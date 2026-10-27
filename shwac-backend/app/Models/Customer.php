<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = ['id'];

    use BranchScoped, SoftDeletes;

    protected $casts = ['whatsapp_opt_in' => 'boolean'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function addresses() { return $this->hasMany(CustomerAddress::class); }
    public function products() { return $this->hasMany(CustomerProduct::class); }
    public function bookings() { return $this->hasMany(Booking::class); }
    public function complaints() { return $this->hasMany(Complaint::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }

    public function defaultAddress()
    {
        return $this->hasOne(CustomerAddress::class)->orderByDesc('is_default')->oldest('id');
    }

    /** Number used for WhatsApp, in E.164 without '+', e.g. 919876543210 */
    public function whatsappNumber(): string
    {
        $n = preg_replace('/\D/', '', $this->whatsapp ?: $this->phone);
        return strlen($n) === 10 ? '91'.$n : $n;
    }
}
