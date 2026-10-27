<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = ['id'];

    use BranchScoped;

    protected $casts = [
        'invoice_date' => 'date', 'due_date' => 'date', 'billing_address' => 'array',
        'issued_at' => 'datetime', 'cancelled_at' => 'datetime',
        'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'taxable_value' => 'decimal:2',
        'cgst_total' => 'decimal:2', 'sgst_total' => 'decimal:2', 'igst_total' => 'decimal:2',
        'round_off' => 'decimal:2', 'total' => 'decimal:2', 'amount_paid' => 'decimal:2', 'balance_due' => 'decimal:2',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function job() { return $this->belongsTo(ServiceJob::class, 'service_job_id'); }
    public function stockWarehouse() { return $this->belongsTo(Warehouse::class, 'stock_warehouse_id'); }
    public function items() { return $this->hasMany(InvoiceItem::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    public function paymentLinks() { return $this->hasMany(PaymentLink::class); }

    /** Public page (no login) with the GST invoice, UPI QR and "I have paid" form. */
    public function publicUrl(): ?string
    {
        return $this->public_token ? url('/i/'.$this->public_token) : null;
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['issued', 'partially_paid'], true) && (float) $this->balance_due > 0;
    }
}
