<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Billing\GstCalculator;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    // ---- Products ----------------------------------------------------------

    public function products(Request $request): JsonResponse
    {
        $q = Product::query()->withSum('stockLevels as total_stock', 'quantity');
        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', $s)->orWhere('barcode', $s));
        }
        if ($request->filled('category')) {
            $q->where('category', $request->input('category'));
        }
        if ($request->has('active')) {
            $q->where('is_active', $request->boolean('active'));
        }

        return response()->json($q->orderBy('name')->paginate($this->perPage($request)));
    }

    public function storeProduct(Request $request): JsonResponse
    {
        return response()->json(Product::create($request->validate($this->productRules())), 201);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $product->update($request->validate($this->productRules($product->id, true)));

        return response()->json($product);
    }

    /** Barcode / QR / SKU scan → product with stock per visible warehouse. */
    public function lookup(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:64']])['code'];
        $product = Product::where('barcode', $code)->orWhere('sku', $code)->firstOrFail();
        $warehouses = Warehouse::query()->visibleTo($request->user())->pluck('id');

        return response()->json($product->load(['stockLevels' => fn ($q) => $q->whereIn('warehouse_id', $warehouses)->with('warehouse:id,name,type')]));
    }

    // ---- Warehouses & stock -------------------------------------------------

    public function warehouses(Request $request): JsonResponse
    {
        return response()->json(
            Warehouse::query()->visibleTo($request->user())->with('owner:id,name')
                ->withSum('stockLevels as total_units', 'quantity')
                ->orderBy('type')->orderBy('name')->get()
        );
    }

    public function storeWarehouse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'alpha_dash', 'max:20', 'unique:warehouses,code'],
        ]);
        abort_unless($request->user()->canSeeAllBranches() || (int) $data['branch_id'] === (int) $request->user()->branch_id, 403);

        return response()->json(Warehouse::create($data + ['type' => 'main']), 201);
    }

    /** Create (or fetch) the van warehouse for a technician. */
    public function technicianVan(Request $request, User $user): JsonResponse
    {
        abort_unless($user->isTechnician(), 422, 'Vans belong to technicians.');
        abort_unless($request->user()->canSeeAllBranches() || $user->branch_id === $request->user()->branch_id, 404);

        return response()->json($this->inventory->vanFor($user));
    }

    public function stock(Request $request): JsonResponse
    {
        $request->validate(['warehouse_id' => ['nullable', 'exists:warehouses,id']]);
        $visible = Warehouse::query()->visibleTo($request->user())->pluck('id');

        $q = StockLevel::query()->whereIn('warehouse_id', $visible)
            ->with(['product:id,sku,name,unit,reorder_level,van_min_level,sale_price,barcode', 'warehouse:id,name,type,code']);
        if ($request->filled('warehouse_id')) {
            $q->where('warehouse_id', $request->integer('warehouse_id'));
        }
        if ($request->boolean('low')) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('stock_alerts')
                ->whereColumn('stock_alerts.product_id', 'stock_levels.product_id')
                ->whereColumn('stock_alerts.warehouse_id', 'stock_levels.warehouse_id')
                ->where('stock_alerts.status', 'open'));
        }

        return response()->json($q->orderBy('warehouse_id')->paginate($this->perPage($request)));
    }

    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'], // supplier bill no.
        ]);
        $wh = $this->visibleWarehouse($request, $data['warehouse_id']);

        $moves = \DB::transaction(fn () => collect($data['items'])->map(fn ($i) => $this->inventory->receive(
            $wh, Product::findOrFail($i['product_id']), $i['quantity'], $request->user(), $i['unit_cost'] ?? null, $data['note'] ?? null,
        )));

        return response()->json($moves, 201);
    }

    /** Main warehouse → van (issue to technician) or van → main (return). */
    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'exists:warehouses,id'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $from = $this->visibleWarehouse($request, $data['from_warehouse_id']);
        $to = $this->visibleWarehouse($request, $data['to_warehouse_id']);

        $moves = \DB::transaction(fn () => collect($data['items'])->map(fn ($i) => $this->inventory->transfer(
            $from, $to, Product::findOrFail($i['product_id']), $i['quantity'], $request->user(), $data['note'] ?? null,
        )));

        return response()->json($moves, 201);
    }

    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'product_id' => ['required', 'exists:products,id'],
            'counted_quantity' => ['required', 'numeric', 'min:0'],
            'note' => ['required', 'string', 'max:255'],
        ]);
        $wh = $this->visibleWarehouse($request, $data['warehouse_id']);
        $move = $this->inventory->adjust($wh, Product::findOrFail($data['product_id']), $data['counted_quantity'], $request->user(), $data['note']);

        return response()->json(['movement' => $move, 'quantity' => $this->inventory->quantity(Product::find($data['product_id']), $wh)]);
    }

    public function movements(Request $request): JsonResponse
    {
        $visible = Warehouse::query()->visibleTo($request->user())->pluck('id');
        $q = StockMovement::query()
            ->where(fn ($w) => $w->whereIn('from_warehouse_id', $visible)->orWhereIn('to_warehouse_id', $visible))
            ->with(['product:id,sku,name', 'fromWarehouse:id,name', 'toWarehouse:id,name', 'user:id,name']);
        foreach (['product_id', 'reason'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->input($f));
            }
        }
        if ($request->filled('warehouse_id')) {
            $w = $request->integer('warehouse_id');
            $q->where(fn ($x) => $x->where('from_warehouse_id', $w)->orWhere('to_warehouse_id', $w));
        }

        return response()->json($q->latest('id')->paginate($this->perPage($request)));
    }

    public function alerts(Request $request): JsonResponse
    {
        $visible = Warehouse::query()->visibleTo($request->user())->pluck('id');

        return response()->json(
            StockAlert::where('status', $request->input('status', 'open'))->whereIn('warehouse_id', $visible)
                ->with(['product:id,sku,name,unit', 'warehouse:id,name,type'])->latest('id')->get()
        );
    }

    private function visibleWarehouse(Request $request, int $id): Warehouse
    {
        $wh = Warehouse::findOrFail($id);
        $this->ensureVisible($wh, $request->user());
        abort_unless($wh->is_active, 422, 'Warehouse is inactive.');

        return $wh;
    }

    private function productRules(?int $id = null, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return [
            'sku' => [$r, 'alpha_dash', 'max:30', Rule::unique('products', 'sku')->ignore($id)],
            'name' => [$r, 'string', 'max:160'],
            'category' => ['nullable', 'string', 'max:60'],
            'brand' => ['nullable', 'string', 'max:60'],
            'hsn' => ['nullable', 'digits_between:4,8'],
            'unit' => ['sometimes', 'string', 'max:10'],
            'tax_rate' => ['sometimes', Rule::in(GstCalculator::ALLOWED_RATES)],
            'mrp' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => [$r, 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products', 'barcode')->ignore($id)],
            'reorder_level' => ['sometimes', 'numeric', 'min:0'],
            'van_min_level' => ['sometimes', 'numeric', 'min:0'],
            'warranty_months' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
