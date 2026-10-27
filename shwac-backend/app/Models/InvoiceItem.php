<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'discount' => 'decimal:2', 'taxable_value' => 'decimal:2',
        'tax_rate' => 'decimal:2', 'cgst_rate' => 'decimal:2', 'cgst_amount' => 'decimal:2', 'sgst_rate' => 'decimal:2',
        'sgst_amount' => 'decimal:2', 'igst_rate' => 'decimal:2', 'igst_amount' => 'decimal:2', 'total' => 'decimal:2',
    ];

    public function invoice() { return $this->belongsTo(Invoice::class); }
}
