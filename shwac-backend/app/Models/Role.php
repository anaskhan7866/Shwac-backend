<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_system' => 'boolean'];

    public function permissions() { return $this->belongsToMany(Permission::class); }
    public function users() { return $this->belongsToMany(User::class); }
}
