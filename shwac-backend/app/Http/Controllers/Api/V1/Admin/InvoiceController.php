<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ResolvesInvoiceLines;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Invoice;
use App\Models\ServiceJob;
use App\Models\Warehouse;
use App\Services\Billing\InvoiceService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    use ResolvesInvoiceLines;

    public function index(Request $request): JsonResponse
    {
        $q = Invoice::query()->visibleTo($request->user())->with('customer:id,name,phone');
        foreach (['status', 'branch_id', 'customer_id'] as $f) {
            if ($request->filled($f)) {
                $q->whereIn($f, (array) $request->input($f));
            }
        }
        if ($request->boolean('outstanding')) {
            $q->whereIn('status', ['issued', 'partially_paid']);
        }
        if ($request->filled('from')) { $q->whereDate('invoice_date', '>=', $request->date('from')); }
        if ($request->filled('to')) { $q->whereDate('invoice_date', '<=', $request->date('to')); }
        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhereHas('customer', fn ($c) => $c->where('phone', 'like', "%{$s}%")));
        }

        return response()->json($q->latest('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request, InvoiceService $invoices): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'service_job_id' => ['nullable', 'exists:service_jobs,id'],
            'customer_address_id' => ['nullable', 'exists:customer_addresses,id'],
            'stock_warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'issue' => ['sometimes', 'boolean'],
        ]);
        $lines = $this->validatedLines($request);
        $customer = Customer::findOrFail($data['customer_id']);
        $this->ensureVisible($customer, $request->user());

        $job = isset($data['service_job_id']) ? ServiceJob::findOrFail($data['service_job_id']) : null;
        abort_if($job && $job->customer_id !== $customer->id, 422, 'Job belongs to another customer.');
        $address = isset($data['customer_address_id']) ? CustomerAddress::where('customer_id', $customer->id)->findOrFail($data['customer_address_id']) : null;

        $warehouse = isset($data['stock_warehouse_id']) ? Warehouse::findOrFail($data['stock_warehouse_id']) : null;

        $invoice = $invoices->createDraft($customer, Branch::findOrFail($customer->branch_id), $lines, $address, $job, $request->user(), $data['notes'] ?? null, $warehouse);
        if ($request->boolean('issue')) {
            $invoice = $invoices->issue($invoice, true, $request->user());
        }

        return response()->json($invoice, 201);
    }

    public function show(Request $request, Invoice $invoice, PaymentService $payments): JsonResponse
    {
        $this->ensureVisible($invoice, $request->user());
        $invoice->load(['items', 'customer', 'branch', 'payments', 'paymentLinks', 'job:id,number']);
        $invoice->setAttribute('upi_intent', $invoice->isPayable() ? $payments->upiIntent($invoice) : null);
        $invoice->setAttribute('public_url', $invoice->publicUrl());
        $invoice->setAttribute('messages', \App\Models\NotificationLog::query()->whereNotNull('link')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('notifiable_type', $invoice->getMorphClass())->where('notifiable_id', $invoice->id))
                ->orWhere(fn ($w) => $w->where('notifiable_type', (new \App\Models\Payment)->getMorphClass())->whereIn('notifiable_id', $invoice->payments->pluck('id'))))
            ->orderBy('id')->get()->map->share()->filter()->values());

        return response()->json($invoice);
    }

    public function update(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->ensureVisible($invoice, $request->user());
        $notes = $request->validate(['notes' => ['nullable', 'string', 'max:2000']])['notes'] ?? null;

        return response()->json($invoices->updateDraft($invoice, $this->validatedLines($request), $notes));
    }

    public function issue(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->ensureVisible($invoice, $request->user());

        return response()->json($invoices->issue($invoice, $request->boolean('send_payment_link', true), $request->user()));
    }

    public function cancel(Request $request, Invoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $this->ensureVisible($invoice, $request->user());
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json($invoices->cancel($invoice, $data['reason'], $request->user()));
    }

    public function paymentLink(Request $request, Invoice $invoice, PaymentService $payments): JsonResponse
    {
        $this->ensureVisible($invoice, $request->user());

        return response()->json($payments->createPaymentLink($invoice), 201);
    }

    /** Printable GST invoice (HTML). PDF rendering is added with dompdf in Phase 1b. */
    public function print(Request $request, Invoice $invoice)
    {
        $this->ensureVisible($invoice, $request->user());

        return response()->view('invoices.print', ['invoice' => $invoice->load('items', 'branch', 'customer')]);
    }
}
