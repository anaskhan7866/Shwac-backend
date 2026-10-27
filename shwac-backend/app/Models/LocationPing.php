<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationPing extends Model
{
    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected $casts = ['lat' => 'float', 'lng' => 'float', 'recorded_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
}
