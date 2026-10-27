<?php

namespace App\Services\Operations;

use App\Models\Complaint;
use App\Models\Customer;
use App\Models\ServiceJob;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Services\Messaging\Notifier;
use App\Services\NumberGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComplaintService
{
    public const TRANSITIONS = [
        'open' => ['assigned', 'in_progress', 'resolved', 'closed'],
        'assigned' => ['in_progress', 'resolved', 'closed', 'assigned'],
        'in_progress' => ['resolved', 'closed'],
        'resolved' => ['closed', 'reopened'],
        'closed' => ['reopened'],
        'reopened' => ['assigned', 'in_progress', 'resolved', 'closed'],
    ];

    /** Fallback when no sla_policies row exists for a priority (minutes). */
    private const DEFAULT_SLA = [
        'critical' => [30, 240], 'high' => [60, 480], 'normal' => [120, 1440], 'low' => [240, 2880],
    ];

    public function __construct(
        private NumberGenerator $numbers,
        private JobService $jobs,
        private Notifier $notifier,
    ) {}

    /**
     * Register a complaint, start SLA timers and (if enabled) auto-assign a technician.
     */
    public function create(Customer $customer, array $data, ?User $by = null, bool $autoAssign = true): Complaint
    {
        $priority = $data['priority'] ?? 'normal';
        [$respond, $resolve] = $this->slaFor($priority);

        $complaint = DB::transaction(function () use ($customer, $data, $by, $priority, $respond, $resolve) {
            $c = Complaint::create([
                'number' => $this->numbers->for('complaint'),
                'branch_id' => $customer->branch_id,
                'customer_id' => $customer->id,
                'customer_address_id' => $data['customer_address_id'] ?? $customer->defaultAddress?->id,
                'customer_product_id' => $data['customer_product_id'] ?? null,
                'category' => $data['category'] ?? 'general',
                'priority' => $priority,
                'channel' => $data['channel'] ?? 'web',
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'status' => 'open',
                'sla_response_due_at' => now()->addMinutes($respond),
                'sla_resolution_due_at' => now()->addMinutes($resolve),
            ]);
            $c->updates()->create(['user_id' => $by?->id, 'type' => 'system', 'body' => 'Complaint registered via '.$c->channel.'.']);

            return $c;
        });

        if ($autoAssign && \App\Models\Setting::get('complaints.auto_assign', true)) {
            $tech = $this->pickTechnician($complaint);
            if ($tech) {
                $this->assign($complaint, $tech, null, 'Auto-assigned (lowest workload in branch)');
            }
        }

        $complaint->refresh();
        $log = $this->notifier->notifyCustomer($customer, 'complaint_registered', [
            'complaint_number' => $complaint->number,
            'subject' => $complaint->subject,
            'expected_by' => $complaint->sla_resolution_due_at->format('d M, h:i A'),
            'technician_name' => $complaint->assignee?->name ?? 'our technician',
        ], $complaint);

        return $complaint->setAttribute('whatsapp', $log?->share());
    }

    /**
     * Assign to a technician: creates (or reassigns) the repair job and records first response.
     */
    public function assign(Complaint $complaint, User $technician, ?User $by = null, ?string $note = null): Complaint
    {
        if (! $complaint->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Only open complaints can be assigned.']);
        }

        DB::transaction(function () use ($complaint, $technician, $by, $note) {
            $job = $complaint->jobs()->whereNotIn('status', ['completed', 'cancelled'])->latest('id')->first()
                ?? $this->jobs->createFromComplaint($complaint, $by);
            $this->jobs->assign($job, $technician, $by);

            $complaint->update([
                'assigned_to' => $technician->id,
                'assigned_at' => now(),
                'status' => in_array($complaint->status, ['open', 'reopened', 'assigned'], true) ? 'assigned' : $complaint->status,
                'first_response_at' => $complaint->first_response_at ?? now(),
            ]);
            $complaint->updates()->create([
                'user_id' => $by?->id, 'type' => 'assignment',
                'body' => $note ?? 'Assigned to '.$technician->name,
                'meta' => ['technician_id' => $technician->id, 'job_id' => $job->id],
            ]);
        });

        return $complaint->refresh();
    }

    public function changeStatus(Complaint $complaint, string $to, ?User $by, ?string $note = null): Complaint
    {
        if (! in_array($to, self::TRANSITIONS[$complaint->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "Cannot move complaint from {$complaint->status} to {$to}."]);
        }

        DB::transaction(function () use ($complaint, $to, $by, $note) {
            $attrs = ['status' => $to, 'first_response_at' => $complaint->first_response_at ?? now()];
            if ($to === 'resolved') {
                $attrs['resolved_at'] = now();
                $attrs['resolution_notes'] = $note;
            }
            if ($to === 'closed') {
                $attrs['closed_at'] = now();
                $attrs['resolved_at'] = $complaint->resolved_at ?? now();
            }
            if ($to === 'reopened') {
                $attrs += ['resolved_at' => null, 'closed_at' => null];
                // fresh resolution window on reopen
                [, $resolve] = $this->slaFor($complaint->priority);
                $attrs['sla_resolution_due_at'] = now()->addMinutes($resolve);
            }
            $complaint->update($attrs);
            $complaint->updates()->create([
                'user_id' => $by?->id, 'type' => 'status',
                'body' => 'Status → '.str_replace('_', ' ', $to).($note ? ': '.$note : ''),
            ]);
        });

        return $complaint->refresh();
    }

    public function comment(Complaint $complaint, User $by, string $body, bool $internal = false): Complaint
    {
        $complaint->updates()->create(['user_id' => $by->id, 'type' => 'comment', 'body' => $body, 'is_internal' => $internal]);
        if (! $internal && $by->isStaff() && ! $complaint->first_response_at) {
            $complaint->update(['first_response_at' => now()]);
        }

        return $complaint;
    }

    /**
     * Least-loaded active technician in the complaint's branch.
     * Load = active jobs (assigned → in progress). Ties → fewer jobs today.
     */
    public function pickTechnician(Complaint $complaint): ?User
    {
        /** @var Collection<int,User> $techs */
        $techs = User::query()
            ->where('user_type', User::TYPE_TECHNICIAN)
            ->where('is_active', true)
            ->where('branch_id', $complaint->branch_id)
            ->withCount([
                'assignedJobs as active_jobs' => fn ($q) => $q->whereIn('status', ServiceJob::ACTIVE_STATUSES),
                'assignedJobs as today_jobs' => fn ($q) => $q->whereDate('scheduled_date', today()),
            ])
            ->get();

        return $techs->sortBy([['active_jobs', 'asc'], ['today_jobs', 'asc'], ['id', 'asc']])->first();
    }

    /**
     * Flag complaints that missed response or resolution SLA. Returns the newly breached ones.
     *
     * @return Collection<int, Complaint>
     */
    public function scanSlaBreaches(): Collection
    {
        $now = now();
        $breached = Complaint::query()
            ->open()
            ->where('sla_breached', false)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('first_response_at')->where('sla_response_due_at', '<', $now))
                ->orWhere('sla_resolution_due_at', '<', $now))
            ->get();

        foreach ($breached as $c) {
            $c->update(['sla_breached' => true, 'sla_breach_notified_at' => $now]);
            $c->updates()->create([
                'type' => 'system', 'is_internal' => true,
                'body' => $c->first_response_at ? 'Resolution SLA breached.' : 'Response SLA breached.',
            ]);
            // Escalation: notify branch managers (in-app/WhatsApp to staff) — wired in Phase 1b admin.
        }

        return $breached;
    }

    /** @return array{0:int,1:int} [responseMinutes, resolutionMinutes] */
    public function slaFor(string $priority): array
    {
        $p = SlaPolicy::where('priority', $priority)->first();

        return $p ? [$p->response_minutes, $p->resolution_minutes] : (self::DEFAULT_SLA[$priority] ?? self::DEFAULT_SLA['normal']);
    }
}
