<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerProduct;
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
            // Alt mobile / WhatsApp given on the call form is kept on the customer.
            if (! empty($data['whatsapp']) && $data['whatsapp'] !== $customer->whatsapp) {
                $customer->update(['whatsapp' => $data['whatsapp']]);
            }
            $product = $this->rememberProduct($customer, $service, $formData, empty($data['customer_product_id']) ? null : (int) $data['customer_product_id'], $address->id);
            $booking = Booking::create([
                'number' => $this->numbers->for('booking'),
                'branch_id' => $customer->branch_id,
                'customer_id' => $customer->id,
                'customer_address_id' => $address->id,
                'service_id' => $service->id,
                'customer_product_id' => $product?->id ?? ($data['customer_product_id'] ?? null),
                'scheduled_date' => $data['scheduled_date'],
                'time_slot' => $data['time_slot'] ?? null,
                'source' => $data['source'] ?? 'web',
                'form_data' => $formData,
                'priority' => $data['priority'] ?? 'medium',
                // Office can set the charge for this call; otherwise the service's price.
                'quoted_amount' => $data['quoted_amount'] ?? $service->base_price,
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

    /** Answers that describe this call, not the appliance; they stay on the booking only. */
    private const CALL_KEYS = ['service_type', 'defect_code', 'solar_service_type', 'job_type'];

    /**
     * Save the appliance details from this booking on the customer's appliance record,
     * so the next call for it is pre-filled. Uses the picked appliance, else the one for
     * this service (same serial if given), else creates one. Empty answers never wipe saved ones.
     */
    private function rememberProduct(Customer $customer, Service $service, array $formData, ?int $productId, int $addressId): ?CustomerProduct
    {
        $details = array_filter(array_diff_key($formData, array_flip(self::CALL_KEYS)), fn ($v) => $v !== null && $v !== '' && $v !== []);
        $warranty = isset($details['warranty_status']) ? mb_substr((string) $details['warranty_status'], 0, 20) : null;
        $brand = isset($details['brand']) ? mb_substr((string) $details['brand'], 0, 60) : null;
        $serial = isset($details['model_serial']) ? mb_substr(trim((string) $details['model_serial']), 0, 60) : null;
        unset($details['warranty_status']);

        $product = $productId ? $customer->products()->find($productId) : null;
        if (! $product) {
            $mine = fn () => $customer->products()->where('service_id', $service->id)->latest('updated_at');
            $product = $serial
                ? ($mine()->where('serial_no', $serial)->first() ?? $mine()->whereNull('serial_no')->first())
                : $mine()->first();
        }
        if (! $product && ! $details && ! $warranty) {
            return null; // nothing to remember yet
        }

        $attrs = array_filter([
            'brand' => $brand, 'serial_no' => $serial, 'warranty_status' => $warranty,
        ], fn ($v) => $v !== null && $v !== '');
        if ($product) {
            $product->update($attrs + [
                'service_id' => $product->service_id ?? $service->id,
                'details' => array_merge($product->details ?? [], $details),
            ]);

            return $product;
        }

        return $customer->products()->create($attrs + [
            'service_id' => $service->id,
            'customer_address_id' => $addressId,
            'category' => $service->category?->name,
            'product_name' => $service->name,
            'details' => $details,
        ]);
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
