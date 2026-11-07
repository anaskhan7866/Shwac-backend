<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\CustomerService;
use App\Services\Operations\BookingService;
use App\Services\Operations\ComplaintService;
use App\Services\Operations\JobService;
use App\Services\Payments\PaymentService;
use Illuminate\Database\Seeder;

/**
 * Dummy business data for a client demo: customers (fake names, 900001xxxx numbers),
 * calls across the V2 catalog in every state (waiting, assigned, in progress, completed
 * and paid), one complaint. Nothing is sent to anyone: WhatsApp stays in "link" mode.
 */
class DemoBusinessSeeder extends Seeder
{
    public function run(): void
    {
        config(['services.whatsapp.mode' => 'link']); // never send real messages to dummy numbers

        $branch = Branch::where('is_active', true)->orderBy('id')->firstOrFail();
        $techs = User::where('user_type', User::TYPE_TECHNICIAN)->where('branch_id', $branch->id)
            ->where('is_active', true)->whereNull('removed_at')->orderBy('id')->get();
        $tech = fn (int $i) => $techs->isEmpty() ? null : $techs[$i % $techs->count()];

        $people = [
            ['Rahul Verma', '9000010001', '12, Civil Lines', 'Meerut', '250001', 'Near Clock Tower'],
            ['Priya Gupta', '9000010002', '45, Shastri Nagar', 'Meerut', '250004', 'Opp. City Mall'],
            ['Amit Patel', '9000010003', 'B-402, Green Villa', 'Ghaziabad', '201001', 'Near Metro Station'],
            ['Neha Singh', '9000010004', '7, Rajendra Nagar', 'Noida', '201301', null],
            ['Vikram Rao', '9000010005', '21, Gandhi Road', 'Meerut', '250002', 'Behind Post Office'],
            ['Sunita Sharma', '9000010006', '3, Model Town', 'Ghaziabad', '201002', null],
        ];
        $customers = [];
        foreach ($people as [$name, $phone, $line1, $city, $pin, $landmark]) {
            $customers[] = Customer::where('phone', $phone)->first() ?? app(CustomerService::class)->create([
                'name' => $name, 'phone' => $phone,
                'address' => array_filter([
                    'label' => 'Home', 'line1' => $line1, 'landmark' => $landmark, 'city' => $city,
                    'state' => $branch->state ?: 'Uttar Pradesh', 'state_code' => $branch->state_code ?: '09', 'pincode' => $pin,
                ], fn ($v) => $v !== null),
            ], $branch->id);
        }

        $svc = fn (string $code) => Service::where('code', $code)->firstOrFail();
        $day = fn (int $plus) => today()->addDays($plus)->toDateString();
        $book = fn (Customer $c, string $code, int $plus, array $form, array $extra = []) => app(BookingService::class)
            ->create($c, $svc($code), $extra + ['scheduled_date' => $day($plus), 'source' => 'phone', 'form_data' => $form]);

        $calls = [
            // [customer, service, days from today, priority, slot, form answers, technician index or null, progress]
            [0, 'HA-AC', 0, 'high', '09:00-11:00', ['service_type' => 'Gas Filling', 'warranty_status' => 'AMC', 'defect_code' => 'Not Cooling', 'brand' => 'LG',
                'model_serial' => 'LG-AC15-1001', 'tonnage' => '1.5 Ton', 'gas_type' => 'R32', 'inverter' => 'Inverter', 'ac_type' => 'Split'], 0, 'in_progress'],
            [1, 'HA-RO', 0, 'medium', '11:00-13:00', ['service_type' => 'Filter Replacement', 'warranty_status' => 'In-Warranty', 'defect_code' => 'Low Flow', 'brand' => 'Kent',
                'model_serial' => 'KENT-GP-2201', 'filter_replacement' => ['Sediment', 'Carbon'], 'tds' => 350, 'tank_capacity' => 8], 1, 'completed'],
            [2, 'SOL-PV', 1, 'medium', null, ['solar_service_type' => 'Panel Cleaning', 'system_capacity' => '5 KW', 'system_type' => 'On-Grid', 'installation' => 'Rooftop',
                'inverter_brand_serial' => 'Growatt SN-7781', 'net_meter' => 'Net Meter', 'panel_count' => 12, 'cleaning_frequency' => 'Monthly', 'subsidy_id' => 'SUB-2024-0091'], null, 'pending'],
            [3, 'HA-WM', 1, 'low', '14:00-16:00', ['service_type' => 'Repair', 'warranty_status' => 'Out of Warranty', 'defect_code' => 'Not Spinning', 'brand' => 'Samsung',
                'capacity_kg' => '7 Kg', 'load_type' => 'Front Load', 'wm_type' => 'Fully Automatic'], 0, 'assigned'],
            [4, 'HA-FRIDGE', 2, 'medium', null, ['service_type' => 'Gas Filling', 'warranty_status' => 'Out of Warranty', 'defect_code' => 'Not Cooling', 'brand' => 'Whirlpool',
                'capacity_litres' => 260, 'door_type' => 'Double Door', 'gas_type' => 'R600a'], null, 'pending'],
            [5, 'SOL-SWH', 2, 'high', '09:00-11:00', ['solar_service_type' => 'Acid Wash / Descaling', 'system_capacity' => '200 LPD', 'system_type' => 'ETC (Glass Tube)',
                'installation' => 'Rooftop', 'hard_water' => 'Yes', 'tank_material' => 'Stainless Steel'], 1, 'assigned'],
        ];

        $jobs = app(JobService::class);
        foreach ($calls as [$ci, $code, $plus, $priority, $slot, $form, $ti, $progress]) {
            $booking = $book($customers[$ci], $code, $plus, $form, ['priority' => $priority, 'time_slot' => $slot]);
            $job = $booking->jobs()->first();
            $t = $ti === null ? null : $tech($ti);
            if (! $job || ! $t || $progress === 'pending') {
                continue;
            }
            $jobs->assign($job, $t);
            if (in_array($progress, ['in_progress', 'completed'], true)) {
                foreach (['accepted', 'en_route', 'in_progress'] as $step) {
                    $job = $jobs->transition($job->refresh(), $step, $t);
                }
            }
            if ($progress === 'completed') {
                $this->billAndPay($job->refresh(), $booking, $t);
                $jobs->transition($job->refresh(), 'completed', $t, 'Sediment and carbon filters changed. TDS after service: 60.');
            }
        }

        // One open complaint so the complaints screen is not empty.
        app(ComplaintService::class)->create($customers[0], [
            'subject' => 'AC water dripping after service', 'description' => 'Water drips from the indoor unit since yesterday.',
            'category' => 'leakage', 'priority' => 'normal', 'channel' => 'phone',
        ], null, false);
    }

    private function billAndPay($job, $booking, User $tech): void
    {
        $service = $booking->service;
        $invoices = app(InvoiceService::class);
        $draft = $invoices->createDraft($job->customer, $job->branch, [[
            'item_type' => 'service', 'item_id' => $service->id, 'description' => $service->name, 'hsn_sac' => $service->sac_code,
            'quantity' => 1, 'unit' => 'NOS', 'unit_price' => $booking->quoted_amount, 'discount' => 0, 'tax_rate' => $service->tax_rate,
        ]], $job->address, $job, $tech);
        $invoice = $invoices->issue($draft, false, $tech);
        app(PaymentService::class)->recordManual($invoice->refresh(), $invoice->total, 'cash', null, $tech);
    }
}
