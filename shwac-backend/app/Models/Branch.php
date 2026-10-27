<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function users() { return $this->hasMany(User::class); }
    public function customers() { return $this->hasMany(Customer::class); }
}
