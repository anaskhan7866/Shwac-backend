<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class ServiceJob extends Model
{
    protected $guarded = ['id'];

    use BranchScoped;

    public const TYPES = ['installation', 'service', 'repair', 'amc_visit', 'inspection'];
    public const STATUSES = ['pending', 'assigned', 'accepted', 'en_route', 'in_progress', 'completed', 'cancelled'];
    public const ACTIVE_STATUSES = ['assigned', 'accepted', 'en_route', 'in_progress'];

    /** Allowed transitions for the job state machine. */
    public const TRANSITIONS = [
        'pending' => ['assigned', 'cancelled'],
        'assigned' => ['accepted', 'assigned', 'pending', 'cancelled'],
        'accepted' => ['en_route', 'in_progress', 'assigned', 'cancelled'],
        'en_route' => ['in_progress', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    protected $casts = [
        'scheduled_date' => 'date', 'assigned_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function address() { return $this->belongsTo(CustomerAddress::class, 'customer_address_id'); }
    public function booking() { return $this->belongsTo(Booking::class); }
    public function complaint() { return $this->belongsTo(Complaint::class); }
    public function service() { return $this->belongsTo(Service::class); }
    public function technician() { return $this->belongsTo(User::class, 'technician_id'); }
    public function statusLogs() { return $this->hasMany(JobStatusLog::class)->oldest('id'); }
    public function attachments() { return $this->hasMany(JobAttachment::class); }
    public function invoice() { return $this->hasOne(Invoice::class)->where('status', '!=', 'cancelled'); }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
