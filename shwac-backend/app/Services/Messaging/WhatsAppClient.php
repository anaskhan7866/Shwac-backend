<?php

namespace App\Services\Messaging;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the Meta WhatsApp Cloud API.
 * https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages
 */
class WhatsAppClient
{
    public function isConfigured(): bool
    {
        return (bool) config('services.whatsapp.enabled')
            && config('services.whatsapp.phone_number_id')
            && config('services.whatsapp.access_token');
    }

    /**
     * Send an approved template message.
     *
     * @param  array<int, string>  $bodyParams  values for {{1}}, {{2}} … in order
     * @param  array<string, mixed>|null  $buttonUrlSuffix  dynamic suffix for a URL button (e.g. payment link id)
     * @return string provider message id (wamid…)
     */
    public function sendTemplate(string $to, string $template, string $language, array $bodyParams = [], ?string $buttonUrlSuffix = null): string
    {
        $components = [];
        if ($bodyParams !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($bodyParams)),
            ];
        }
        if ($buttonUrlSuffix !== null) {
            $components[] = [
                'type' => 'button', 'sub_type' => 'url', 'index' => '0',
                'parameters' => [['type' => 'text', 'text' => $buttonUrlSuffix]],
            ];
        }

        return $this->post([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => array_filter([
                'name' => $template,
                'language' => ['code' => $language],
                'components' => $components ?: null,
            ]),
        ]);
    }

    /** Free-form text — only allowed inside the 24h customer service window. */
    public function sendText(string $to, string $body): string
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $body, 'preview_url' => true],
        ]);
    }

    /** Send a document (e.g. invoice PDF) by public URL. */
    public function sendDocument(string $to, string $url, string $filename, ?string $caption = null): string
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'document',
            'document' => array_filter(['link' => $url, 'filename' => $filename, 'caption' => $caption]),
        ]);
    }

    /** Verify X-Hub-Signature-256 on incoming webhooks. */
    public function verifySignature(string $rawBody, ?string $header): bool
    {
        $secret = config('services.whatsapp.app_secret');
        if (! $secret || ! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $header);
    }

    private function post(array $payload): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('WhatsApp Cloud API is not configured.');
        }

        $url = sprintf('%s/%s/%s/messages',
            rtrim(config('services.whatsapp.base_url'), '/'),
            config('services.whatsapp.api_version'),
            config('services.whatsapp.phone_number_id'),
        );

        try {
            $response = Http::withToken(config('services.whatsapp.access_token'))
                ->acceptJson()->timeout(15)->retry(2, 500, throw: false)
                ->post($url, $payload)
                ->throw();
        } catch (RequestException $e) {
            $error = $e->response->json('error.message') ?? $e->getMessage();
            throw new RuntimeException('WhatsApp send failed: '.$error, previous: $e);
        }

        return (string) $response->json('messages.0.id');
    }
}
