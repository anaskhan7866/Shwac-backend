<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Models\SlaPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * System configuration: company profile & branding, tax defaults, SLA policies,
 * WhatsApp notification templates and the message log.
 */
class SettingsController extends Controller
{
    /** Keys editable through the API (anything else is rejected). */
    private const KEYS = [
        'company' => ['array'],           // name, legal_name, gstin, pan, address, phone, email, website
        'branding' => ['array'],          // logo_url, primary_color, invoice_footer, terms
        'tax' => ['array'],               // default_rate, default_sac, prices_include_tax
        'complaints' => ['array'],        // auto_assign (bool), categories (list)
        'booking' => ['array'],           // time_slots (list), lead_days, max_per_slot
    ];

    public function index(): JsonResponse
    {
        return response()->json([
            'settings' => collect(self::KEYS)->keys()->mapWithKeys(fn ($k) => [$k => Setting::get($k, [])]),
            'sla_policies' => SlaPolicy::orderByRaw("case priority when 'critical' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")->get(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(collect(self::KEYS)->map(fn ($r) => ['sometimes', ...$r])->all());
        foreach ($data as $key => $value) {
            Setting::put($key, array_replace(Setting::get($key, []) ?: [], $value));
        }

        return $this->index();
    }

    public function updateSla(Request $request): JsonResponse
    {
        $data = $request->validate([
            'policies' => ['required', 'array'],
            'policies.*.priority' => ['required', Rule::in(['low', 'normal', 'high', 'critical'])],
            'policies.*.response_minutes' => ['required', 'integer', 'min:5'],
            'policies.*.resolution_minutes' => ['required', 'integer', 'min:15', 'gte:policies.*.response_minutes'],
        ]);
        foreach ($data['policies'] as $p) {
            SlaPolicy::updateOrCreate(['priority' => $p['priority']], $p);
        }

        return $this->index();
    }

    public function templates(): JsonResponse
    {
        return response()->json(NotificationTemplate::orderBy('event')->get());
    }

    public function upsertTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'max:50'],
            'channel' => ['sometimes', Rule::in(['whatsapp', 'sms'])],
            'provider_template' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:1024'],
            'language' => ['sometimes', 'string', 'max:10'],
            'variables' => ['sometimes', 'array'],
            'variables.*' => ['string', 'max:40'],
            'preview' => ['nullable', 'string', 'max:1024'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $exists = NotificationTemplate::where('event', $data['event'])->where('channel', $data['channel'] ?? 'whatsapp')->exists();
        if (! $exists) {
            $data += ['variables' => []];
        }
        $tpl = NotificationTemplate::updateOrCreate(
            ['event' => $data['event'], 'channel' => $data['channel'] ?? 'whatsapp'],
            $data,
        );

        return response()->json($tpl);
    }

    public function notificationLogs(Request $request): JsonResponse
    {
        $q = NotificationLog::query()->latest('id');
        foreach (['status', 'event', 'to'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->input($f));
            }
        }

        return response()->json($q->paginate($this->perPage($request)));
    }
}
