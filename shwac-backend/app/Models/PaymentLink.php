<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentLink extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2', 'expires_at' => 'datetime'];

    public function invoice() { return $this->belongsTo(Invoice::class); }
}
