<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Service;
use App\Models\ServiceJob;
use App\Services\Operations\BookingService;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Client spec items: new customer on the Add Call form, mandatory solar System Type,
 * and filters for multi-choice and number answers. Includes edge cases.
 */
class AddCallSpecTest extends TestCase
{
    private Service $ac;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        (new ServiceCatalogSeeder)->run();
        $this->ac = Service::where('code', 'HA-AC')->firstOrFail();
    }

    private function newCustomerBody(array $nc = [], array $extra = []): array
    {
        return $extra + [
            'service_id' => $this->ac->id, 'scheduled_date' => today()->toDateString(),
            'form_data' => ['service_type' => 'Repair', 'warranty_status' => 'AMC', 'defect_code' => 'Noise'],
            'new_customer' => array_replace_recursive([
                'name' => 'Pritam Sharma', 'phone' => '9832654152',
                'address' => ['line1' => 'B-402, Green Villa', 'landmark' => 'Nr. City Mall', 'city' => 'Meerut', 'state' => 'Uttar Pradesh', 'state_code' => '09', 'pincode' => '250001'],
            ], $nc),
        ];
    }

    // ---- 1. New customer on the Add Call form ---------------------------------------

    #[Test]
    public function a_new_caller_is_created_with_address_and_booked_in_one_go(): void
    {
        $tech = $this->technician();
        $this->actingAsUser($this->staff('operator'));

        $id = $this->postJson('/api/v1/admin/bookings', $this->newCustomerBody([], ['technician_id' => $tech->id, 'whatsapp' => '9876543210', 'priority' => 'high']))
            ->assertCreated()->json('id');

        $c = Customer::where('phone', '9832654152')->sole();
        $this->assertSame('Pritam Sharma', $c->name);
        $this->assertSame($this->branch->id, (int) $c->branch_id);
        $this->assertSame('9876543210', $c->whatsapp);
        $a = $c->addresses()->sole();
        $this->assertTrue((bool) $a->is_default);
        $this->assertSame('Nr. City Mall', $a->landmark);
        $b = Booking::findOrFail($id);
        $this->assertSame($c->id, (int) $b->customer_id);
        $this->assertSame($a->id, (int) $b->customer_address_id);
        $this->assertSame('assigned', ServiceJob::where('booking_id', $id)->value('status'));
    }

    #[Test]
    public function an_existing_mobile_is_refused_and_nothing_is_created(): void
    {
        $this->customer(phone: '9832654152');
        $this->actingAsUser($this->staff('operator'));

        $this->postJson('/api/v1/admin/bookings', $this->newCustomerBody())
            ->assertStatus(422)->assertJsonValidationErrors('new_customer.phone');
        $this->assertSame(1, Customer::count());
        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function missing_or_bad_new_customer_fields_are_refused(): void
    {
        $this->actingAsUser($this->staff('operator'));
        $cases = [
            ['name' => ''],
            ['phone' => '12345'],
            ['address' => ['line1' => '']],
            ['address' => ['city' => '']],
            ['address' => ['state_code' => '9']],
            ['address' => ['pincode' => '25001']],
        ];
        foreach ($cases as $bad) {
            $this->postJson('/api/v1/admin/bookings', $this->newCustomerBody($bad))->assertStatus(422);
        }
        $this->postJson('/api/v1/admin/bookings', ['service_id' => $this->ac->id, 'scheduled_date' => today()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function a_failing_booking_leaves_no_half_created_customer(): void
    {
        $this->actingAsUser($this->staff('operator'));
        // Required service answers missing → the whole call fails, the customer is not kept either.
        $this->postJson('/api/v1/admin/bookings', $this->newCustomerBody([], ['form_data' => []]))->assertStatus(422);
        // Technician of another branch → refused before anything is saved.
        $other = $this->technician($this->makeBranch('DEL', '07', 'Delhi'));
        $this->postJson('/api/v1/admin/bookings', $this->newCustomerBody([], ['technician_id' => $other->id]))->assertStatus(422);

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, CustomerAddress::count());
        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function branch_rules_for_the_new_customer(): void
    {
        $delhi = $this->makeBranch('DEL', '07', 'Delhi');

        // Branch staff: always their own branch, even if another is sent.
        $this->actingAsUser($this->staff('operator'));
        $this->postJson('/api/v1/admin/bookings', $this->newCustomerBody(['branch_id' => $delhi->id]))->assertCreated();
        $this->assertSame($this->branch->id, (int) Customer::where('phone', '9832654152')->value('branch_id'));

        // Super Admin (no branch of their own) must choose one.
        $sa = $this->staff('super_admin');
        $sa->update(['branch_id' => null]); // a Super Admin has no branch of their own (actingAsUser reloads from the DB)
        $this->actingAsUser($sa);
        $this->postJson('/api/v1/admin/bookings', $this->newCustomerBody(['phone' => '9832654153']))->assertStatus(422);
        $this->postJson('/api/v1/admin/bookings', $this->newCustomerBody(['phone' => '9832654153', 'branch_id' => $delhi->id]))->assertCreated();
        $this->assertSame($delhi->id, (int) Customer::where('phone', '9832654153')->value('branch_id'));
    }

    #[Test]
    public function an_existing_customer_id_wins_over_new_customer_fields(): void
    {
        $existing = $this->customer();
        $this->actingAsUser($this->staff('operator'));

        $this->postJson('/api/v1/admin/bookings', $this->newCustomerBody([], ['customer_id' => $existing->id]))
            ->assertCreated()->assertJsonPath('customer_id', $existing->id);
        $this->assertSame(1, Customer::count());
    }

    #[Test]
    public function staff_without_customer_rights_cannot_add_one_while_booking(): void
    {
        $role = \App\Models\Role::where('slug', 'operator')->firstOrFail();
        $role->permissions()->detach(\App\Models\Permission::where('slug', 'customers.manage')->value('id'));
        $this->actingAsUser($this->staff('operator'));

        $this->postJson('/api/v1/admin/bookings', $this->newCustomerBody())->assertForbidden();
        $this->assertSame(0, Customer::count());
    }

    #[Test]
    public function the_new_customer_can_then_log_in_by_otp_as_the_same_record(): void
    {
        $this->actingAsUser($this->staff('operator'));
        $this->postJson('/api/v1/admin/bookings', $this->newCustomerBody())->assertCreated();
        $id = Customer::where('phone', '9832654152')->value('id');

        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9832654152'])->json('code');
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9832654152', 'code' => $code])->assertOk()->assertJsonPath('user.customer_id', $id);
        $this->assertSame(1, Customer::count());
    }

    // ---- 2. Solar System Type is mandatory for PV and water heater -------------------

    #[Test]
    public function solar_system_type_is_required_for_pv_and_water_heater_only(): void
    {
        $c = $this->customer();
        $this->actingAsUser($this->customerUser($c));
        $book = fn (string $code, array $form) => $this->postJson('/api/v1/customer/bookings', [
            'service_id' => Service::where('code', $code)->value('id'), 'scheduled_date' => today()->toDateString(), 'form_data' => $form,
        ]);

        $book('SOL-PV', ['solar_service_type' => 'Panel Cleaning', 'system_capacity' => '3 KW'])->assertStatus(422)->assertJsonValidationErrors('system_type');
        $book('SOL-SWH', ['solar_service_type' => 'Breakdown', 'system_capacity' => '200 LPD'])->assertStatus(422)->assertJsonValidationErrors('system_type');
        $book('SOL-SWH', ['solar_service_type' => 'Breakdown', 'system_capacity' => '200 LPD', 'system_type' => 'Not a type'])->assertStatus(422)->assertJsonValidationErrors('system_type');

        $book('SOL-PV', ['solar_service_type' => 'Panel Cleaning', 'system_capacity' => '3 KW', 'system_type' => 'On-Grid'])->assertCreated();
        $book('SOL-SWH', ['solar_service_type' => 'Breakdown', 'system_capacity' => '200 LPD', 'system_type' => 'ETC (Glass Tube)'])->assertCreated();
        // The inverter is not in the spec table: still optional.
        $book('SOL-INV', ['solar_service_type' => 'Breakdown', 'system_capacity' => '5 KVA'])->assertCreated();
    }

    #[Test]
    public function the_migration_updates_an_existing_live_catalog_only_where_needed(): void
    {
        // Live catalog as it was before: System Type optional, admin had renamed a label.
        foreach (['SOL-PV', 'SOL-SWH', 'SOL-INV'] as $code) {
            $s = Service::where('code', $code)->firstOrFail();
            $s->update(['form_schema' => collect($s->form_schema)->map(fn ($f) => $f['key'] === 'system_type' ? ['required' => false, 'label' => 'Type (edited)'] + $f : $f)->all()]);
        }
        $migration = require database_path('migrations/2026_11_05_100000_require_solar_system_type.php');
        $migration->up();
        $migration->up(); // running twice is harmless

        $field = fn (string $code) => collect(Service::where('code', $code)->value('form_schema'))->firstWhere('key', 'system_type');
        $this->assertTrue($field('SOL-PV')['required']);
        $this->assertTrue($field('SOL-SWH')['required']);
        $this->assertFalse($field('SOL-INV')['required']);
        $this->assertSame('Type (edited)', $field('SOL-PV')['label']); // admin edits kept

        $migration->down();
        $this->assertFalse($field('SOL-PV')['required']);
    }

    // ---- 3. Filters for multi-choice and number answers ------------------------------

    private function roBookings(): array
    {
        $ro = Service::where('code', 'HA-RO')->firstOrFail();
        $svc = app(BookingService::class);
        $base = ['service_type' => 'Filter Replacement', 'warranty_status' => 'In-Warranty', 'defect_code' => 'Low Flow'];
        $mk = fn (array $form) => $svc->create($this->customer(), $ro, ['scheduled_date' => today()->toDateString(), 'form_data' => $base + $form])->id;

        return [
            'sed_carbon_300' => $mk(['filter_replacement' => ['Sediment', 'Carbon'], 'tds' => 300, 'tank_capacity' => 8]),
            'membrane_1500' => $mk(['filter_replacement' => ['Membrane'], 'tds' => 1500]),
            'carbon_text_tds' => $mk(['filter_replacement' => ['Carbon'], 'tds' => '75']),
            'no_answers' => $mk([]),
        ];
    }

    private function ids(string $query): array
    {
        return collect($this->getJson('/api/v1/admin/bookings?'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    private function pick(array $ids, string ...$keys): array
    {
        return collect($keys)->map(fn ($k) => $ids[$k])->sort()->values()->all();
    }

    #[Test]
    public function multi_choice_answers_filter_by_contains(): void
    {
        $ids = $this->roBookings();
        $this->actingAsUser($this->staff('super_admin'));

        $this->assertSame($this->pick($ids, 'sed_carbon_300', 'carbon_text_tds'), $this->ids('form[filter_replacement]=Carbon'));
        $this->assertSame($this->pick($ids, 'membrane_1500'), $this->ids('form[filter_replacement]=Membrane'));
        $this->assertSame([], $this->ids('form[filter_replacement]=Carb'));        // whole choice only
        $this->assertSame([], $this->ids('form[filter_replacement]=%25'));          // % is not a wildcard
        // A plain select answer still matches exactly.
        $this->assertSame(count($ids), count($this->ids('form[service_type]=Filter%20Replacement')));
    }

    #[Test]
    public function number_answers_filter_by_range(): void
    {
        $ids = $this->roBookings();
        $this->actingAsUser($this->staff('super_admin'));

        $this->assertSame($this->pick($ids, 'sed_carbon_300', 'membrane_1500'), $this->ids('form_min[tds]=200'));
        $this->assertSame($this->pick($ids, 'sed_carbon_300', 'carbon_text_tds'), $this->ids('form_max[tds]=500'));
        $this->assertSame($this->pick($ids, 'sed_carbon_300'), $this->ids('form_min[tds]=300&form_max[tds]=300'));
        $this->assertSame($this->pick($ids, 'carbon_text_tds'), $this->ids('form_max[tds]=100'));       // "75" stored as text still counts
        $this->assertSame($this->pick($ids, 'sed_carbon_300'), $this->ids('form_min[tank_capacity]=5')); // others have no answer
        $this->assertSame([], $this->ids('form_min[tds]=2000'));
        $this->assertSame([], $this->ids('form_min[service_type]=1'));   // a text answer never matches a range
        $this->assertSame($this->pick($ids, 'sed_carbon_300'), $this->ids('form_min[tds]=250&form[filter_replacement]=Carbon'));

        // Jobs use the same rules through their booking.
        $jobs = collect($this->getJson('/api/v1/admin/jobs?form_min[tds]=1000')->assertOk()->json('data'))->pluck('booking_id')->all();
        $this->assertSame([$ids['membrane_1500']], $jobs);
    }

    #[Test]
    public function bad_range_values_are_refused_and_bad_keys_ignored(): void
    {
        $this->roBookings();
        $this->actingAsUser($this->staff('super_admin'));

        $this->getJson('/api/v1/admin/bookings?form_min[tds]=abc')->assertStatus(422);
        $this->getJson('/api/v1/admin/jobs?form_max[tds]=1;DROP')->assertStatus(422);
        $all = count($this->ids(''));
        $this->assertSame($all, count($this->ids("form_min[x')%20OR%201=1--]=1")));   // unsafe key ignored
        $this->assertSame($all, count($this->ids('form_min[tds]=')));                  // empty value ignored
    }
}
