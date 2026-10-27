<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
}
