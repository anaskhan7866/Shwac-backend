<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Status: ready (link prepared, waiting for someone to tap Send) · sent · delivered · read ·
 * queued (Cloud API) · failed · skipped.
 */
class NotificationLog extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'sent_at' => 'datetime'];

    public const LABELS = [
        'booking_confirmed' => 'Booking confirmation',
        'technician_assigned' => 'Technician details',
        'technician_on_the_way' => 'On the way',
        'complaint_registered' => 'Complaint registered',
        'invoice_issued' => 'Invoice & payment link',
        'payment_received' => 'Payment receipt',
        'payment_reminder' => 'Payment reminder',
    ];

    public function notifiable() { return $this->morphTo(); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function sender() { return $this->belongsTo(User::class, 'sent_by'); }

    public function scopeToSend(Builder $q): Builder
    {
        return $q->where('status', 'ready')->whereNotNull('link');
    }

    /** What the apps need to show a "Send on WhatsApp" button. */
    public function share(): ?array
    {
        if (! $this->link || ! in_array($this->status, ['ready', 'sent'], true)) {
            return null;
        }

        return [
            'id' => $this->id,
            'event' => $this->event,
            'label' => self::LABELS[$this->event] ?? ucfirst(str_replace('_', ' ', (string) $this->event)),
            'to' => $this->to,
            'message' => $this->message,
            'url' => $this->link,
            'sent' => $this->status === 'sent',
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }

    public function markSent(?User $by): self
    {
        if ($this->status === 'ready') {
            $this->update(['status' => 'sent', 'sent_by' => $by?->id, 'sent_at' => now()]);
        }

        return $this;
    }

    /** All prepared messages about a job, its invoice and its payments (for the job screen). */
    public static function forJob(ServiceJob $job): \Illuminate\Support\Collection
    {
        $invoiceIds = Invoice::where('service_job_id', $job->id)->pluck('id');
        $paymentIds = Payment::whereIn('invoice_id', $invoiceIds)->pluck('id');

        return static::query()
            ->whereNotNull('link')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('notifiable_type', $job->getMorphClass())->where('notifiable_id', $job->id))
                ->orWhere(fn ($w) => $w->where('notifiable_type', (new Invoice)->getMorphClass())->whereIn('notifiable_id', $invoiceIds))
                ->orWhere(fn ($w) => $w->where('notifiable_type', (new Payment)->getMorphClass())->whereIn('notifiable_id', $paymentIds))
                ->orWhere(fn ($w) => $job->complaint_id
                    ? $w->where('notifiable_type', (new Complaint)->getMorphClass())->where('notifiable_id', $job->complaint_id)
                    : $w->whereRaw('1 = 0')))
            ->orderBy('id')->get()
            ->map(fn (self $l) => $l->share())->filter()->values();
    }
}
