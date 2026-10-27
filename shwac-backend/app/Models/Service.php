<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['form_schema' => 'array', 'is_active' => 'boolean', 'base_price' => 'decimal:2', 'tax_rate' => 'decimal:2'];

    public function category() { return $this->belongsTo(ServiceCategory::class, 'service_category_id'); }
}
