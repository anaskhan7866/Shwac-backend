<?php

namespace App\Services\Messaging;

use App\Jobs\SendWhatsAppTemplate;
use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;

/**
 * Business event → customer message.
 *
 * Default (free) "link" mode: the message text is rendered from the editable template body
 * and saved with a ready-to-open WhatsApp link (wa.me). The office or the technician taps
 * "Send on WhatsApp", their own WhatsApp opens with the text filled in, and they press send.
 * Nothing is sent automatically and there is no per-message cost.
 *
 * "cloud" mode (next stage, paid): the same events are sent automatically through the
 * Meta WhatsApp Cloud API using approved templates.
 */
class Notifier
{
    /** @param array<string, scalar|null> $data */
    public function notifyCustomer(Customer $customer, string $event, array $data, ?Model $subject = null, ?string $buttonUrlSuffix = null): ?NotificationLog
    {
        $to = $customer->whatsappNumber();
        if (! $customer->whatsapp_opt_in) {
            return $this->log($to, $event, $subject, ['status' => 'skipped', 'error' => 'Customer opted out of WhatsApp', 'branch_id' => $customer->branch_id]);
        }

        return $this->send($to, $event, $data + ['customer_name' => $customer->name], $subject, $buttonUrlSuffix, $customer->branch_id);
    }

    /** @param array<string, scalar|null> $data */
    public function send(string $to, string $event, array $data, ?Model $subject = null, ?string $buttonUrlSuffix = null, ?int $branchId = null): ?NotificationLog
    {
        $template = NotificationTemplate::query()
            ->where('event', $event)->where('channel', 'whatsapp')->where('is_active', true)
            ->first();
        if (! $template) {
            return $this->log($to, $event, $subject, ['status' => 'skipped', 'error' => 'No active template for event', 'branch_id' => $branchId]);
        }

        $data += ['company_name' => Setting::get('company.name', config('app.name'))];

        if (config('services.whatsapp.mode', 'link') === 'cloud') {
            $params = array_map(fn (string $key) => (string) ($data[$key] ?? '-'), $template->variables ?? []);
            $log = $this->log($to, $event, $subject, [
                'branch_id' => $branchId,
                'status' => 'queued',
                'message' => $this->render($template, $data),
                'payload' => ['template' => $template->provider_template, 'language' => $template->language, 'params' => $params, 'button_url_suffix' => $buttonUrlSuffix],
            ]);
            SendWhatsAppTemplate::dispatch($log->id)->afterCommit();

            return $log;
        }

        $message = $this->render($template, $data);

        return $this->log($to, $event, $subject, [
            'branch_id' => $branchId,
            'status' => 'ready',
            'message' => $message,
            'link' => self::waLink($to, $message),
        ]);
    }

    /** https://wa.me/<number>?text=… opens WhatsApp (phone app or WhatsApp Web) with the text filled in. */
    public static function waLink(string $toE164NoPlus, string $message = ''): string
    {
        $number = preg_replace('/\D/', '', $toE164NoPlus);

        return 'https://wa.me/'.$number.($message !== '' ? '?text='.rawurlencode($message) : '');
    }

    /** Replace {placeholders} in the template body; falls back to the preview text. */
    public function render(NotificationTemplate $t, array $data): string
    {
        $body = $t->body ?: ($t->preview ?? '');

        return trim(preg_replace_callback('/\{(\w+)\}/', fn ($m) => array_key_exists($m[1], $data) ? (string) ($data[$m[1]] ?? '') : $m[0], $body));
    }

    private function log(string $to, string $event, ?Model $subject, array $attrs): NotificationLog
    {
        return NotificationLog::create($attrs + [
            'channel' => 'whatsapp',
            'event' => $event,
            'to' => $to,
            'notifiable_type' => $subject?->getMorphClass(),
            'notifiable_id' => $subject?->getKey(),
            'created_by' => auth()->id(),
        ]);
    }
}
