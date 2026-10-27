<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAlert extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:2', 'threshold' => 'decimal:2', 'resolved_at' => 'datetime'];

    public function product() { return $this->belongsTo(Product::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
}
