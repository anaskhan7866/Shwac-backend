<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\Messaging\Notifier;
use App\Services\NumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private RazorpayClient $razorpay,
        private NumberGenerator $numbers,
        private Notifier $notifier,
    ) {}

    /** Cash / UPI-to-QR / bank transfer / cheque collected by staff or technician. */
    public function recordManual(Invoice $invoice, string|float $amount, string $method, ?string $reference, User $by): Payment
    {
        $this->assertPayable($invoice, $amount);

        $payment = DB::transaction(function () use ($invoice, $amount, $method, $reference, $by) {
            $payment = Payment::create([
                'branch_id' => $invoice->branch_id,
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'amount' => Money::fromPaise(Money::toPaise($amount)),
                'method' => $method,
                'source' => $by->isTechnician() ? 'technician' : 'staff',
                'reference' => $reference,
                'received_by' => $by->id,
            ]);

            return $this->markSuccess($payment);
        });

        return $payment;
    }

    /** Razorpay Checkout order for the in-app / website "Pay now" button. */
    public function createOrder(Invoice $invoice): Payment
    {
        $this->assertPayable($invoice, $invoice->balance_due);
        $amount = Money::toPaise($invoice->balance_due);

        $order = $this->razorpay->createOrder($amount, (string) $invoice->number, ['invoice_id' => (string) $invoice->id]);

        return Payment::create([
            'branch_id' => $invoice->branch_id,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'amount' => Money::fromPaise($amount),
            'method' => 'razorpay',
            'gateway' => 'razorpay',
            'gateway_order_id' => $order['id'],
            'status' => 'pending',
        ]);
    }

    /** Called by the client after Checkout succeeds. The webhook is the source of truth; this just makes the UI instant. */
    public function confirmCheckout(string $orderId, string $paymentId, string $signature): Payment
    {
        if (! $this->razorpay->verifyPaymentSignature($orderId, $paymentId, $signature)) {
            throw ValidationException::withMessages(['razorpay_signature' => 'Payment signature verification failed.']);
        }

        return DB::transaction(function () use ($orderId, $paymentId) {
            $payment = Payment::where('gateway_order_id', $orderId)->lockForUpdate()->firstOrFail();
            if ($payment->status === 'success') {
                return $payment;
            }
            $payment->gateway_payment_id = $paymentId;

            return $this->markSuccess($payment);
        });
    }

    public function createPaymentLink(Invoice $invoice): PaymentLink
    {
        $this->assertPayable($invoice, $invoice->balance_due);

        $existing = $invoice->paymentLinks()
            ->where('status', 'created')
            ->where('amount', $invoice->balance_due)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        $customer = $invoice->customer;
        $expires = now()->addDays(15);
        $res = $this->razorpay->createPaymentLink(
            Money::toPaise($invoice->balance_due),
            (string) $invoice->number.'-'.now()->format('His'),
            'Invoice '.$invoice->number.' — '.config('app.name'),
            array_filter(['name' => $customer->name, 'contact' => '+'.$customer->whatsappNumber(), 'email' => $customer->email]),
            $expires->timestamp,
            ['invoice_id' => (string) $invoice->id],
        );

        return $invoice->paymentLinks()->create([
            'gateway' => 'razorpay',
            'gateway_link_id' => $res['id'],
            'short_url' => $res['short_url'],
            'amount' => $invoice->balance_due,
            'status' => 'created',
            'expires_at' => $expires,
        ]);
    }

    /** UPI deep link (works with any UPI app / can be rendered as a QR). Not auto-reconciled. */
    public function upiIntent(Invoice $invoice): ?string
    {
        $vpa = config('services.upi.vpa');
        if (! $vpa) {
            return null;
        }

        return 'upi://pay?'.http_build_query([
            'pa' => $vpa,
            'pn' => config('services.upi.payee_name'),
            'am' => $invoice->balance_due,
            'cu' => 'INR',
            'tn' => 'Invoice '.$invoice->number,
            'tr' => $invoice->number,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Razorpay webhook. Handles payment.captured, order.paid and payment_link.paid.
     * Idempotent on gateway_payment_id.
     */
    public function handleRazorpayWebhook(array $event): ?Payment
    {
        $type = $event['event'] ?? '';
        $entity = data_get($event, 'payload.payment.entity');
        if (! $entity || ! in_array($type, ['payment.captured', 'order.paid', 'payment_link.paid'], true)) {
            return null;
        }

        return DB::transaction(function () use ($type, $entity, $event) {
            if ($done = Payment::where('gateway_payment_id', $entity['id'])->first()) {
                return $done;
            }

            $payment = null;
            if ($type === 'payment_link.paid') {
                $linkId = data_get($event, 'payload.payment_link.entity.id');
                $link = PaymentLink::where('gateway_link_id', $linkId)->lockForUpdate()->first();
                if (! $link) {
                    return null;
                }
                $link->update(['status' => 'paid']);
                $invoice = $link->invoice;
                $payment = new Payment([
                    'branch_id' => $invoice->branch_id,
                    'customer_id' => $invoice->customer_id,
                    'invoice_id' => $invoice->id,
                    'method' => 'razorpay',
                    'gateway' => 'razorpay',
                    'gateway_order_id' => $entity['order_id'] ?? null,
                ]);
            } elseif (! empty($entity['order_id'])) {
                $payment = Payment::where('gateway_order_id', $entity['order_id'])->lockForUpdate()->first();
                if ($payment?->status === 'success') {
                    return $payment;
                }
            }

            if (! $payment) {
                return null;
            }

            $payment->fill([
                'amount' => Money::fromPaise((int) $entity['amount']),
                'gateway_payment_id' => $entity['id'],
                'gateway_payload' => ['method' => $entity['method'] ?? null, 'vpa' => $entity['vpa'] ?? null, 'event' => $type],
            ]);

            return $this->markSuccess($payment);
        });
    }

    /** Must run inside a transaction. */
    private function markSuccess(Payment $payment): Payment
    {
        $payment->fill([
            'status' => 'success',
            'paid_at' => now(),
            'number' => $payment->number ?? $this->numbers->receipt($payment->branch()->first()),
        ])->save();

        if ($invoice = $payment->invoice()->lockForUpdate()->first()) {
            app(InvoiceService::class)->refreshPaymentStatus($invoice);
        }

        $log = $this->notifier->notifyCustomer($payment->customer, 'payment_received', [
            'amount' => Money::format($payment->amount),
            'invoice_number' => $invoice?->number ?? '-',
            'receipt_number' => $payment->number,
            'balance_due' => Money::format($invoice?->balance_due ?? 0),
            'link' => $invoice?->publicUrl() ?? '',
        ], $payment);

        return $payment->setAttribute('whatsapp', $log?->share());
    }

    /**
     * Free UPI flow: the customer paid by UPI (QR / UPI app) and reports the UTR from the
     * invoice page or the app. The payment waits for staff to check it in the bank/UPI app.
     */
    public function claimUpi(Invoice $invoice, string $reference, string $source, string|float|null $amount = null): Payment
    {
        $amount ??= $invoice->balance_due;
        $this->assertPayable($invoice, $amount);
        $ref = strtoupper(preg_replace('/\s+/', '', $reference));
        if (! preg_match('/^[A-Z0-9]{6,30}$/', $ref)) {
            throw ValidationException::withMessages(['reference' => 'Enter the UPI reference / UTR number shown in your UPI app (usually 12 digits).']);
        }
        if (Payment::where('reference', $ref)->whereIn('status', ['success', 'pending_verification'])->exists()) {
            throw ValidationException::withMessages(['reference' => 'This UPI reference has already been submitted.']);
        }

        return Payment::create([
            'branch_id' => $invoice->branch_id,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'amount' => Money::fromPaise(Money::toPaise($amount)),
            'method' => 'upi',
            'source' => $source,
            'reference' => $ref,
            'status' => 'pending_verification',
        ]);
    }

    /** Staff confirm (money is in the account) or reject a reported UPI payment. */
    public function verify(Payment $payment, bool $approve, User $by, ?string $note = null): Payment
    {
        if ($payment->status !== 'pending_verification') {
            throw ValidationException::withMessages(['status' => 'This payment is not waiting for verification.']);
        }

        return DB::transaction(function () use ($payment, $approve, $by, $note) {
            $payment->fill(['verified_by' => $by->id, 'verified_at' => now(), 'review_note' => $note]);
            if (! $approve) {
                $payment->update(['status' => 'failed']);

                return $payment;
            }
            $invoice = $payment->invoice;
            if ($invoice && Money::toPaise($payment->amount) > Money::toPaise($invoice->balance_due)) {
                throw ValidationException::withMessages(['amount' => 'This is more than the balance due ('.Money::format($invoice->balance_due).').']);
            }
            $payment->received_by = $by->id;

            return $this->markSuccess($payment);
        });
    }

    private function assertPayable(Invoice $invoice, string|float $amount): void
    {
        if (! $invoice->isPayable()) {
            throw ValidationException::withMessages(['invoice' => "Invoice is {$invoice->status} and cannot take payments."]);
        }
        $amt = Money::toPaise($amount);
        if ($amt <= 0 || $amt > Money::toPaise($invoice->balance_due)) {
            throw ValidationException::withMessages(['amount' => 'Amount must be between ₹0.01 and the balance due ('.Money::format($invoice->balance_due).').']);
        }
    }
}
