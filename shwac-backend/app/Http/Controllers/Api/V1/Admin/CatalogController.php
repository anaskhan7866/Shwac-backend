<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Billing\GstCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function categories(): JsonResponse
    {
        return response()->json(ServiceCategory::withCount('services')->orderBy('sort_order')->get());
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['nullable', 'alpha_dash', 'unique:service_categories,slug'],
            'icon' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        return response()->json(ServiceCategory::create($data + ['slug' => Str::slug($data['name'])]), 201);
    }

    public function updateCategory(Request $request, ServiceCategory $category): JsonResponse
    {
        $category->update($request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'icon' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return response()->json($category);
    }

    public function services(Request $request): JsonResponse
    {
        $q = Service::with('category:id,name')->orderBy('sort_order');
        if ($request->filled('service_category_id')) {
            $q->where('service_category_id', $request->integer('service_category_id'));
        }

        return response()->json($q->get());
    }

    public function storeService(Request $request): JsonResponse
    {
        return response()->json(Service::create($request->validate($this->serviceRules())), 201);
    }

    public function updateService(Request $request, Service $service): JsonResponse
    {
        $service->update($request->validate($this->serviceRules($service->id, true)));

        return response()->json($service);
    }

    private function serviceRules(?int $id = null, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return [
            'service_category_id' => [$r, 'exists:service_categories,id'],
            'name' => [$r, 'string', 'max:120'],
            'code' => [$r, 'alpha_dash', 'max:20', Rule::unique('services', 'code')->ignore($id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'base_price' => [$r, 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', Rule::in(GstCalculator::ALLOWED_RATES)],
            'sac_code' => ['sometimes', 'digits_between:4,8'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
            // Dynamic booking form definition
            'form_schema' => ['nullable', 'array', 'max:30'],
            'form_schema.*.key' => ['required', 'alpha_dash', 'max:40', 'distinct'],
            'form_schema.*.label' => ['required', 'string', 'max:120'],
            'form_schema.*.type' => ['required', Rule::in(['text', 'textarea', 'number', 'select', 'multiselect', 'date', 'boolean', 'phone'])],
            'form_schema.*.required' => ['sometimes', 'boolean'],
            'form_schema.*.options' => ['required_if:form_schema.*.type,select,multiselect', 'array'],
            'form_schema.*.options.*' => ['string', 'max:80'],
            'form_schema.*.min' => ['nullable', 'numeric'],
            'form_schema.*.max' => ['nullable', 'numeric'],
            'form_schema.*.help' => ['nullable', 'string', 'max:255'],
        ];
    }
}
