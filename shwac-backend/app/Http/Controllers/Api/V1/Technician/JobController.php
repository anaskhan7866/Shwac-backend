<?php

namespace App\Http\Controllers\Api\V1\Technician;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInvoiceLines;
use App\Http\Controllers\Controller;
use App\Models\ServiceJob;
use App\Services\Billing\InvoiceService;
use App\Services\Inventory\InventoryService;
use App\Services\Operations\JobService;
use App\Services\Payments\PaymentService;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Field technician app: my jobs (with route order), status updates with GPS,
 * on-site billing from van stock, payment collection, photos and signature.
 * All writes accept an `Idempotency-Key` header so offline replays are safe.
 */
class JobController extends Controller
{
    use ResolvesInvoiceLines;

    private const WITH = ['customer:id,name,phone,whatsapp', 'address', 'branch:id,name,state_code', 'service:id,name,base_price,tax_rate', 'booking:id,number,form_data,notes',
        'complaint:id,number,subject,description,priority,sla_resolution_due_at,customer_product_id', 'complaint.customerProduct'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = ServiceJob::where('technician_id', $user->id)->with(self::WITH);

        $view = $request->input('view', 'active');
        match ($view) {
            'today' => $q->whereDate('scheduled_date', today()),
            'completed' => $q->where('status', 'completed')->latest('completed_at'),
            default => $q->whereIn('status', ServiceJob::ACTIVE_STATUSES),
        };

        if ($request->filled('updated_since')) {
            $q->where('updated_at', '>', $request->date('updated_since'));
        }

        if ($view === 'completed') {
            return response()->json($q->paginate($this->perPage($request)));
        }

        // Route order: nearest-first from the technician's position (sent by the app, else last known).
        $jobs = $q->orderBy('scheduled_date')->orderBy('id')->limit(100)->get()->all();
        $lat = $request->filled('lat') ? (float) $request->input('lat') : $user->last_lat;
        $lng = $request->filled('lng') ? (float) $request->input('lng') : $user->last_lng;
        $route = Geo::nearestNeighbourRoute($lat, $lng, $jobs, fn (ServiceJob $j) => [$j->address?->lat, $j->address?->lng]);

        return response()->json([
            'data' => collect($route)->values()->map(fn ($r, $i) => $r['stop']->setAttribute('route_position', $i + 1)->setAttribute('leg_km', $r['leg_km'])),
            'route_total_km' => round(collect($route)->sum('leg_km'), 1),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function show(Request $request, ServiceJob $job): JsonResponse
    {
        $this->own($request, $job);

        $job->load(array_merge(self::WITH, ['statusLogs', 'invoice.items', 'invoice.payments', 'attachments']));
        if ($job->invoice) {
            $job->invoice->setAttribute('upi_intent', $job->invoice->isPayable() ? app(PaymentService::class)->upiIntent($job->invoice) : null);
            $job->invoice->setAttribute('public_url', $job->invoice->publicUrl());
        }
        $job->setAttribute('messages', \App\Models\NotificationLog::forJob($job));

        return response()->json($job);
    }

    public function updateStatus(Request $request, ServiceJob $job, JobService $jobs): JsonResponse
    {
        $this->own($request, $job);
        $data = $request->validate([
            'status' => ['required', Rule::in(['accepted', 'en_route', 'in_progress', 'completed'])],
            'note' => ['nullable', 'string', 'max:1000', Rule::requiredIf(fn () => $request->input('status') === 'completed')],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        // An offline replay of a step the job has already passed is a no-op, not an error.
        if ($job->status === $data['status']) {
            return response()->json($job);
        }

        return response()->json($jobs->transition($job, $data['status'], $request->user(), $data['note'] ?? null, $data['lat'] ?? null, $data['lng'] ?? null));
    }

    /**
     * On-site billing: catalog prices only; spare parts come out of the technician's van
     * when the invoice is issued. WhatsApp sends it with a Razorpay pay link.
     */
    public function invoice(Request $request, ServiceJob $job, InvoiceService $invoices, InventoryService $inventory): JsonResponse
    {
        $this->own($request, $job);
        abort_unless(in_array($job->status, ['in_progress', 'completed'], true), 422, 'Start the job before billing.');
        abort_if($job->invoice()->exists(), 422, 'This job already has an invoice.');

        $lines = $this->validatedLines($request, allowPriceOverride: false);
        $van = $inventory->vanFor($request->user());

        $invoice = \DB::transaction(function () use ($invoices, $job, $lines, $request, $van) {
            $draft = $invoices->createDraft($job->customer, $job->branch, $lines, $job->address, $job, $request->user(), null, $van);

            return $invoices->issue($draft, true, $request->user());
        });

        return response()->json($invoice->load('items'), 201);
    }

    /** Record cash / UPI collected at the door against the job's invoice. */
    public function collect(Request $request, ServiceJob $job, PaymentService $payments): JsonResponse
    {
        $this->own($request, $job);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['cash', 'upi'])],
            'reference' => ['nullable', 'string', 'max:60', 'required_if:method,upi'],
        ]);
        $invoice = $job->invoice ?? abort(422, 'Create the invoice first.');

        return response()->json($payments->recordManual($invoice, $data['amount'], $data['method'], $data['reference'] ?? null, $request->user()), 201);
    }

    /** Before/after photos and the customer's signature. */
    public function attach(Request $request, ServiceJob $job): JsonResponse
    {
        $this->own($request, $job);
        $data = $request->validate([
            'type' => ['required', Rule::in(['before', 'after', 'other', 'signature'])],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'caption' => ['nullable', 'string', 'max:120'],
        ]);
        abort_if($data['type'] === 'signature' && $job->attachments()->where('type', 'signature')->exists(), 422, 'Signature already captured.');

        $path = $request->file('file')->store('jobs/'.$job->id, 'public');
        $att = $job->attachments()->create(['user_id' => $request->user()->id, 'type' => $data['type'], 'path' => $path, 'caption' => $data['caption'] ?? null]);

        return response()->json($att, 201);
    }

    /** The technician tapped "Send on WhatsApp" for a prepared message about one of their jobs. */
    public function messageSent(Request $request, \App\Models\NotificationLog $log): JsonResponse
    {
        $mine = ServiceJob::where('technician_id', $request->user()->id)->get()
            ->contains(fn (ServiceJob $j) => \App\Models\NotificationLog::forJob($j)->contains('id', $log->id));
        abort_unless($mine, 404);

        return response()->json($log->markSent($request->user())->share());
    }

    private function own(Request $request, ServiceJob $job): void
    {
        abort_unless((int) $job->technician_id === (int) $request->user()->id, 404);
    }
}
