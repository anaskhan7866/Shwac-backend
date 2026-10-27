<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobStatusLog extends Model
{
    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected $casts = ['lat' => 'float', 'lng' => 'float'];

    public function job() { return $this->belongsTo(ServiceJob::class, 'service_job_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
