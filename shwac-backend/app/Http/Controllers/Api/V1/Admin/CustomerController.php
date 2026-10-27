<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerProduct;
use App\Services\NumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Customer::query()->visibleTo($request->user())->with('defaultAddress')
            ->withCount(['complaints as open_complaints' => fn ($q) => $q->open()]);

        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")->orWhere('code', $s));
        }
        if ($request->filled('branch_id')) {
            $q->where('branch_id', $request->integer('branch_id'));
        }

        return response()->json($q->latest('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request, NumberGenerator $numbers): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'address' => ['required', 'array'],
            ...$this->addressRules('address.'),
        ]);
        $user = $request->user();
        $branchId = $user->canSeeAllBranches() ? ($data['branch_id'] ?? $user->branch_id) : $user->branch_id;
        abort_unless($branchId, 422, 'branch_id is required.');

        $customer = DB::transaction(function () use ($data, $branchId, $numbers) {
            $c = Customer::create(collect($data)->except(['address', 'branch_id'])->all() + [
                'code' => $numbers->for('customer'),
                'branch_id' => $branchId,
                'whatsapp' => $data['whatsapp'] ?? $data['phone'],
            ]);
            $c->addresses()->create($data['address'] + ['is_default' => true]);

            return $c;
        });

        return response()->json($customer->load('addresses'), 201);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->ensureVisible($customer, $request->user());

        return response()->json($customer->load([
            'addresses', 'products', 'branch:id,name,code',
            'bookings' => fn ($q) => $q->latest('id')->limit(10)->with('service:id,name'),
            'complaints' => fn ($q) => $q->latest('id')->limit(10),
            'invoices' => fn ($q) => $q->latest('id')->limit(10),
        ]));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->ensureVisible($customer, $request->user());
        $data = $request->validate($this->rules($customer->id, partial: true));
        if (isset($data['branch_id']) && ! $request->user()->canSeeAllBranches()) {
            unset($data['branch_id']);
        }
        $customer->update($data);

        return response()->json($customer);
    }

    public function storeAddress(Request $request, Customer $customer): JsonResponse
    {
        $this->ensureVisible($customer, $request->user());
        $data = $request->validate($this->addressRules());
        $address = DB::transaction(function () use ($customer, $data) {
            if (! empty($data['is_default'])) {
                $customer->addresses()->update(['is_default' => false]);
            }

            return $customer->addresses()->create($data);
        });

        return response()->json($address, 201);
    }

    public function updateAddress(Request $request, Customer $customer, CustomerAddress $address): JsonResponse
    {
        $this->ensureVisible($customer, $request->user());
        abort_unless($address->customer_id === $customer->id, 404);
        $data = $request->validate(collect($this->addressRules())->map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r))->all());
        $address->update($data);

        return response()->json($address);
    }

    public function storeProduct(Request $request, Customer $customer): JsonResponse
    {
        $this->ensureVisible($customer, $request->user());
        $data = $request->validate([
            'customer_address_id' => ['nullable', Rule::exists('customer_addresses', 'id')->where('customer_id', $customer->id)],
            'category' => ['nullable', 'string', 'max:60'],
            'product_name' => ['required', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'serial_no' => ['nullable', 'string', 'max:60'],
            'purchase_date' => ['nullable', 'date'],
            'installed_on' => ['nullable', 'date'],
            'warranty_until' => ['nullable', 'date'],
        ]);

        return response()->json($customer->products()->create($data), 201);
    }

    public function updateProduct(Request $request, Customer $customer, CustomerProduct $product): JsonResponse
    {
        $this->ensureVisible($customer, $request->user());
        abort_unless($product->customer_id === $customer->id, 404);
        $product->update($request->validate([
            'product_name' => ['sometimes', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'serial_no' => ['nullable', 'string', 'max:60'],
            'purchase_date' => ['nullable', 'date'],
            'installed_on' => ['nullable', 'date'],
            'warranty_until' => ['nullable', 'date'],
        ]));

        return response()->json($product);
    }

    private function rules(?int $ignoreId = null, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'branch_id' => ['nullable', 'exists:branches,id'],
            'name' => [$req, 'string', 'max:120'],
            'phone' => [$req, 'regex:/^[6-9]\d{9}$/', Rule::unique('customers', 'phone')->ignore($ignoreId)],
            'whatsapp' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'email' => ['nullable', 'email', 'max:120'],
            'gstin' => ['nullable', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'whatsapp_opt_in' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function addressRules(string $prefix = ''): array
    {
        return [
            $prefix.'label' => ['nullable', 'string', 'max:30'],
            $prefix.'line1' => ['required', 'string', 'max:255'],
            $prefix.'line2' => ['nullable', 'string', 'max:255'],
            $prefix.'landmark' => ['nullable', 'string', 'max:120'],
            $prefix.'city' => ['required', 'string', 'max:80'],
            $prefix.'district' => ['nullable', 'string', 'max:80'],
            $prefix.'state' => ['required', 'string', 'max:60'],
            $prefix.'state_code' => ['required', 'digits:2'],
            $prefix.'pincode' => ['required', 'digits:6'],
            $prefix.'lat' => ['nullable', 'numeric', 'between:-90,90'],
            $prefix.'lng' => ['nullable', 'numeric', 'between:-180,180'],
            $prefix.'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
