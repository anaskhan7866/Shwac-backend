<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CustomerProduct;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceJob;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Edge cases for the V2 "Add Call" work: bad input, limits, permissions and odd data. */
class V2EdgeCasesTest extends TestCase
{
    private Service $ac;

    private Service $ro;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        (new ServiceCatalogSeeder)->run();
        $this->ac = Service::where('code', 'HA-AC')->firstOrFail();
        $this->ro = Service::where('code', 'HA-RO')->firstOrFail();
    }

    private function acForm(array $extra = []): array
    {
        return $extra + ['service_type' => 'Repair', 'warranty_status' => 'AMC', 'defect_code' => 'Noise'];
    }

    private function adminBook(array $body, int $status = 201)
    {
        return $this->postJson('/api/v1/admin/bookings', $body + [
            'service_id' => $this->ac->id, 'scheduled_date' => today()->toDateString(), 'form_data' => $this->acForm(),
        ])->assertStatus($status);
    }

    // ---- booking input ----------------------------------------------------------

    #[Test]
    public function bad_booking_inputs_are_refused_with_field_errors(): void
    {
        $c = $this->customer();
        $this->actingAsUser($this->staff('operator'));

        $this->adminBook(['customer_id' => $c->id, 'priority' => 'HIGH'], 422)->assertJsonValidationErrors('priority');
        $this->adminBook(['customer_id' => $c->id, 'quoted_amount' => -1], 422)->assertJsonValidationErrors('quoted_amount');
        $this->adminBook(['customer_id' => $c->id, 'quoted_amount' => 'free'], 422)->assertJsonValidationErrors('quoted_amount');
        $this->adminBook(['customer_id' => $c->id, 'quoted_amount' => 10000000], 422)->assertJsonValidationErrors('quoted_amount');
        $this->adminBook(['customer_id' => $c->id, 'whatsapp' => '5876543210'], 422)->assertJsonValidationErrors('whatsapp');
        $this->adminBook(['customer_id' => $c->id, 'whatsapp' => '98765'], 422)->assertJsonValidationErrors('whatsapp');
        $this->adminBook(['customer_id' => $c->id, 'technician_id' => 999999], 422)->assertJsonValidationErrors('technician_id');
        $this->adminBook(['customer_id' => $c->id, 'customer_product_id' => 'abc'], 422)->assertJsonValidationErrors('customer_product_id');
        $this->assertSame(0, Booking::count());

        // A free visit (charge 0) is allowed.
        $this->adminBook(['customer_id' => $c->id, 'quoted_amount' => 0])->assertJsonPath('quoted_amount', '0.00');
    }

    #[Test]
    public function customer_bad_inputs_are_refused(): void
    {
        $c = $this->customer();
        $this->actingAsUser($this->customerUser($c));
        $body = ['service_id' => $this->ac->id, 'scheduled_date' => today()->toDateString(), 'form_data' => $this->acForm()];

        $this->postJson('/api/v1/customer/bookings', $body + ['priority' => 'urgent'])->assertStatus(422)->assertJsonValidationErrors('priority');
        $this->postJson('/api/v1/customer/bookings', $body + ['whatsapp' => '123'])->assertStatus(422)->assertJsonValidationErrors('whatsapp');
        $this->postJson('/api/v1/customer/bookings', ['form_data' => ['service_type' => 'Not a choice', 'warranty_status' => 'AMC', 'defect_code' => 'Noise']] + $body)
            ->assertStatus(422)->assertJsonValidationErrors('service_type');
        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function an_inactive_service_cannot_be_booked(): void
    {
        $this->ac->update(['is_active' => false]);
        $this->actingAsUser($this->staff('operator'));
        $this->adminBook(['customer_id' => $this->customer()->id], 422);
    }

    // ---- technician at booking -------------------------------------------------

    #[Test]
    public function a_removed_technician_cannot_be_assigned(): void
    {
        $tech = $this->technician();
        $this->actingAsUser($this->staff('super_admin'));
        $this->deleteJson("/api/v1/admin/users/{$tech->id}")->assertOk();

        $this->adminBook(['customer_id' => $this->customer()->id, 'technician_id' => $tech->id], 422);
        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function staff_without_job_rights_cannot_assign_while_booking(): void
    {
        $operator = Role::where('slug', 'operator')->firstOrFail();
        $operator->permissions()->detach(\App\Models\Permission::where('slug', 'jobs.manage')->value('id'));
        $this->actingAsUser($this->staff('operator'));

        $this->adminBook(['customer_id' => $this->customer()->id, 'technician_id' => $this->technician()->id], 403);
        $this->assertSame(0, Booking::count());
        // Booking without a technician still works for them.
        $this->adminBook(['customer_id' => $this->customer()->id]);
    }

    // ---- call charge -----------------------------------------------------------

    #[Test]
    public function a_free_call_bills_zero_and_other_services_keep_catalog_price(): void
    {
        $tech = $this->technician();
        $this->actingAsUser($this->staff('operator'));
        $id = $this->adminBook(['customer_id' => $this->customer()->id, 'quoted_amount' => 0, 'technician_id' => $tech->id])->json('id');
        $job = ServiceJob::where('booking_id', $id)->firstOrFail();

        $this->actingAsUser($tech);
        foreach (['accepted', 'en_route', 'in_progress'] as $s) {
            $this->postJson("/api/v1/technician/jobs/{$job->id}/status", ['status' => $s])->assertOk();
        }
        $inv = $this->postJson("/api/v1/technician/jobs/{$job->id}/invoice", ['items' => [
            ['service_id' => $this->ac->id, 'quantity' => 1],
            ['service_id' => $this->ro->id, 'quantity' => 1],
        ]])->assertCreated();

        $lines = collect($inv->json('items'))->keyBy('item_id');
        $this->assertSame('0.00', $lines[$this->ac->id]['unit_price']);   // the agreed free visit
        $this->assertSame('350.00', $lines[$this->ro->id]['unit_price']); // anything else: catalog price
    }

    #[Test]
    public function a_closed_booking_cannot_be_edited(): void
    {
        $this->actingAsUser($this->staff('operator'));
        $id = $this->adminBook(['customer_id' => $this->customer()->id])->json('id');
        $this->postJson("/api/v1/admin/bookings/{$id}/cancel", ['reason' => 'test'])->assertOk();

        $this->patchJson("/api/v1/admin/bookings/{$id}", ['quoted_amount' => 999])->assertStatus(422);
        $this->patchJson("/api/v1/admin/bookings/{$id}", ['priority' => 'urgent'])->assertStatus(422);
        $this->assertSame('350.00', Booking::findOrFail($id)->quoted_amount);
    }

    // ---- filters -----------------------------------------------------------------

    #[Test]
    public function bad_filter_values_never_crash_the_lists(): void
    {
        $this->actingAsUser($this->staff('super_admin'));
        $this->adminBook(['customer_id' => $this->customer()->id]);

        foreach (['/api/v1/admin/bookings', '/api/v1/admin/jobs'] as $url) {
            $this->getJson($url.'?from=not-a-date')->assertStatus(422);
            $this->getJson($url.'?to=31-31-2026')->assertStatus(422);
            $this->getJson($url.'?category_id=abc')->assertStatus(422);
            $this->getJson($url.'?technician_id=abc')->assertStatus(422);
            $this->getJson($url.'?form[warranty_status][]=AMC')->assertOk();      // array value: ignored
            $this->getJson($url.'?place=%25%25')->assertOk();                    // "%%" is not a wildcard
            $this->assertSame([], $this->getJson($url.'?place=%25%25')->json('data'));
            $this->getJson($url.'?from=2026-12-31&to=2026-01-01')->assertOk()->assertJsonCount(0, 'data');
        }
        $this->getJson('/api/v1/admin/jobs?date=yesterday-ish')->assertStatus(422);
    }

    // ---- appliance memory -----------------------------------------------------------

    #[Test]
    public function an_appliance_of_another_type_cannot_be_picked_for_a_call(): void
    {
        $c = $this->customer();
        $ro = $c->products()->create(['product_name' => 'Kitchen RO', 'service_id' => $this->ro->id, 'details' => ['tds' => 300]]);
        $this->actingAsUser($this->staff('operator'));

        $this->adminBook(['customer_id' => $c->id, 'customer_product_id' => $ro->id], 422)->assertJsonValidationErrors('customer_product_id');
        $this->assertSame(['tds' => 300], $ro->refresh()->details); // untouched
    }

    #[Test]
    public function long_or_padded_serials_and_multi_choice_details_are_stored_safely(): void
    {
        $c = $this->customer();
        $this->actingAsUser($this->staff('operator'));
        $long = str_repeat('S', 200);

        $this->adminBook(['customer_id' => $c->id, 'form_data' => $this->acForm(['model_serial' => '  '.$long.'  '])]);
        $p = CustomerProduct::sole();
        $this->assertSame(60, mb_strlen($p->serial_no));
        $this->assertStringStartsWith('SSS', $p->serial_no);

        // Same serial again (with spaces) is the same appliance.
        $this->adminBook(['customer_id' => $c->id, 'form_data' => $this->acForm(['model_serial' => $long])]);
        $this->assertSame(1, CustomerProduct::count());

        // RO filter replacement is a multi-choice answer: stored as a list.
        $this->adminBook(['customer_id' => $c->id, 'service_id' => $this->ro->id, 'form_data' => [
            'service_type' => 'Filter Replacement', 'warranty_status' => 'In-Warranty', 'defect_code' => 'Low Flow', 'filter_replacement' => ['Sediment', 'Carbon'],
        ]]);
        $ro = CustomerProduct::where('service_id', $this->ro->id)->sole();
        $this->assertSame(['Sediment', 'Carbon'], $ro->details['filter_replacement']);
    }

    #[Test]
    public function customer_add_product_rejects_bad_input(): void
    {
        $c = $this->customer();
        $this->actingAsUser($this->customerUser($c));
        $this->ac->update(['is_active' => false]);

        $this->postJson('/api/v1/customer/products', ['service_id' => $this->ac->id, 'product_name' => 'AC'])->assertStatus(422)->assertJsonValidationErrors('service_id');
        $this->postJson('/api/v1/customer/products', ['service_id' => 999999, 'product_name' => 'AC'])->assertStatus(422);
        $this->postJson('/api/v1/customer/products', ['service_id' => $this->ro->id])->assertStatus(422)->assertJsonValidationErrors('product_name');
        $this->postJson('/api/v1/customer/products', ['service_id' => $this->ro->id, 'product_name' => 'RO', 'purchase_date' => today()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('purchase_date');
        $this->assertSame(0, CustomerProduct::count());
    }
}
