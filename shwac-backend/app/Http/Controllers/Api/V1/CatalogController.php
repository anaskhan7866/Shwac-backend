<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Http\JsonResponse;

/** Public service catalog for the website / customer app booking flow. */
class CatalogController extends Controller
{
    public function index(): JsonResponse
    {
        $cats = ServiceCategory::where('is_active', true)->orderBy('sort_order')
            ->with(['services' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'icon' => $c->icon,
                'services' => $c->services->map(fn (Service $s) => $this->present($s)),
            ]);

        return response()->json([
            'categories' => $cats,
            'time_slots' => Setting::get('booking.time_slots', ['09:00-11:00', '11:00-13:00', '14:00-16:00', '16:00-18:00']),
        ]);
    }

    public function show(Service $service): JsonResponse
    {
        abort_unless($service->is_active, 404);

        return response()->json($this->present($service) + ['form_schema' => $service->form_schema ?? []]);
    }

    private function present(Service $s): array
    {
        $base = Money::toPaise($s->base_price);
        $tax = Money::percentOf($base, $s->tax_rate);

        return [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'description' => $s->description,
            'price' => $s->base_price, 'tax_rate' => $s->tax_rate,
            'price_incl_tax' => Money::fromPaise($base + $tax),
            'duration_minutes' => $s->duration_minutes,
            'has_form' => ! empty($s->form_schema),
        ];
    }
}
