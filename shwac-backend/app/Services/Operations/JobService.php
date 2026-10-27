<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\Complaint;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\Messaging\Notifier;
use App\Services\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobService
{
    public function __construct(private NumberGenerator $numbers, private Notifier $notifier) {}

    public function createFromBooking(Booking $booking, ?User $by = null): ServiceJob
    {
        return DB::transaction(function () use ($booking, $by) {
            $job = ServiceJob::create([
                'number' => $this->numbers->for('job'),
                'branch_id' => $booking->branch_id,
                'customer_id' => $booking->customer_id,
                'customer_address_id' => $booking->customer_address_id,
                'booking_id' => $booking->id,
                'service_id' => $booking->service_id,
                'type' => $booking->form_data['job_type'] ?? 'service',
                'status' => 'pending',
                'scheduled_date' => $booking->scheduled_date,
                'time_slot' => $booking->time_slot,
            ]);
            $this->log($job, null, 'pending', $by, 'Created from booking '.$booking->number);
            if ($booking->status === 'pending') {
                $booking->update(['status' => 'confirmed']);
            }

            return $job;
        });
    }

    public function createFromComplaint(Complaint $complaint, ?User $by = null): ServiceJob
    {
        $addressId = $complaint->customer_address_id ?? $complaint->customer->defaultAddress?->id;
        if (! $addressId) {
            throw ValidationException::withMessages(['customer_address_id' => 'Complaint has no address to send a technician to.']);
        }

        return DB::transaction(function () use ($complaint, $by, $addressId) {
            $job = ServiceJob::create([
                'number' => $this->numbers->for('job'),
                'branch_id' => $complaint->branch_id,
                'customer_id' => $complaint->customer_id,
                'customer_address_id' => $addressId,
                'complaint_id' => $complaint->id,
                'type' => 'repair',
                'status' => 'pending',
                'scheduled_date' => now()->toDateString(),
            ]);
            $this->log($job, null, 'pending', $by, 'Created from complaint '.$complaint->number);

            return $job;
        });
    }

    public function assign(ServiceJob $job, User $technician, ?User $by = null): ServiceJob
    {
        if (! $technician->isTechnician() || ! $technician->is_active) {
            throw ValidationException::withMessages(['technician_id' => 'Selected user is not an active technician.']);
        }
        if ((int) $technician->branch_id !== (int) $job->branch_id) {
            throw ValidationException::withMessages(['technician_id' => 'Technician belongs to another branch.']);
        }
        if (! in_array($job->status, ['pending', 'assigned', 'accepted'], true)) {
            throw ValidationException::withMessages(['status' => "A {$job->status} job cannot be reassigned."]);
        }

        DB::transaction(function () use ($job, $technician, $by) {
            $from = $job->status;
            $job->update(['technician_id' => $technician->id, 'status' => 'assigned', 'assigned_at' => now()]);
            $this->log($job, $from, 'assigned', $by, 'Assigned to '.$technician->name);
            $job->booking?->update(['status' => 'scheduled']);
        });

        $log = $this->notifier->notifyCustomer($job->customer, 'technician_assigned', [
            'job_number' => $job->number,
            'technician_name' => $technician->name,
            'technician_phone' => $technician->phone,
            'scheduled_date' => $job->scheduled_date?->format('d M Y') ?? 'today',
            'time_slot' => $job->time_slot ?? '-',
        ], $job);

        // The technician app checks for new jobs every minute (free). Push via FCM is optional later.

        return $job->refresh()->setAttribute('whatsapp', $log?->share());
    }

    /**
     * Move a job through its state machine. Technicians can only move their own jobs.
     */
    public function transition(ServiceJob $job, string $to, User $by, ?string $note = null, ?float $lat = null, ?float $lng = null): ServiceJob
    {
        if (! $job->canTransitionTo($to)) {
            throw ValidationException::withMessages(['status' => "Cannot move job from {$job->status} to {$to}."]);
        }
        if ($by->isTechnician() && (int) $job->technician_id !== (int) $by->id) {
            abort(403, 'This job is not assigned to you.');
        }

        DB::transaction(function () use ($job, $to, $by, $note, $lat, $lng) {
            $from = $job->status;
            $attrs = ['status' => $to];
            if ($to === 'in_progress' && ! $job->started_at) {
                $attrs['started_at'] = now();
            }
            if ($to === 'completed') {
                $attrs['completed_at'] = now();
                $attrs['work_summary'] = $note ?? $job->work_summary;
            }
            if ($to === 'pending') {
                $attrs['technician_id'] = null;
            }
            $job->update($attrs);
            $this->log($job, $from, $to, $by, $note, $lat, $lng);

            if ($to === 'completed') {
                $job->booking?->update(['status' => 'completed']);
                if ($job->complaint && $job->complaint->isOpen()) {
                    app(ComplaintService::class)->changeStatus($job->complaint, 'resolved', $by, 'Resolved by job '.$job->number.($note ? ': '.$note : ''));
                }
            }
            // Free geocoding: the first time a technician starts work at an address with no
            // coordinates, save their GPS position on it (used for route order and the map).
            if ($to === 'in_progress' && $lat !== null && $lng !== null && $job->address && $job->address->lat === null) {
                $job->address->update(['lat' => $lat, 'lng' => $lng]);
            }
            if ($to === 'in_progress' && $job->complaint && in_array($job->complaint->status, ['open', 'assigned', 'reopened'], true)) {
                app(ComplaintService::class)->changeStatus($job->complaint, 'in_progress', $by, 'Technician started work');
            }
        });

        $job->refresh();
        if ($to === 'en_route') {
            $log = $this->notifier->notifyCustomer($job->customer, 'technician_on_the_way', [
                'technician_name' => $by->name,
                'technician_phone' => $by->phone,
                'job_number' => $job->number,
            ], $job);
            $job->setAttribute('whatsapp', $log?->share());
        }

        return $job;
    }

    private function log(ServiceJob $job, ?string $from, string $to, ?User $by, ?string $note = null, ?float $lat = null, ?float $lng = null): void
    {
        $job->statusLogs()->create([
            'user_id' => $by?->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note ? mb_substr($note, 0, 255) : null,
            'lat' => $lat,
            'lng' => $lng,
            'created_at' => now(),
        ]);
    }
}
