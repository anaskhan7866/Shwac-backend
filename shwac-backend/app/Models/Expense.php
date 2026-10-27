<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $guarded = ['id'];

    use BranchScoped;

    public const CATEGORIES = ['fuel', 'travel', 'food', 'parking', 'parts_local', 'other'];

    protected $casts = ['date' => 'date', 'amount' => 'decimal:2', 'reviewed_at' => 'datetime'];

    protected $appends = ['receipt_url'];

    public function user() { return $this->belongsTo(User::class); }
    public function job() { return $this->belongsTo(ServiceJob::class, 'service_job_id'); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function getReceiptUrlAttribute(): ?string
    {
        return $this->receipt_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->receipt_path) : null;
    }
}
