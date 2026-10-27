<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Payments\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The link sent on WhatsApp: shows the GST invoice without login, a UPI QR / "Pay with UPI app"
 * button (no gateway fee), and a form to report the UPI reference after paying.
 * Optional: a Razorpay button when PAYMENT_GATEWAY=razorpay.
 */
class PublicInvoiceController extends Controller
{
    private function find(string $token): Invoice
    {
        abort_unless(preg_match('/^[a-z0-9]{24}$/', $token), 404);

        return Invoice::where('public_token', $token)->whereNotIn('status', ['draft'])->with(['items', 'branch', 'customer', 'payments'])->firstOrFail();
    }

    public function show(string $token, PaymentService $payments): View
    {
        $invoice = $this->find($token);

        return view('invoices.public', [
            'invoice' => $invoice,
            'upi' => $invoice->isPayable() ? $payments->upiIntent($invoice) : null,
            'pending' => $invoice->payments->where('status', 'pending_verification'),
            'gateway' => config('shwac.payments.gateway') === 'razorpay',
        ]);
    }

    public function claim(Request $request, string $token, PaymentService $payments): RedirectResponse
    {
        $invoice = $this->find($token);
        $data = $request->validate(['reference' => ['required', 'string', 'max:40']]);
        try {
            $payments->claimUpi($invoice, $data['reference'], 'invoice_link');
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return back()->with('status', 'Thank you! We will confirm your payment shortly and send the receipt on WhatsApp.');
    }

    /** Optional paid gateway: create/reuse a Razorpay link and redirect. */
    public function gateway(string $token, PaymentService $payments): RedirectResponse
    {
        abort_unless(config('shwac.payments.gateway') === 'razorpay', 404);
        $invoice = $this->find($token);
        abort_unless($invoice->isPayable(), 410);

        return redirect()->away($payments->createPaymentLink($invoice)->short_url);
    }
}
