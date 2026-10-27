<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Payment::query()->visibleTo($request->user())->with(['customer:id,name,phone', 'invoice:id,number', 'receiver:id,name']);
        foreach (['status', 'method', 'branch_id', 'customer_id', 'invoice_id'] as $f) {
            if ($request->filled($f)) {
                $q->whereIn($f, (array) $request->input($f));
            }
        }
        if ($request->filled('from')) { $q->whereDate('paid_at', '>=', $request->date('from')); }
        if ($request->filled('to')) { $q->whereDate('paid_at', '<=', $request->date('to')); }

        return response()->json($q->latest('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => ['required', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['cash', 'upi', 'card', 'bank_transfer', 'cheque'])],
            'reference' => ['nullable', 'string', 'max:60', Rule::requiredIf(fn () => in_array($request->input('method'), ['upi', 'bank_transfer', 'cheque'], true))],
        ]);
        $invoice = Invoice::findOrFail($data['invoice_id']);
        $this->ensureVisible($invoice, $request->user());

        $payment = $payments->recordManual($invoice, $data['amount'], $data['method'], $data['reference'] ?? null, $request->user());

        return response()->json($payment->load('invoice:id,number,status,balance_due'), 201);
    }

    /** Confirm (money received) or reject a UPI payment the customer reported. */
    public function verify(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        $this->ensureVisible($payment, $request->user());
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255', 'required_if:approve,false'],
        ]);
        $p = $payments->verify($payment, $data['approve'], $request->user(), $data['note'] ?? null);

        return response()->json($p->load('invoice:id,number,status,balance_due'));
    }
}
