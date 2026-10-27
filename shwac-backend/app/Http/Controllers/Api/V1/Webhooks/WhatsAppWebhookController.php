<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Services\Messaging\WhatsAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Cloud API webhook.
 *  GET  → subscription handshake (hub.verify_token / hub.challenge)
 *  POST → delivery statuses (sent/delivered/read/failed) and inbound messages
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $ok = $request->query('hub_mode') === 'subscribe'
            && config('services.whatsapp.verify_token')
            && hash_equals((string) config('services.whatsapp.verify_token'), (string) $request->query('hub_verify_token'));

        return $ok ? response((string) $request->query('hub_challenge'), 200) : response('Forbidden', 403);
    }

    public function handle(Request $request, WhatsAppClient $wa): JsonResponse
    {
        if (! $wa->verifySignature($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                foreach ($value['statuses'] ?? [] as $s) {
                    $log = NotificationLog::where('provider_message_id', $s['id'] ?? null)->first();
                    $new = $s['status'] ?? null;
                    if ($log && isset($rank[$new]) && $rank[$new] > ($rank[$log->status] ?? -1)) {
                        $log->update([
                            'status' => $new,
                            'error' => $new === 'failed' ? ($s['errors'][0]['title'] ?? 'failed') : $log->error,
                        ]);
                    }
                }

                foreach ($value['messages'] ?? [] as $m) {
                    // Inbound customer message. Phase 4: WhatsApp bot for booking/complaint intake.
                    Log::info('WhatsApp inbound', ['from' => $m['from'] ?? null, 'type' => $m['type'] ?? null]);
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
