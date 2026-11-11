<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\ServiceArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Admin "Service areas": PIN codes served and the services offered in each. */
class ServiceAreaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = ServiceArea::query()->with('services:id,name,code,service_category_id')->orderBy('pincode');
        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('pincode', 'like', "{$s}%")->orWhere('name', 'like', "%{$s}%")->orWhere('city', 'like', "%{$s}%"));
        }
        $areas = $q->get();

        // Customers per PIN code: their saved addresses, plus the PIN code given at sign-up by those with no address yet.
        $pins = fn () => DB::query()->fromSub(
            CustomerAddress::query()->select('customer_id', 'pincode', 'city')->union(
                Customer::query()->whereNotNull('pincode')->whereDoesntHave('addresses')->select('id as customer_id', 'pincode', 'city')
            ), 'p');

        // How many customers live in each PIN code, so the office sees who is affected.
        $customers = $pins()->whereIn('pincode', $areas->pluck('pincode'))
            ->selectRaw('pincode, count(distinct customer_id) as total')->groupBy('pincode')->pluck('total', 'pincode');

        // PIN codes where customers signed up but no area is set up yet: they see no services.
        $uncovered = $pins()->whereNotIn('pincode', ServiceArea::query()->pluck('pincode'))
            ->selectRaw('pincode, max(city) as city, count(distinct customer_id) as total')->groupBy('pincode')
            ->orderByDesc('total')->limit(50)->get();

        return response()->json([
            'areas' => $areas->map(fn (ServiceArea $a) => $a->toArray() + ['customers' => (int) ($customers[$a->pincode] ?? 0)]),
            'uncovered' => $uncovered,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $area = DB::transaction(function () use ($data) {
            $area = ServiceArea::create(collect($data)->except('service_ids')->all());
            $area->services()->sync($data['service_ids'] ?? []);

            return $area;
        });

        return response()->json($area->load('services:id,name,code,service_category_id'), 201);
    }

    public function update(Request $request, ServiceArea $area): JsonResponse
    {
        $data = $request->validate($this->rules($area->id, true));
        DB::transaction(function () use ($area, $data) {
            $area->update(collect($data)->except('service_ids')->all());
            if (array_key_exists('service_ids', $data)) {
                $area->services()->sync($data['service_ids'] ?? []);
            }
        });

        return response()->json($area->fresh()->load('services:id,name,code,service_category_id'));
    }

    public function destroy(ServiceArea $area): JsonResponse
    {
        $area->delete();

        return response()->json(['message' => 'Service area removed.']);
    }

    private function rules(?int $id = null, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return [
            'pincode' => [$r, 'digits:6', Rule::unique('service_areas', 'pincode')->ignore($id)],
            'name' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'all_services' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'service_ids' => ['sometimes', 'nullable', 'array'],
            'service_ids.*' => ['integer', 'exists:services,id'],
        ];
    }
}
