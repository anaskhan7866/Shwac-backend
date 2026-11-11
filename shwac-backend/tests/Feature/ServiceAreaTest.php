<?php

namespace Tests\Feature;

use App\Models\ServiceArea;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceAreaTest extends TestCase
{
    private function customerAt(string $pincode)
    {
        $c = $this->customer();
        $c->addresses()->update(['pincode' => $pincode]);
        $this->actingAsUser($this->customerUser($c));

        return $c;
    }

    private function serviceIds(array $catalog): array
    {
        return collect($catalog['categories'])->flatMap(fn ($c) => collect($c['services'])->pluck('id'))->sort()->values()->all();
    }

    #[Test]
    public function customers_only_see_and_book_services_offered_at_their_pincode(): void
    {
        $ro = $this->service(['name' => 'RO Service', 'code' => 'RO-A']);
        $ac = $this->service(['name' => 'AC Service', 'code' => 'AC-A']);
        $area = ServiceArea::create(['pincode' => '110001', 'name' => 'Connaught Place', 'city' => 'Delhi']);
        $area->services()->sync([$ro->id]);
        $this->customerAt('110001');

        $cat = $this->getJson('/api/v1/catalog')->assertOk()->assertJsonPath('location.serviceable', true)->assertJsonPath('location.area', 'Connaught Place')->json();
        $this->assertSame([$ro->id], $this->serviceIds($cat));

        $book = fn ($s) => $this->postJson('/api/v1/customer/bookings', ['service_id' => $s->id, 'scheduled_date' => today()->toDateString(), 'form_data' => ['brand' => 'Kent']]);
        $book($ac)->assertStatus(422)->assertJsonValidationErrors('service_id');
        $book($ro)->assertCreated();

        // Appliance types in the app still list everything
        $this->assertSame(collect([$ro->id, $ac->id])->sort()->values()->all(), $this->serviceIds($this->getJson('/api/v1/catalog?all=1')->json()));
    }

    #[Test]
    public function customers_outside_every_service_area_see_nothing_and_cannot_book(): void
    {
        $ro = $this->service();
        $this->customerAt('999999');

        $this->getJson('/api/v1/catalog')->assertOk()->assertJsonPath('location.serviceable', false)
            ->assertJsonPath('location.pincode', '999999')->assertJsonCount(0, 'categories');
        $this->postJson('/api/v1/customer/bookings', ['service_id' => $ro->id, 'scheduled_date' => today()->toDateString(), 'form_data' => ['brand' => 'Kent']])
            ->assertStatus(422)->assertJsonValidationErrors('service_id');

        // A switched-off area counts as not served
        ServiceArea::create(['pincode' => '999999', 'all_services' => true, 'is_active' => false]);
        $this->getJson('/api/v1/catalog')->assertJsonPath('location.serviceable', false);
    }

    #[Test]
    public function a_new_customer_without_an_address_sees_no_services_until_they_add_one(): void
    {
        $this->service();
        $c = $this->customer();
        $c->addresses()->delete();
        $this->actingAsUser($this->customerUser($c));

        $this->getJson('/api/v1/catalog')->assertJsonPath('location.serviceable', false)->assertJsonCount(0, 'categories');

        $this->postJson('/api/v1/customer/addresses', ['line1' => '12 Abu Lane', 'city' => 'Meerut', 'state' => 'Uttar Pradesh', 'state_code' => '09', 'pincode' => '250001'])->assertCreated();
        $this->getJson('/api/v1/catalog')->assertJsonPath('location.serviceable', true)->assertJsonCount(1, 'categories');
    }

    #[Test]
    public function a_new_customer_registers_on_a_pincode_with_or_without_gps(): void
    {
        $ro = $this->service();
        ServiceArea::create(['pincode' => '201301', 'name' => 'Sector 18', 'city' => 'Noida', 'all_services' => true]);
        $c = $this->customer();
        $c->addresses()->delete();
        $this->actingAsUser($this->customerUser($c));

        // City name → the PIN codes served there; a PIN code is checked as is
        $this->getJson('/api/v1/customer/places?q=noida')->assertOk()->assertJsonPath('results.0.pincode', '201301')->assertJsonPath('results.0.name', 'Sector 18');
        $this->getJson('/api/v1/customer/places?q=Agra')->assertOk()->assertJsonCount(0, 'results');
        $this->getJson('/api/v1/customer/places?q=400001')->assertOk()->assertJsonPath('results.0.serviceable', false);

        // GPS refused: the PIN code alone is enough
        $this->postJson('/api/v1/customer/location', ['pincode' => '201301'])->assertOk()
            ->assertJson(['pincode' => '201301', 'city' => 'Noida', 'area' => 'Sector 18', 'serviceable' => true]);
        $this->assertNull($c->fresh()->lat);
        $this->getJson('/api/v1/catalog')->assertJsonPath('location.serviceable', true)->assertJsonPath('location.pincode', '201301');
        $this->getJson('/api/v1/customer/profile')->assertJsonPath('pincode', '201301');

        // GPS allowed: the spot is kept for the technician
        $this->postJson('/api/v1/customer/location', ['pincode' => '201301', 'lat' => 28.57, 'lng' => 77.32])->assertOk();
        $this->assertEquals(28.57, (float) $c->fresh()->lat);
        $this->postJson('/api/v1/customer/location', ['pincode' => '2013'])->assertStatus(422);

        // Booking still needs a full address, which then decides the services
        $this->postJson('/api/v1/customer/bookings', ['service_id' => $ro->id, 'scheduled_date' => today()->toDateString(), 'form_data' => ['brand' => 'Kent']])
            ->assertStatus(422)->assertJsonValidationErrors('customer_address_id');

        // The office sees sign-up-only customers in "not served yet" too
        $this->postJson('/api/v1/customer/location', ['pincode' => '560001', 'city' => 'Bengaluru'])->assertOk()->assertJsonPath('serviceable', false);
        $this->getJson('/api/v1/catalog')->assertJsonCount(0, 'categories');
        $this->actingAsUser($this->staff('super_admin'));
        $this->assertContains('560001', collect($this->getJson('/api/v1/admin/service-areas')->json('uncovered'))->pluck('pincode')->all());
    }

    #[Test]
    public function every_service_the_admin_ticks_is_shown_to_the_customer(): void
    {
        $cat2 = \App\Models\ServiceCategory::create(['name' => 'Solar', 'slug' => 'solar', 'sort_order' => 2]);
        $ac = $this->service(['name' => 'Air Conditioner', 'code' => 'HA-AC', 'sort_order' => 1]);
        $wm = $this->service(['name' => 'Washing Machine', 'code' => 'HA-WM', 'sort_order' => 2]);
        $ro = $this->service(['name' => 'RO Purifier', 'code' => 'HA-RO', 'sort_order' => 3]);
        $pv = $this->service(['name' => 'Solar Panel', 'code' => 'SO-PV', 'service_category_id' => $cat2->id]);
        $c = $this->customer();
        $c->addresses()->delete();
        $this->actingAsUser($this->customerUser($c));
        $this->postJson('/api/v1/customer/location', ['pincode' => '313001'])->assertOk();

        // Set up exactly like the admin page does it: create with ticked services, then edit
        $this->actingAsUser($this->staff('super_admin'));
        $id = $this->postJson('/api/v1/admin/service-areas', ['pincode' => '313001', 'city' => 'Udaipur', 'is_active' => true, 'all_services' => false,
            'service_ids' => [$ac->id, $wm->id]])->assertCreated()->json('id');
        $this->actingAsUser($c->user->fresh());
        $this->assertSame([$ac->id, $wm->id], $this->serviceIds($this->getJson('/api/v1/catalog')->json()), 'two ticked services');

        // Only services other than the AC
        $this->actingAsUser($this->staff('super_admin'));
        $this->patchJson("/api/v1/admin/service-areas/{$id}", ['service_ids' => [$ro->id, $pv->id], 'all_services' => false])->assertOk();
        $this->actingAsUser($c->user->fresh());
        $cat = $this->getJson('/api/v1/catalog')->assertJsonPath('location.serviceable', true)->json();
        $this->assertSame(collect([$ro->id, $pv->id])->sort()->values()->all(), $this->serviceIds($cat), 'non-AC services, two categories');
    }

    #[Test]
    public function allowing_gps_at_sign_up_saves_the_gps_address_as_the_default_address(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['address' => [
            'house_number' => '14', 'road' => 'Hiran Magri Road', 'neighbourhood' => 'Sector 4', 'city' => 'Udaipur',
            'state_district' => 'Udaipur', 'state' => 'Rajasthan', 'postcode' => '313002',
        ]])]);
        ServiceArea::create(['pincode' => '313001', 'city' => 'Udaipur', 'all_services' => true]);
        $c = $this->customer();
        $c->addresses()->delete();
        $this->actingAsUser($this->customerUser($c));

        // The customer confirmed 313001; that PIN code is kept on the address
        $this->postJson('/api/v1/customer/location', ['pincode' => '313001', 'lat' => 24.5714, 'lng' => 73.7091])->assertOk()
            ->assertJsonPath('address.line1', '14 Hiran Magri Road')->assertJsonPath('address.pincode', '313001');

        $address = $c->addresses()->sole();
        $this->assertTrue($address->is_default);
        $this->assertSame(['14 Hiran Magri Road', 'Sector 4', 'Udaipur', 'Rajasthan', '08', '313001'],
            [$address->line1, $address->line2, $address->city, $address->state, $address->state_code, $address->pincode]);
        $this->assertEquals(24.5714, (float) $address->lat);

        // Shown in the customer's profile, and used for services and booking
        $this->getJson('/api/v1/customer/profile')->assertJsonPath('addresses.0.line1', '14 Hiran Magri Road')->assertJsonPath('addresses.0.is_default', true);
        $this->getJson('/api/v1/catalog')->assertJsonPath('location.serviceable', true);
        $ro = $this->service();
        $this->postJson('/api/v1/customer/bookings', ['service_id' => $ro->id, 'scheduled_date' => today()->toDateString(), 'form_data' => ['brand' => 'Kent']])
            ->assertCreated()->assertJsonPath('customer_address_id', $address->id);

        // ...and in the admin customer page
        $this->actingAsUser($this->staff('super_admin'));
        $this->getJson("/api/v1/admin/customers/{$c->id}")->assertOk()->assertJsonPath('addresses.0.line1', '14 Hiran Magri Road');

        // Never a second copy when location is saved again
        $this->actingAsUser($c->user->fresh());
        $this->postJson('/api/v1/customer/location', ['pincode' => '313001', 'lat' => 24.5714, 'lng' => 73.7091])->assertOk()->assertJsonPath('address', null);
        $this->assertSame(1, $c->addresses()->count());
    }

    #[Test]
    public function no_address_is_made_when_gps_is_refused_or_the_spot_has_no_address(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['address' => ['country' => 'India', 'state' => 'Rajasthan']])]);
        $c = $this->customer();
        $c->addresses()->delete();
        $this->actingAsUser($this->customerUser($c));

        $this->postJson('/api/v1/customer/location', ['pincode' => '313001'])->assertOk()->assertJsonPath('address', null);
        Http::assertNothingSent(); // GPS refused: no lookup at all
        $this->postJson('/api/v1/customer/location', ['pincode' => '313001', 'lat' => 24.1, 'lng' => 73.1])->assertOk()->assertJsonPath('address', null);
        $this->assertSame(0, $c->addresses()->count());
        $this->assertSame('313001', $c->fresh()->pincode); // still a customer on their PIN code
    }

    #[Test]
    public function state_names_from_gps_map_to_gst_state_codes(): void
    {
        $this->assertSame('08', \App\Support\Geo::stateCode('Rajasthan'));
        $this->assertSame('07', \App\Support\Geo::stateCode('NCT of Delhi'));
        $this->assertSame('21', \App\Support\Geo::stateCode('Orissa'));
        $this->assertSame('09', \App\Support\Geo::stateCode('uttar pradesh'));
        $this->assertNull(\App\Support\Geo::stateCode('Atlantis'));
    }

    #[Test]
    public function the_postgres_connection_does_not_rely_on_server_side_prepared_statements(): void
    {
        // Neon's pooler (PgBouncer, transaction mode) broke named prepared statements: random 500s
        // ("prepared statement pdo_stmt_… does not exist") and wrong services for a PIN code.
        $options = config('database.connections.pgsql.options');
        $this->assertTrue($options[\PDO::ATTR_EMULATE_PREPARES] ?? false);
        if (config('database.default') === 'pgsql') {
            $this->assertTrue((bool) \DB::connection()->getPdo()->getAttribute(\PDO::ATTR_EMULATE_PREPARES));
        }
    }

    #[Test]
    public function every_customer_screen_loads_for_a_customer_registered_only_by_pincode(): void
    {
        $this->service();
        ServiceArea::create(['pincode' => '313001', 'city' => 'Udaipur', 'all_services' => true]);
        $c = $this->customer();
        $c->addresses()->delete();
        $this->actingAsUser($this->customerUser($c));
        $this->postJson('/api/v1/customer/location', ['pincode' => '313001', 'city' => 'Udaipur'])->assertOk();

        // Exactly what the app/web home screen loads
        foreach (['customer/profile', 'catalog', 'customer/invoices?per_page=50', 'customer/complaints?per_page=50',
            'customer/bookings?per_page=50', 'catalog?all=1'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertOk();
        }
        $this->getJson('/api/v1/customer/places?q=UDAIPUR')->assertOk()->assertJsonPath('results.0.pincode', '313001');
    }

    #[Test]
    public function the_admin_manages_pincodes_and_their_services(): void
    {
        $ro = $this->service(['code' => 'RO-B']);
        $ac = $this->service(['code' => 'AC-B']);
        $this->customer()->addresses()->update(['pincode' => '201301']);
        $this->actingAsUser($this->staff('super_admin'));

        $this->getJson('/api/v1/admin/service-areas')->assertOk()->assertJsonPath('uncovered.0.pincode', '201301');

        $id = $this->postJson('/api/v1/admin/service-areas', ['pincode' => '201301', 'name' => 'Sector 18', 'city' => 'Noida', 'service_ids' => [$ro->id]])
            ->assertCreated()->assertJsonCount(1, 'services')->json('id');
        $this->postJson('/api/v1/admin/service-areas', ['pincode' => '201301'])->assertStatus(422)->assertJsonValidationErrors('pincode');

        $this->patchJson("/api/v1/admin/service-areas/{$id}", ['service_ids' => [$ro->id, $ac->id]])->assertOk()->assertJsonCount(2, 'services');
        $this->patchJson("/api/v1/admin/service-areas/{$id}", ['service_ids' => []])->assertOk()->assertJsonCount(0, 'services');

        $this->getJson('/api/v1/admin/service-areas?q=noida')->assertOk()->assertJsonCount(1, 'areas'); // any letter case
        $this->getJson('/api/v1/admin/service-areas?q=SECTOR')->assertOk()->assertJsonCount(1, 'areas');
        $list = $this->getJson('/api/v1/admin/service-areas')->assertOk();
        $this->assertSame(1, collect($list->json('areas'))->firstWhere('pincode', '201301')['customers']);
        $this->assertNotContains('201301', collect($list->json('uncovered'))->pluck('pincode')->all());

        $this->deleteJson("/api/v1/admin/service-areas/{$id}")->assertOk();
        $this->assertNull(ServiceArea::find($id));
    }

    #[Test]
    public function only_staff_who_manage_services_can_change_service_areas(): void
    {
        $this->actingAsUser($this->staff('operator'));
        $this->postJson('/api/v1/admin/service-areas', ['pincode' => '110002'])->assertForbidden();

        $this->actingAsUser($this->customerUser($this->customer()));
        $this->getJson('/api/v1/admin/service-areas')->assertForbidden();
    }

    #[Test]
    public function gps_location_is_turned_into_a_pincode_and_area(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()
            ->push(['address' => ['road' => 'Abu Lane', 'suburb' => 'Sadar Bazar', 'city' => 'Meerut', 'state_district' => 'Meerut', 'state' => 'Uttar Pradesh', 'postcode' => '250001']])
            ->push(['address' => ['country' => 'India']]), // no PIN code at this spot
        ]);
        $this->actingAsUser($this->customerUser($this->customer()));

        $this->getJson('/api/v1/customer/locate?lat=28.98&lng=77.70')->assertOk()
            ->assertJson(['pincode' => '250001', 'city' => 'Meerut', 'state' => 'Uttar Pradesh', 'line2' => 'Abu Lane, Sadar Bazar', 'serviceable' => true]);

        $this->getJson('/api/v1/customer/locate?lat=10.11&lng=76.22')->assertStatus(422);
    }
}
