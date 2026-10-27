<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_default' => 'boolean', 'lat' => 'float', 'lng' => 'float'];

    public function customer() { return $this->belongsTo(Customer::class); }

    public function toSnapshot(): array
    {
        return $this->only(['line1', 'line2', 'landmark', 'city', 'district', 'state', 'state_code', 'pincode']);
    }
}
