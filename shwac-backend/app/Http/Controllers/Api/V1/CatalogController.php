<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServiceArea;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public service catalog for the website / customer app booking flow. */
class CatalogController extends Controller
{
    /**
     * Services offered at the customer's PIN code (admin "Service areas").
     * - logged-in customer: PIN code of their default address, else the one given at sign-up; none, or an area not set up → no services
     * - ?pincode=XXXXXX: that PIN code
     * - ?all=1, or an anonymous visitor with no PIN code: the full list (e.g. appliance types); booking still checks the area
     */
    public function index(Request $request): JsonResponse
    {
        $location = null;
        $area = null;
        if (! $request->boolean('all')) {
            $user = $request->user('sanctum');
            $customer = $user && $user->isCustomer() ? Customer::where('user_id', $user->id)->first() : null;
            $pincode = $request->filled('pincode') ? (string) $request->query('pincode')
                : $customer?->servicePincode();
            if ($pincode !== null || $customer) {
                $area = ServiceArea::forPincode($pincode);
                $location = ['pincode' => $pincode, 'area' => $area?->name, 'city' => $area?->city, 'serviceable' => (bool) $area];
            }
        }
        $offered = $area && ! $area->all_services ? $area->services()->pluck('services.id')->all() : null;

        $cats = ServiceCategory::where('is_active', true)->orderBy('sort_order')
            ->with(['services' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->get()
            ->map(function ($c) use ($location, $area, $offered) {
                $services = $c->services;
                if ($location) {
                    $services = ! $area ? collect() : ($offered === null ? $services : $services->whereIn('id', $offered));
                }

                return [
                    'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'icon' => $c->icon,
                    'services' => $services->values()->map(fn (Service $s) => $this->present($s)),
                ];
            })
            ->when($location, fn ($all) => $all->filter(fn ($c) => count($c['services']) > 0)->values());

        return response()->json([
            'categories' => $cats,
            'location' => $location,
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
