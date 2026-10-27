<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date', 'check_in_at' => 'datetime', 'check_out_at' => 'datetime',
        'check_in_lat' => 'float', 'check_in_lng' => 'float', 'check_out_lat' => 'float', 'check_out_lng' => 'float',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
