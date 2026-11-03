<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Service;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** V2 step 4: a booking's appliance details are saved on the customer's appliance and reused. */
class ApplianceMemoryTest extends TestCase
{
    private Service $ac;

    private Customer $cust;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        (new ServiceCatalogSeeder)->run();
        $this->ac = Service::where('code', 'HA-AC')->firstOrFail();
        $this->cust = $this->customer();
        $this->actingAsUser($this->staff('operator'));
    }

    private function book(array $form, array $extra = []): Booking
    {
        $id = $this->postJson('/api/v1/admin/bookings', $extra + [
            'customer_id' => $this->cust->id, 'service_id' => $this->ac->id, 'scheduled_date' => today()->toDateString(),
            'form_data' => $form + ['service_type' => 'General Service', 'warranty_status' => 'In-Warranty', 'defect_code' => 'Not Cooling'],
        ])->assertCreated()->json('id');

        return Booking::findOrFail($id);
    }

    #[Test]
    public function the_first_call_creates_the_appliance_with_its_details(): void
    {
        $booking = $this->book(['brand' => 'LG', 'model_serial' => 'LG-15-001', 'tonnage' => '1.5 Ton', 'gas_type' => 'R32']);

        $p = CustomerProduct::sole();
        $this->assertSame($p->id, (int) $booking->customer_product_id);
        $this->assertSame($this->ac->id, (int) $p->service_id);
        $this->assertSame('Air Conditioner (AC)', $p->product_name);
        $this->assertSame('Home Appliances', $p->category);
        $this->assertSame('LG', $p->brand);
        $this->assertSame('LG-15-001', $p->serial_no);
        $this->assertSame('In-Warranty', $p->warranty_status);
        $this->assertSame('1.5 Ton', $p->details['tonnage']);
        // Call-only answers are not stored on the appliance.
        $this->assertArrayNotHasKey('service_type', $p->details);
        $this->assertArrayNotHasKey('defect_code', $p->details);
    }

    #[Test]
    public function the_next_call_updates_the_same_appliance_and_keeps_old_answers(): void
    {
        $this->book(['brand' => 'LG', 'tonnage' => '1.5 Ton', 'gas_type' => 'R32']);
        $second = $this->book(['tonnage' => '2 Ton']);
        $this->book([], ['form_data' => ['service_type' => 'Repair', 'warranty_status' => 'AMC', 'defect_code' => 'Noise']]);

        $p = CustomerProduct::sole();
        $this->assertSame($p->id, (int) $second->customer_product_id);
        $this->assertSame('2 Ton', $p->details['tonnage']);
        $this->assertSame('R32', $p->details['gas_type']); // not wiped by later calls
        $this->assertSame('LG', $p->brand);
        $this->assertSame('AMC', $p->warranty_status);
    }

    #[Test]
    public function a_different_serial_is_a_second_appliance_and_a_picked_one_is_used(): void
    {
        $this->book(['model_serial' => 'AC-ONE']);
        $this->book(['model_serial' => 'AC-TWO']);
        $this->assertSame(2, CustomerProduct::count());

        $one = CustomerProduct::where('serial_no', 'AC-ONE')->firstOrFail();
        $b = $this->book(['brand' => 'Voltas'], ['customer_product_id' => $one->id]);
        $this->assertSame($one->id, (int) $b->customer_product_id);
        $this->assertSame('Voltas', $one->refresh()->brand);
        $this->assertSame(2, CustomerProduct::count());
    }

    #[Test]
    public function the_customer_app_profile_returns_saved_details_for_prefill(): void
    {
        $this->book(['brand' => 'Daikin', 'tonnage' => '1 Ton']);
        $this->actingAsUser($this->customerUser($this->cust));

        $this->getJson('/api/v1/customer/profile')->assertOk()
            ->assertJsonPath('products.0.service_id', $this->ac->id)
            ->assertJsonPath('products.0.warranty_status', 'In-Warranty')
            ->assertJsonPath('products.0.details.tonnage', '1 Ton')
            ->assertJsonPath('products.0.brand', 'Daikin');

        // Customer books again from the app: same appliance, details updated.
        $this->postJson('/api/v1/customer/bookings', ['service_id' => $this->ac->id, 'scheduled_date' => today()->toDateString(), 'form_data' => [
            'service_type' => 'Gas Filling', 'warranty_status' => 'Out of Warranty', 'defect_code' => 'Not Cooling', 'gas_type' => 'R410A',
        ]])->assertCreated();
        $p = CustomerProduct::sole();
        $this->assertSame('Out of Warranty', $p->warranty_status);
        $this->assertEquals(['tonnage' => '1 Ton', 'brand' => 'Daikin', 'gas_type' => 'R410A'], collect($p->details)->only(['tonnage', 'brand', 'gas_type'])->all());
    }

    #[Test]
    public function another_customers_appliance_cannot_be_used(): void
    {
        $other = $this->customer();
        $theirs = $other->products()->create(['product_name' => 'Their AC']);

        $this->postJson('/api/v1/admin/bookings', [
            'customer_id' => $this->cust->id, 'service_id' => $this->ac->id, 'scheduled_date' => today()->toDateString(), 'customer_product_id' => $theirs->id,
            'form_data' => ['service_type' => 'Repair', 'warranty_status' => 'AMC', 'defect_code' => 'Noise'],
        ])->assertStatus(422);
    }
}
