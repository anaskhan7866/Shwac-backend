<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\Messaging\Notifier;
use App\Services\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private NumberGenerator $numbers,
        private JobService $jobs,
        private Notifier $notifier,
    ) {}

    /**
     * Create a booking from the dynamic service form and open a service job for it.
     */
    public function create(Customer $customer, Service $service, array $data, ?User $by = null): Booking
    {
        if (! $service->is_active) {
            throw ValidationException::withMessages(['service_id' => 'This service is not available for booking.']);
        }

        $address = $customer->addresses()->find($data['customer_address_id'] ?? null) ?? $customer->defaultAddress;
        if (! $address) {
            throw ValidationException::withMessages(['customer_address_id' => 'Add an address before booking.']);
        }
        if (! empty($data['customer_product_id']) && ! $customer->products()->whereKey($data['customer_product_id'])->exists()) {
            throw ValidationException::withMessages(['customer_product_id' => 'Product does not belong to this customer.']);
        }

        $formData = $this->validateDynamicForm($service, $data['form_data'] ?? []);

        $booking = DB::transaction(function () use ($customer, $service, $data, $by, $address, $formData) {
            $booking = Booking::create([
                'number' => $this->numbers->for('booking'),
                'branch_id' => $customer->branch_id,
                'customer_id' => $customer->id,
                'customer_address_id' => $address->id,
                'service_id' => $service->id,
                'customer_product_id' => $data['customer_product_id'] ?? null,
                'scheduled_date' => $data['scheduled_date'],
                'time_slot' => $data['time_slot'] ?? null,
                'source' => $data['source'] ?? 'web',
                'form_data' => $formData,
                'quoted_amount' => $service->base_price,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
                'created_by' => $by?->id,
            ]);
            $this->jobs->createFromBooking($booking, $by);

            return $booking->refresh();
        });

        $log = $this->notifier->notifyCustomer($customer, 'booking_confirmed', [
            'booking_number' => $booking->number,
            'service_name' => $service->name,
            'scheduled_date' => $booking->scheduled_date->format('d M Y'),
            'time_slot' => $booking->time_slot ?? 'Any time',
        ], $booking);

        return $booking->setAttribute('whatsapp', $log?->share());
    }

    public function cancel(Booking $booking, string $reason, ?User $by = null): Booking
    {
        if (in_array($booking->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => "Booking is already {$booking->status}."]);
        }

        DB::transaction(function () use ($booking, $reason, $by) {
            $booking->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            foreach ($booking->jobs()->whereNotIn('status', ['completed', 'cancelled', 'in_progress'])->get() as $job) {
                $job->update(['status' => 'cancelled']);
                $job->statusLogs()->create(['user_id' => $by?->id, 'from_status' => $job->getOriginal('status'), 'to_status' => 'cancelled', 'note' => 'Booking cancelled: '.$reason, 'created_at' => now()]);
            }
        });

        return $booking->refresh();
    }

    /**
     * Validate answers against the service's form_schema.
     * Schema field: {key, label, type: text|textarea|number|select|multiselect|date|boolean|phone, required?, options?, min?, max?}
     */
    public function validateDynamicForm(Service $service, array $input): array
    {
        $fields = $service->form_schema ?? [];
        $rules = [];
        foreach ($fields as $f) {
            $key = $f['key'];
            $r = [! empty($f['required']) ? 'required' : 'nullable'];
            switch ($f['type'] ?? 'text') {
                case 'number':
                    $r[] = 'numeric';
                    if (isset($f['min'])) { $r[] = 'min:'.$f['min']; }
                    if (isset($f['max'])) { $r[] = 'max:'.$f['max']; }
                    break;
                case 'select':
                    $r[] = Rule::in($f['options'] ?? []);
                    break;
                case 'multiselect':
                    $r[] = 'array';
                    $rules[$key.'.*'] = [Rule::in($f['options'] ?? [])];
                    break;
                case 'date':
                    $r[] = 'date';
                    break;
                case 'boolean':
                    $r[] = 'boolean';
                    break;
                case 'phone':
                    $r[] = 'regex:/^[6-9]\d{9}$/';
                    break;
                default:
                    $r[] = 'string';
                    $r[] = 'max:'.(($f['type'] ?? '') === 'textarea' ? 2000 : 255);
            }
            $rules[$key] = $r;
        }

        $attributes = collect($fields)->mapWithKeys(fn ($f) => [$f['key'] => $f['label'] ?? $f['key']])->all();
        $validated = Validator::make($input, $rules, [], $attributes)->validate();

        // keep only declared keys
        return array_intersect_key($validated, $rules);
    }
}
