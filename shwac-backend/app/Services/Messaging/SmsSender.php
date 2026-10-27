<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Provider-neutral SMS sender for any Indian DLT provider with an HTTP API.
 *
 * Configure once in .env (see config/services.php → sms). Placeholders in the URL / body:
 *   {phone}   91XXXXXXXXXX     {phone10} XXXXXXXXXX
 *   {message} full SMS text    {otp}     the code (for providers that take only variables)
 *
 * Example (generic GET):
 *   SMS_HTTP_URL="https://api.example.in/send?apikey=KEY&sender=SHWACC&to={phone}&text={message}&dlt_template_id=1207xxxxxxxxxx"
 */
class SmsSender
{
    /** @param array<string, string> $vars extra placeholders, e.g. ['otp' => '123456'] */
    public function send(string $phone10, string $message, array $vars = []): void
    {
        $values = ['phone' => '91'.$phone10, 'phone10' => $phone10, 'message' => $message] + $vars;

        if (config('services.sms.driver') !== 'http') {
            Log::info('SMS (log driver)', ['to' => $values['phone'], 'message' => $message]);

            return;
        }

        $url = (string) config('services.sms.url');
        if ($url === '') {
            throw new RuntimeException('SMS_HTTP_URL is not set.');
        }
        $url = $this->fill($url, $values, fn ($v) => rawurlencode($v));
        $headers = json_decode((string) config('services.sms.headers'), true) ?: [];
        $req = Http::withHeaders($headers)->timeout(15)->acceptJson();

        if (strtoupper((string) config('services.sms.method')) === 'POST') {
            $body = (string) config('services.sms.body');
            $json = $body !== '' ? json_decode($this->fill($body, $values, fn ($v) => substr(json_encode($v), 1, -1)), true) : null;
            $res = is_array($json) ? $req->post($url, $json) : $req->post($url);
        } else {
            $res = $req->get($url);
        }

        if ($res->failed()) {
            throw new RuntimeException('SMS provider returned HTTP '.$res->status().': '.mb_substr($res->body(), 0, 200));
        }
    }

    private function fill(string $tpl, array $values, callable $escape): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => array_key_exists($m[1], $values) ? $escape((string) $values[$m[1]]) : $m[0], $tpl);
    }
}
