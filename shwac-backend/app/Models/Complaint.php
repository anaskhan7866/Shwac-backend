<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    protected $guarded = ['id'];

    use BranchScoped;

    public const PRIORITIES = ['low', 'normal', 'high', 'critical'];
    public const STATUSES = ['open', 'assigned', 'in_progress', 'resolved', 'closed', 'reopened'];
    public const OPEN_STATUSES = ['open', 'assigned', 'in_progress', 'reopened'];

    protected $casts = [
        'assigned_at' => 'datetime', 'sla_response_due_at' => 'datetime', 'sla_resolution_due_at' => 'datetime',
        'first_response_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime',
        'sla_breached' => 'boolean', 'sla_breach_notified_at' => 'datetime',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function address() { return $this->belongsTo(CustomerAddress::class, 'customer_address_id'); }
    public function customerProduct() { return $this->belongsTo(CustomerProduct::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function updates() { return $this->hasMany(ComplaintUpdate::class)->latest('id'); }
    public function jobs() { return $this->hasMany(ServiceJob::class); }

    public function scopeOpen($q) { return $q->whereIn('status', self::OPEN_STATUSES); }

    public function isOpen(): bool { return in_array($this->status, self::OPEN_STATUSES, true); }

    /** Remaining minutes to resolution SLA (negative when breached). */
    public function slaMinutesLeft(): ?int
    {
        if (! $this->sla_resolution_due_at || ! $this->isOpen()) {
            return null;
        }
        return (int) now()->diffInMinutes($this->sla_resolution_due_at, false);
    }
}
