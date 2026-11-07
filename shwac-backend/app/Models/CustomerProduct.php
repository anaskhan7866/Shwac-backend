<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerProduct extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['purchase_date' => 'date', 'installed_on' => 'date', 'warranty_until' => 'date', 'details' => 'array'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function service() { return $this->belongsTo(Service::class); }
    public function address() { return $this->belongsTo(CustomerAddress::class, 'customer_address_id'); }}
