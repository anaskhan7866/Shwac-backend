<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentService;
use App\Services\Payments\RazorpayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RazorpayWebhookController extends Controller
{
    public function __invoke(Request $request, RazorpayClient $rzp, PaymentService $payments): JsonResponse
    {
        if (! $rzp->verifyWebhookSignature($request->getContent(), $request->header('X-Razorpay-Signature'))) {
            Log::warning('Razorpay webhook: bad signature', ['ip' => $request->ip()]);

            return response()->json(['error' => 'invalid signature'], 400);
        }

        $payment = $payments->handleRazorpayWebhook($request->json()->all());

        return response()->json(['ok' => true, 'payment' => $payment?->number]);
    }
}
