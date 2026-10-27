<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\Messaging\WhatsAppClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWhatsAppTemplate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public int $logId) {}

    public function handle(WhatsAppClient $client): void
    {
        $log = NotificationLog::find($this->logId);
        if (! $log || $log->status !== 'queued') {
            return;
        }

        if (! $client->isConfigured()) {
            $log->update(['status' => 'skipped', 'error' => 'WhatsApp not configured (set WHATSAPP_* in .env)']);

            return;
        }

        $p = $log->payload;
        try {
            $id = $client->sendTemplate($log->to, $p['template'], $p['language'], $p['params'] ?? [], $p['button_url_suffix'] ?? null);
            $log->update(['status' => 'sent', 'provider_message_id' => $id, 'error' => null]);
        } catch (Throwable $e) {
            $log->update(['error' => $e->getMessage()]);
            // Never let a messaging failure break the business request when running on the sync driver.
            if ($this->attempts() >= $this->tries || $this->job instanceof \Illuminate\Queue\Jobs\SyncJob || $this->job === null) {
                $log->update(['status' => 'failed']);

                return;
            }
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        NotificationLog::whereKey($this->logId)->update(['status' => 'failed', 'error' => $e->getMessage()]);
    }
}
