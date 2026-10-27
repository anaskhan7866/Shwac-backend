<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected $casts = ['quantity' => 'decimal:2', 'unit_cost' => 'decimal:2'];

    public function product() { return $this->belongsTo(Product::class); }
    public function fromWarehouse() { return $this->belongsTo(Warehouse::class, 'from_warehouse_id'); }
    public function toWarehouse() { return $this->belongsTo(Warehouse::class, 'to_warehouse_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function reference() { return $this->morphTo(); }
}
