<?php

namespace App\Services\Inventory;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every stock change goes through here so `stock_levels` and the append-only
 * `stock_movements` ledger always agree. Quantities are handled in hundredths
 * (integers) to avoid float drift; stock rows are locked for the duration.
 *
 *   purchase     → into a warehouse (goods received)
 *   transfer     → main warehouse ↔ technician van
 *   consumed     → used on an issued invoice (out of the invoice's stock warehouse)
 *   returned     → back into stock when an invoice is cancelled
 *   adjustment   → stock count correction (+/-)
 */
class InventoryService
{
    public function receive(Warehouse $to, Product $product, string|float $qty, ?User $by = null, string|float|null $unitCost = null, ?string $note = null): StockMovement
    {
        $q = $this->positive($qty);

        return DB::transaction(function () use ($to, $product, $q, $by, $unitCost, $note) {
            $this->change($product, $to, $q);

            return $this->log($product, null, $to, $q, 'purchase', $by, null, $note, $unitCost ?? $product->purchase_price);
        });
    }

    public function transfer(Warehouse $from, Warehouse $to, Product $product, string|float $qty, ?User $by = null, ?string $note = null): StockMovement
    {
        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_warehouse_id' => 'Source and destination are the same.']);
        }
        if ((int) $from->branch_id !== (int) $to->branch_id) {
            throw ValidationException::withMessages(['to_warehouse_id' => 'Transfers between branches are not allowed here.']);
        }
        $q = $this->positive($qty);

        return DB::transaction(function () use ($from, $to, $product, $q, $by, $note) {
            $this->change($product, $from, -$q);
            $this->change($product, $to, $q);

            return $this->log($product, $from, $to, $q, 'transfer', $by, null, $note);
        });
    }

    /** Set the counted quantity; logs the difference. */
    public function adjust(Warehouse $warehouse, Product $product, string|float $countedQty, User $by, string $note): ?StockMovement
    {
        $counted = Money::toPaise($countedQty);
        if ($counted < 0) {
            throw ValidationException::withMessages(['quantity' => 'Counted quantity cannot be negative.']);
        }

        return DB::transaction(function () use ($warehouse, $product, $counted, $by, $note) {
            $current = Money::toPaise($this->lockedLevel($product, $warehouse)->quantity);
            $diff = $counted - $current;
            if ($diff === 0) {
                return null;
            }
            $this->change($product, $warehouse, $diff, allowNegative: false);

            return $diff > 0
                ? $this->log($product, null, $warehouse, $diff, 'adjustment', $by, null, $note)
                : $this->log($product, $warehouse, null, -$diff, 'adjustment', $by, null, $note);
        });
    }

    /**
     * Take the parts on an invoice out of its stock warehouse. Called inside the
     * invoice-issue transaction, so a shortage rolls back the whole issue.
     */
    public function consumeForInvoice(Invoice $invoice, ?User $by = null): void
    {
        $lines = $this->partLines($invoice);
        if ($lines === []) {
            return;
        }
        $warehouse = $invoice->stockWarehouse;
        if (! $warehouse) {
            throw ValidationException::withMessages(['stock_warehouse_id' => 'Choose the warehouse the spare parts come from.']);
        }

        foreach ($lines as $productId => $q) {
            $product = Product::findOrFail($productId);
            $this->change($product, $warehouse, -$q);
            $this->log($product, $warehouse, null, $q, 'consumed', $by, $invoice);
        }
    }

    /** Put parts back when an issued invoice is cancelled. */
    public function returnForInvoice(Invoice $invoice, ?User $by = null): void
    {
        $consumed = StockMovement::query()
            ->where('reference_type', $invoice->getMorphClass())->where('reference_id', $invoice->id)
            ->where('reason', 'consumed')->get();

        foreach ($consumed as $m) {
            $q = Money::toPaise($m->quantity);
            $this->change($m->product, $m->fromWarehouse, $q);
            $this->log($m->product, null, $m->fromWarehouse, $q, 'returned', $by, $invoice, 'Invoice cancelled');
        }
    }

    public function quantity(Product $product, Warehouse $warehouse): string
    {
        return (string) (StockLevel::where(['product_id' => $product->id, 'warehouse_id' => $warehouse->id])->value('quantity') ?? '0.00');
    }

    /** Technician's van, created on first use. */
    public function vanFor(User $technician): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['user_id' => $technician->id, 'type' => 'van'],
            [
                'branch_id' => $technician->branch_id,
                'name' => 'Van – '.$technician->name,
                'code' => 'VAN-'.$technician->id,
            ],
        );
    }

    public function mainWarehouseFor(int $branchId): ?Warehouse
    {
        return Warehouse::where('branch_id', $branchId)->where('type', 'main')->where('is_active', true)->orderBy('id')->first();
    }

    // ---------------------------------------------------------------------

    /** @return array<int,int> product_id => hundredths */
    private function partLines(Invoice $invoice): array
    {
        $out = [];
        foreach ($invoice->items()->where('item_type', 'part')->whereNotNull('item_id')->get() as $item) {
            $out[$item->item_id] = ($out[$item->item_id] ?? 0) + Money::toPaise($item->quantity);
        }

        return $out;
    }

    private function lockedLevel(Product $product, Warehouse $warehouse): StockLevel
    {
        $level = StockLevel::where(['product_id' => $product->id, 'warehouse_id' => $warehouse->id])->lockForUpdate()->first();

        return $level ?? StockLevel::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 0]);
    }

    /** @param int $delta hundredths */
    private function change(Product $product, Warehouse $warehouse, int $delta, bool $allowNegative = false): void
    {
        $level = $this->lockedLevel($product, $warehouse);
        $new = Money::toPaise($level->quantity) + $delta;
        if ($new < 0 && ! $allowNegative) {
            throw ValidationException::withMessages([
                'stock' => sprintf('Not enough %s in %s (have %s, need %s).', $product->name, $warehouse->name,
                    rtrim(rtrim((string) $level->quantity, '0'), '.') ?: '0', rtrim(rtrim(Money::fromPaise(-$delta), '0'), '.')),
            ]);
        }
        $level->update(['quantity' => Money::fromPaise($new)]);
        $this->checkThreshold($product, $warehouse, $new);
    }

    private function checkThreshold(Product $product, Warehouse $warehouse, int $newQty): void
    {
        $threshold = Money::toPaise($warehouse->isVan() ? $product->van_min_level : $product->reorder_level);
        $open = StockAlert::where(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'status' => 'open'])->first();

        if ($threshold > 0 && $newQty <= $threshold) {
            if ($open) {
                $open->update(['quantity' => Money::fromPaise($newQty)]);
            } else {
                StockAlert::create([
                    'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
                    'quantity' => Money::fromPaise($newQty), 'threshold' => Money::fromPaise($threshold),
                ]);
            }
        } elseif ($open) {
            $open->update(['status' => 'resolved', 'resolved_at' => now(), 'quantity' => Money::fromPaise($newQty)]);
        }
    }

    private function log(Product $p, ?Warehouse $from, ?Warehouse $to, int $q, string $reason, ?User $by, ?Model $ref = null, ?string $note = null, string|float|null $unitCost = null): StockMovement
    {
        return StockMovement::create([
            'product_id' => $p->id,
            'from_warehouse_id' => $from?->id,
            'to_warehouse_id' => $to?->id,
            'quantity' => Money::fromPaise($q),
            'reason' => $reason,
            'reference_type' => $ref?->getMorphClass(),
            'reference_id' => $ref?->getKey(),
            'unit_cost' => $unitCost,
            'user_id' => $by?->id,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function positive(string|float $qty): int
    {
        $q = Money::toPaise($qty);
        if ($q <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be greater than zero.']);
        }

        return $q;
    }
}
