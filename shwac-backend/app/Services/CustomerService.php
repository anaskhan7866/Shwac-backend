<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/** One place that creates a customer with their first (default) address. */
class CustomerService
{
    public function __construct(private NumberGenerator $numbers) {}

    /**
     * @param  array  $data  customer fields + 'address' => [line1, city, state, state_code, pincode, ...]
     */
    public function create(array $data, int $branchId): Customer
    {
        return DB::transaction(function () use ($data, $branchId) {
            $customer = Customer::create(collect($data)->except(['address', 'branch_id'])->all() + [
                'code' => $this->numbers->for('customer'),
                'branch_id' => $branchId,
                'whatsapp' => $data['whatsapp'] ?? $data['phone'],
            ]);
            $customer->addresses()->create($data['address'] + ['is_default' => true]);

            return $customer;
        });
    }
}
