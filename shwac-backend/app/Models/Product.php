<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'tax_rate' => 'decimal:2', 'mrp' => 'decimal:2', 'sale_price' => 'decimal:2', 'purchase_price' => 'decimal:2',
        'reorder_level' => 'decimal:2', 'van_min_level' => 'decimal:2', 'is_active' => 'boolean',
    ];

    public function stockLevels() { return $this->hasMany(StockLevel::class); }
}
