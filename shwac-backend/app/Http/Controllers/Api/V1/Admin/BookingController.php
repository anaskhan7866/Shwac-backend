<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\FiltersCalls;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\Operations\BookingService;
use App\Services\Operations\JobService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    use FiltersCalls;

    public function index(Request $request): JsonResponse
    {
        $q = Booking::query()->visibleTo($request->user())
            ->with(['customer:id,name,phone', 'service:id,name', 'jobs:id,booking_id,number,status,technician_id']);

        foreach (['status', 'branch_id', 'customer_id'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->input($f));
            }
        }
        // service / category / priority / technician / dates / place / service answers
        $this->applyCallFilters($q, $request);
        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('number', $s)->orWhereHas('customer', fn ($c) => $c->where('phone', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")));
        }

        return response()->json($q->latest('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request, BookingService $bookings, JobService $jobs): JsonResponse
    {
        // V2 "Add Call": a caller who is not a customer yet is created on the same form.
        $isNew = ! $request->filled('customer_id') && $request->has('new_customer');
        $newRules = ! $isNew ? [] : [
            'new_customer' => ['required', 'array'],
            'new_customer.name' => ['required', 'string', 'max:120'],
            'new_customer.phone' => ['required', 'regex:/^[6-9]\d{9}$/', Rule::unique('customers', 'phone')],
            'new_customer.email' => ['nullable', 'email', 'max:120'],
            'new_customer.branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'new_customer.address' => ['required', 'array'],
            ...CustomerController::addressRules('new_customer.address.'),
        ];
        $data = $request->validate($newRules + [
            'customer_id' => [$isNew ? 'nullable' : 'required', 'exists:customers,id'],
            'service_id' => ['required', 'exists:services,id'],
            'customer_address_id' => ['nullable', 'integer'],
            'customer_product_id' => ['nullable', 'integer'],
            'scheduled_date' => ['required', 'date', 'after_or_equal:today'],
            'time_slot' => ['nullable', 'string', 'max:20'],
            'source' => ['nullable', Rule::in(['admin', 'phone', 'whatsapp', 'walk_in'])],
            'form_data' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'priority' => ['nullable', Rule::in(Booking::PRIORITIES)],
            'quoted_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'whatsapp' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'technician_id' => ['nullable', 'integer', 'exists:users,id'],
        ], ['new_customer.phone.unique' => 'A customer with this mobile already exists. Search and pick them instead.']);
        $user = $request->user();

        if ($isNew) {
            abort_unless($user->hasPermission('customers.manage'), 403, 'You cannot add customers.');
            $branchId = $user->canSeeAllBranches() ? ($data['new_customer']['branch_id'] ?? $user->branch_id) : $user->branch_id;
            abort_unless($branchId, 422, 'Choose the branch for the new customer.');
            $customer = null;
        } else {
            $customer = Customer::findOrFail($data['customer_id']);
            $this->ensureVisible($customer, $user);
            $branchId = $customer->branch_id;
        }

        // Optional technician: checked before anything is saved so a bad pick leaves no half-made booking.
        $tech = isset($data['technician_id']) ? User::findOrFail($data['technician_id']) : null;
        if ($tech) {
            abort_unless($user->hasPermission('jobs.manage'), 403, 'You cannot assign technicians.');
            abort_unless($tech->isTechnician() && $tech->is_active && ! $tech->removed_at, 422, 'Selected user is not an active technician.');
            abort_unless((int) $tech->branch_id === (int) $branchId, 422, 'This technician belongs to another branch.');
        }

        // Customer (if new), booking, job and assignment are saved together or not at all.
        $booking = DB::transaction(function () use ($bookings, $customer, $data, $request, $jobs, $tech, $isNew, $branchId) {
            if ($isNew) {
                $nc = $data['new_customer'];
                $customer = app(\App\Services\CustomerService::class)->create(array_filter([
                    'name' => $nc['name'], 'phone' => $nc['phone'], 'email' => $nc['email'] ?? null, 'whatsapp' => $data['whatsapp'] ?? null,
                ], fn ($v) => $v !== null) + ['address' => $nc['address']], (int) $branchId);
            }
            $booking = $bookings->create($customer, Service::findOrFail($data['service_id']), $data + ['source' => $data['source'] ?? 'admin'], $request->user());
            if ($tech && ($job = $booking->jobs()->first())) {
                $jobs->assign($job, $tech, $request->user());
            }

            return $booking;
        });

        return response()->json($booking->load('jobs.technician:id,name,phone', 'service:id,name'), 201);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->ensureVisible($booking, $request->user());

        return response()->json($booking->load(['customer', 'address', 'service', 'customerProduct', 'jobs.technician:id,name,phone', 'jobs.statusLogs']));
    }

    public function update(Request $request, Booking $booking): JsonResponse
    {
        $this->ensureVisible($booking, $request->user());
        $data = $request->validate([
            'scheduled_date' => ['sometimes', 'date'],
            'time_slot' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['pending', 'confirmed', 'scheduled'])],
            'priority' => ['sometimes', Rule::in(Booking::PRIORITIES)],
            'quoted_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999'],
        ]);
        abort_if(in_array($booking->status, ['completed', 'cancelled'], true), 422, 'Booking is closed.');
        $booking->update($data);
        if (isset($data['scheduled_date']) || array_key_exists('time_slot', $data)) {
            $booking->jobs()->whereNotIn('status', ['completed', 'cancelled'])
                ->update(array_intersect_key($data, array_flip(['scheduled_date', 'time_slot'])));
        }

        return response()->json($booking->refresh());
    }

    public function cancel(Request $request, Booking $booking, BookingService $bookings): JsonResponse
    {
        $this->ensureVisible($booking, $request->user());
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json($bookings->cancel($booking, $data['reason'], $request->user()));
    }
}
