<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplaintUpdate extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_internal' => 'boolean', 'meta' => 'array'];

    public function complaint() { return $this->belongsTo(Complaint::class); }
    public function user() { return $this->belongsTo(User::class); }
}
