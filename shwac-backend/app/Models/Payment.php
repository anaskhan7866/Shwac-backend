<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = ['id'];

    use BranchScoped;

    public const METHODS = ['cash', 'upi', 'card', 'razorpay', 'bank_transfer', 'cheque'];

    protected $casts = ['amount' => 'decimal:2', 'gateway_payload' => 'array', 'paid_at' => 'datetime'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
}
