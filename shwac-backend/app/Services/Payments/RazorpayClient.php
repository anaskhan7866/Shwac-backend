<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Razorpay REST client (Orders, Payment Links, signature checks).
 * https://razorpay.com/docs/api/
 */
class RazorpayClient
{
    public function isConfigured(): bool
    {
        return (bool) (config('services.razorpay.key_id') && config('services.razorpay.key_secret'));
    }

    public function keyId(): ?string
    {
        return config('services.razorpay.key_id');
    }

    /** @return array{id:string, amount:int, currency:string, receipt:?string, status:string} */
    public function createOrder(int $amountPaise, string $receipt, array $notes = []): array
    {
        return $this->request()->post('/orders', [
            'amount' => $amountPaise,
            'currency' => 'INR',
            'receipt' => mb_substr($receipt, 0, 40),
            'notes' => $notes,
        ])->throw()->json();
    }

    /**
     * Hosted payment link (UPI, cards, netbanking). We send it ourselves on WhatsApp,
     * so Razorpay's own SMS/email notifications are switched off.
     *
     * @return array{id:string, short_url:string, status:string, expire_by:?int}
     */
    public function createPaymentLink(int $amountPaise, string $referenceId, string $description, array $customer, ?int $expireBy = null, array $notes = []): array
    {
        try {
            return $this->request()->post('/payment_links', array_filter([
                'amount' => $amountPaise,
                'currency' => 'INR',
                'accept_partial' => false,
                'reference_id' => mb_substr($referenceId, 0, 40),
                'description' => mb_substr($description, 0, 2048),
                'customer' => $customer,
                'notify' => ['sms' => false, 'email' => false],
                'reminder_enable' => true,
                'expire_by' => $expireBy,
                'notes' => $notes ?: null,
            ], fn ($v) => $v !== null))->throw()->json();
        } catch (RequestException $e) {
            throw new RuntimeException('Razorpay: '.($e->response->json('error.description') ?? $e->getMessage()), previous: $e);
        }
    }

    /** Checkout handler signature: HMAC_SHA256(order_id|payment_id, key_secret). */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = (string) config('services.razorpay.key_secret');

        return $secret !== '' && hash_equals(hash_hmac('sha256', $orderId.'|'.$paymentId, $secret), $signature);
    }

    /** Webhook signature: HMAC_SHA256(raw body, webhook_secret) in X-Razorpay-Signature. */
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('services.razorpay.webhook_secret');

        return $secret !== '' && $signature !== null && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    private function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Razorpay is not configured (RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET).');
        }

        return Http::baseUrl(rtrim(config('services.razorpay.base_url'), '/'))
            ->withBasicAuth(config('services.razorpay.key_id'), config('services.razorpay.key_secret'))
            ->acceptJson()->asJson()->timeout(20);
    }
}
