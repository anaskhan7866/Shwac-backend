<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobAttachment extends Model
{
    protected $guarded = ['id'];

    protected $appends = ['url'];

    public function job() { return $this->belongsTo(ServiceJob::class, 'service_job_id'); }

    public function getUrlAttribute(): string
    {
        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->path);
    }
}
