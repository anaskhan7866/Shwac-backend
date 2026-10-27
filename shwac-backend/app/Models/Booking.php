<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $guarded = ['id'];

    use BranchScoped;

    public const STATUSES = ['pending', 'confirmed', 'scheduled', 'completed', 'cancelled'];

    protected $casts = ['form_data' => 'array', 'scheduled_date' => 'date', 'cancelled_at' => 'datetime', 'quoted_amount' => 'decimal:2'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function address() { return $this->belongsTo(CustomerAddress::class, 'customer_address_id'); }
    public function service() { return $this->belongsTo(Service::class); }
    public function customerProduct() { return $this->belongsTo(CustomerProduct::class); }
    public function jobs() { return $this->hasMany(ServiceJob::class); }
}
