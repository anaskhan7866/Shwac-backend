<?php

use App\Models\StockAlert;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Switched-off stores (vans of removed logins, shown as "Van (removed user)") are no longer listed.
 * Any parts still in them go back to the main store with a normal logged transfer, so no stock
 * is lost, and their low-stock alerts are closed. The main store loses the branch-style "HQ" name.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $inventory = app(InventoryService::class);

            Warehouse::where('is_active', false)->get()->each(function (Warehouse $old) use ($inventory) {
                $main = $inventory->mainWarehouseFor((int) $old->branch_id);
                if ($main && ! $main->is($old)) {
                    StockLevel::where('warehouse_id', $old->id)->where('quantity', '>', 0)->with('product')->get()
                        ->each(fn (StockLevel $level) => $inventory->transfer($old, $main, $level->product, $level->quantity,
                            null, 'Returned from a switched-off store'));
                }
                StockAlert::where('warehouse_id', $old->id)->where('status', 'open')
                    ->update(['status' => 'resolved', 'resolved_at' => now()]);
            });

            Warehouse::where('name', 'HQ Main Store')->update(['name' => 'Main Store']);
        });
    }

    public function down(): void
    {
        // Stock moves are logged in stock_movements; nothing to undo automatically.
    }
};
