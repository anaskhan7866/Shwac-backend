<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Api\V1\Admin\CustomerController as AdminCustomers;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Operations\BookingService;
use App\Services\Operations\ComplaintService;
use App\Services\Payments\PaymentService;
use App\Services\Payments\RazorpayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Customer website / app: profile, addresses, products, bookings, complaints, invoices, payments.
 * Every query is pinned to the logged-in customer.
 */
class PortalController extends Controller
{
    private function customer(Request $request): Customer
    {
        return $request->user()->customer ?? abort(404, 'Customer profile not found.');
    }

    public function profile(Request $request): JsonResponse
    {
        $c = $this->customer($request)->load('addresses', 'products');

        return response()->json($c->makeHidden(['notes', 'branch_id', 'user_id']));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $c = $this->customer($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120'],
            'whatsapp' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'whatsapp_opt_in' => ['sometimes', 'boolean'],
            'gstin' => ['nullable', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
        ]);
        $c->update($data);
        if (isset($data['name'])) {
            $request->user()->update(['name' => $data['name']]);
        }

        return response()->json($c);
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $c = $this->customer($request);
        $data = $request->validate(AdminCustomers::addressRules());
        $address = DB::transaction(function () use ($c, $data) {
            $first = ! $c->addresses()->exists();
            if (! empty($data['is_default']) || $first) {
                $c->addresses()->update(['is_default' => false]);
                $data['is_default'] = true;
            }

            return $c->addresses()->create($data);
        });

        return response()->json($address, 201);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $c = $this->customer($request);
        $data = $request->validate([
            'customer_address_id' => ['nullable', Rule::exists('customer_addresses', 'id')->where('customer_id', $c->id)],
            'category' => ['nullable', 'string', 'max:60'],
            'product_name' => ['required', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'serial_no' => ['nullable', 'string', 'max:60'],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        return response()->json($c->products()->create($data), 201);
    }

    // --- Bookings -------------------------------------------------------------

    public function bookings(Request $request): JsonResponse
    {
        return response()->json(
            $this->customer($request)->bookings()->with(['service:id,name', 'jobs:id,booking_id,number,status,technician_id', 'jobs.technician:id,name,phone'])
                ->latest('id')->paginate($this->perPage($request))
        );
    }

    public function storeBooking(Request $request, BookingService $bookings): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'exists:services,id'],
            'customer_address_id' => ['nullable', 'integer'],
            'customer_product_id' => ['nullable', 'integer'],
            'scheduled_date' => ['required', 'date', 'after_or_equal:today', 'before:+60 days'],
            'time_slot' => ['nullable', 'string', 'max:20'],
            'form_data' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $booking = $bookings->create($this->customer($request), Service::findOrFail($data['service_id']), $data + ['source' => 'app'], $request->user());

        return response()->json($booking->load('service:id,name'), 201);
    }

    public function showBooking(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($booking->customer_id === $this->customer($request)->id, 404);

        return response()->json($booking->load(['service:id,name', 'address', 'jobs.technician:id,name,phone', 'jobs.statusLogs:id,service_job_id,to_status,created_at']));
    }

    public function cancelBooking(Request $request, Booking $booking, BookingService $bookings): JsonResponse
    {
        abort_unless($booking->customer_id === $this->customer($request)->id, 404);
        abort_if($booking->jobs()->whereIn('status', ['en_route', 'in_progress', 'completed'])->exists(), 422, 'Technician is already on the way; please call us to cancel.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json($bookings->cancel($booking, $data['reason'], $request->user()));
    }

    // --- Complaints -----------------------------------------------------------

    public function complaints(Request $request): JsonResponse
    {
        return response()->json(
            $this->customer($request)->complaints()->with('assignee:id,name')->latest('id')->paginate($this->perPage($request))
        );
    }

    public function storeComplaint(Request $request, ComplaintService $complaints): JsonResponse
    {
        $c = $this->customer($request);
        $data = $request->validate([
            'customer_address_id' => ['nullable', Rule::exists('customer_addresses', 'id')->where('customer_id', $c->id)],
            'customer_product_id' => ['nullable', Rule::exists('customer_products', 'id')->where('customer_id', $c->id)],
            'category' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
        // Customers can't self-escalate priority; staff triage it.
        $complaint = $complaints->create($c, $data + ['channel' => 'app', 'priority' => 'normal'], $request->user());

        return response()->json($this->publicComplaint($complaint), 201);
    }

    /** Live tracking: status, assigned technician, SLA deadline, public updates and job progress. */
    public function showComplaint(Request $request, Complaint $complaint): JsonResponse
    {
        abort_unless($complaint->customer_id === $this->customer($request)->id, 404);

        return response()->json($this->publicComplaint($complaint));
    }

    public function commentComplaint(Request $request, Complaint $complaint, ComplaintService $complaints): JsonResponse
    {
        abort_unless($complaint->customer_id === $this->customer($request)->id, 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000'], 'reopen' => ['sometimes', 'boolean']]);
        $complaints->comment($complaint, $request->user(), $data['body']);
        if (($data['reopen'] ?? false) && in_array($complaint->status, ['resolved', 'closed'], true)) {
            $complaints->changeStatus($complaint, 'reopened', $request->user(), 'Reopened by customer');
        }

        return response()->json($this->publicComplaint($complaint->refresh()), 201);
    }

    // --- Invoices & payments --------------------------------------------------

    public function invoices(Request $request): JsonResponse
    {
        return response()->json(
            $this->customer($request)->invoices()->where('status', '!=', 'draft')->latest('id')->paginate($this->perPage($request))
        );
    }

    public function showInvoice(Request $request, Invoice $invoice, PaymentService $payments): JsonResponse
    {
        abort_unless($invoice->customer_id === $this->customer($request)->id && $invoice->status !== 'draft', 404);
        $invoice->load('items', 'branch:id,name,gstin,address,phone', 'payments:id,invoice_id,number,amount,method,status,reference,paid_at,review_note');
        $invoice->setAttribute('upi_intent', $invoice->isPayable() ? $payments->upiIntent($invoice) : null);
        $invoice->setAttribute('public_url', $invoice->publicUrl());
        $invoice->setAttribute('online_payment', config('shwac.payments.gateway') === 'razorpay');

        return response()->json($invoice);
    }

    /** Customer paid by UPI (app/QR) and reports the reference; staff verify it. */
    public function upiClaim(Request $request, Invoice $invoice, PaymentService $payments): JsonResponse
    {
        abort_unless($invoice->customer_id === $this->customer($request)->id && $invoice->status !== 'draft', 404);
        $data = $request->validate(['reference' => ['required', 'string', 'max:40']]);

        return response()->json($payments->claimUpi($invoice, $data['reference'], 'customer_app'), 201);
    }

    /** Start Razorpay Checkout for the outstanding balance. */
    public function payInvoice(Request $request, Invoice $invoice, PaymentService $payments, RazorpayClient $rzp): JsonResponse
    {
        $c = $this->customer($request);
        abort_unless($invoice->customer_id === $c->id, 404);
        $payment = $payments->createOrder($invoice);

        return response()->json([
            'payment_id' => $payment->id,
            'razorpay' => [
                'key' => $rzp->keyId(),
                'order_id' => $payment->gateway_order_id,
                'amount' => \App\Support\Money::toPaise($payment->amount),
                'currency' => 'INR',
                'name' => config('app.name'),
                'description' => 'Invoice '.$invoice->number,
                'prefill' => ['name' => $c->name, 'contact' => $c->phone, 'email' => $c->email],
            ],
        ], 201);
    }

    /**
     * Hosted Razorpay payment page (UPI, cards, netbanking). Used by the Android and web apps:
     * they open `short_url` in the browser and the webhook marks the invoice paid.
     */
    public function invoicePaymentLink(Request $request, Invoice $invoice, PaymentService $payments): JsonResponse
    {
        abort_unless($invoice->customer_id === $this->customer($request)->id && $invoice->status !== 'draft', 404);
        abort_unless(config('shwac.payments.gateway') === 'razorpay', 422, 'Online card payment is not enabled. Please pay by UPI.');
        try {
            $link = $payments->createPaymentLink($invoice);
        } catch (\RuntimeException $e) {
            report($e);
            abort(503, 'Online payment is temporarily unavailable. Please try again or pay the technician.');
        }

        return response()->json($link->only(['short_url', 'amount', 'status', 'expires_at']));
    }

    public function confirmPayment(Request $request, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);
        $payment = $payments->confirmCheckout($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature']);
        abort_unless($payment->customer_id === $this->customer($request)->id, 404);

        return response()->json($payment->load('invoice:id,number,status,balance_due'));
    }

    private function publicComplaint(Complaint $complaint): array
    {
        $complaint->load([
            'assignee:id,name,phone',
            'updates' => fn ($q) => $q->where('is_internal', false),
            'updates.user:id,name',
            'jobs:id,complaint_id,number,status,scheduled_date,technician_id',
            'jobs.statusLogs:id,service_job_id,to_status,created_at',
        ]);

        return $complaint->only([
            'id', 'number', 'subject', 'description', 'category', 'status', 'priority', 'created_at',
            'sla_resolution_due_at', 'resolved_at', 'resolution_notes', 'assignee', 'updates', 'jobs',
        ]);
    }
}
