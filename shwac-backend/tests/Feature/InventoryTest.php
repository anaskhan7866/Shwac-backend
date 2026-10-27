<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\ServiceJob;
use App\Models\StockAlert;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    private Warehouse $store;
    private Product $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Main Store', 'code' => 'HQ-MAIN', 'type' => 'main']);
        $this->filter = Product::create([
            'sku' => 'SED-10', 'name' => 'Sediment filter', 'hsn' => '84212190', 'sale_price' => 250, 'tax_rate' => 18,
            'barcode' => '8901030865071', 'reorder_level' => 5, 'van_min_level' => 2,
        ]);
    }

    private function qty(Warehouse $w, ?Product $p = null): string
    {
        return app(InventoryService::class)->quantity($p ?? $this->filter, $w);
    }

    #[Test]
    public function receive_transfer_to_van_and_adjust_keep_ledger_consistent(): void
    {
        $tech = $this->technician();
        $this->actingAsUser($this->staff('branch_manager'));

        $this->postJson('/api/v1/admin/stock/receive', ['warehouse_id' => $this->store->id, 'note' => 'Bill #A-112',
            'items' => [['product_id' => $this->filter->id, 'quantity' => 20, 'unit_cost' => 120]]])->assertCreated();

        $van = $this->postJson("/api/v1/admin/technicians/{$tech->id}/van")->assertOk()->json();
        $this->postJson('/api/v1/admin/stock/transfer', ['from_warehouse_id' => $this->store->id, 'to_warehouse_id' => $van['id'],
            'items' => [['product_id' => $this->filter->id, 'quantity' => 6]]])->assertCreated();

        // can't transfer more than is in stock
        $this->postJson('/api/v1/admin/stock/transfer', ['from_warehouse_id' => $this->store->id, 'to_warehouse_id' => $van['id'],
            'items' => [['product_id' => $this->filter->id, 'quantity' => 15]]])->assertStatus(422)->assertJsonValidationErrors('stock');

        $this->assertSame('14.00', $this->qty($this->store));
        $this->assertSame('6.00', $this->qty(Warehouse::find($van['id'])));

        // stock count found 13 → adjustment of −1 logged
        $this->postJson('/api/v1/admin/stock/adjust', ['warehouse_id' => $this->store->id, 'product_id' => $this->filter->id,
            'counted_quantity' => 13, 'note' => 'Monthly count'])->assertOk()->assertJsonPath('quantity', '13.00');

        $this->assertSame(['purchase', 'transfer', 'adjustment'], StockMovement::orderBy('id')->pluck('reason')->all());
        $this->getJson('/api/v1/admin/products/lookup?code=8901030865071')->assertOk()->assertJsonPath('sku', 'SED-10')->assertJsonCount(2, 'stock_levels');
    }

    #[Test]
    public function technician_bills_parts_from_van_stock_and_low_stock_alert_fires(): void
    {
        $this->fakeExternal();
        $tech = $this->technician();
        $inv = app(InventoryService::class);
        $inv->receive($this->store, $this->filter, 10);
        $van = $inv->vanFor($tech);
        $inv->transfer($this->store, $van, $this->filter, 3);

        $customer = $this->customer();
        $job = ServiceJob::create(['number' => 'JOB-T1', 'branch_id' => $this->branch->id, 'customer_id' => $customer->id,
            'customer_address_id' => $customer->addresses()->first()->id, 'technician_id' => $tech->id, 'status' => 'in_progress',
            'service_id' => $this->service()->id]);

        $this->actingAsUser($tech);
        $this->getJson('/api/v1/technician/parts/lookup?code=8901030865071')->assertOk()->assertJsonPath('in_van', '3.00');

        // not enough in the van → nothing is issued, no invoice number consumed
        $this->postJson("/api/v1/technician/jobs/{$job->id}/invoice", ['items' => [
            ['service_id' => $job->service_id, 'quantity' => 1],
            ['product_id' => $this->filter->id, 'quantity' => 4],
        ]])->assertStatus(422)->assertJsonValidationErrors('stock');
        $this->assertSame(0, Invoice::count());

        // technicians can't override catalog prices
        $res = $this->postJson("/api/v1/technician/jobs/{$job->id}/invoice", ['items' => [
            ['service_id' => $job->service_id, 'quantity' => 1],
            ['product_id' => $this->filter->id, 'quantity' => 2, 'unit_price' => 1],
        ]])->assertCreated()->assertJsonPath('number', 'SHW/2627/00001')->assertJsonPath('taxable_value', '999.00')->json();

        $this->assertSame('1.00', $this->qty($van));
        $alert = StockAlert::where('warehouse_id', $van->id)->first();
        $this->assertNotNull($alert);
        $this->assertSame('open', $alert->status);

        // Cancelling the invoice puts the parts back into the van and clears the alert
        $this->actingAsUser($this->staff('accountant'));
        $this->postJson("/api/v1/admin/invoices/{$res['id']}/cancel", ['reason' => 'Customer refused'])->assertOk();
        $this->assertSame('3.00', $this->qty($van));
        $this->assertSame('resolved', $alert->fresh()->status);
        $this->assertSame(1, StockMovement::where('reason', 'returned')->count());
    }

    #[Test]
    public function office_invoices_take_parts_from_the_branch_main_store(): void
    {
        $this->fakeExternal();
        app(InventoryService::class)->receive($this->store, $this->filter, 8);
        $this->actingAsUser($this->staff('accountant'));

        $this->postJson('/api/v1/admin/invoices', ['customer_id' => $this->customer()->id, 'issue' => true,
            'items' => [['product_id' => $this->filter->id, 'quantity' => 4]]])
            ->assertCreated()->assertJsonPath('stock_warehouse_id', $this->store->id);

        $this->assertSame('4.00', $this->qty($this->store));
        $this->getJson('/api/v1/admin/stock/alerts')->assertOk()->assertJsonCount(1)->assertJsonPath('0.product.sku', 'SED-10');
        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('inventory.low_stock_alerts', 1);
    }

    #[Test]
    public function operators_can_view_but_not_move_stock(): void
    {
        $this->actingAsUser($this->staff('operator'));
        $this->getJson('/api/v1/admin/stock')->assertOk();
        $this->postJson('/api/v1/admin/stock/receive', ['warehouse_id' => $this->store->id, 'items' => [['product_id' => $this->filter->id, 'quantity' => 1]]])
            ->assertForbidden();
    }
}
