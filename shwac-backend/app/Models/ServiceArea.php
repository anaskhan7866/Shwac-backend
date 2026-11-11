<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A PIN code the company serves and the services offered there (managed in the admin "Service areas" page). */
class ServiceArea extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['all_services' => 'boolean', 'is_active' => 'boolean'];

    public function services() { return $this->belongsToMany(Service::class, 'service_area_service'); }

    public static function forPincode(?string $pincode): ?self
    {
        $pincode = trim((string) $pincode);

        return preg_match('/^\d{6}$/', $pincode) ? static::where('pincode', $pincode)->where('is_active', true)->first() : null;
    }

    public function offers(Service $service): bool
    {
        return $this->all_services || $this->services()->whereKey($service->id)->exists();
    }
}
