<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ServiceJob;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** V2 step 1: priority, optional time slot, technician at booking, call charge, alt mobile. */
class BookingCallFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function body(array $extra = []): array
    {
        return $extra + ['service_id' => $this->service()->id, 'scheduled_date' => today()->toDateString(), 'form_data' => ['brand' => 'Kent']];
    }

    #[Test]
    public function office_books_a_call_with_priority_charge_alt_mobile_and_technician(): void
    {
        $customer = $this->customer(phone: '9876511111');
        $tech = $this->technician();
        $this->actingAsUser($this->staff('operator'));

        $res = $this->postJson('/api/v1/admin/bookings', $this->body([
            'customer_id' => $customer->id, 'priority' => 'high', 'quoted_amount' => 500,
            'whatsapp' => '9876522222', 'technician_id' => $tech->id,
        ]))->assertCreated()->assertJsonPath('priority', 'high')->assertJsonPath('quoted_amount', '500.00');

        $booking = Booking::findOrFail($res->json('id'));
        $this->assertNull($booking->time_slot); // time slot is optional ("Any time")
        $this->assertSame('9876522222', $customer->refresh()->whatsapp);
        $job = ServiceJob::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('assigned', $job->status);
        $this->assertSame($tech->id, (int) $job->technician_id);
        $this->assertSame('scheduled', $booking->refresh()->status);

        // The technician sees the priority and the agreed charge on the job...
        $this->actingAsUser($tech);
        $this->getJson("/api/v1/technician/jobs/{$job->id}")->assertOk()
            ->assertJsonPath('booking.priority', 'high')->assertJsonPath('booking.quoted_amount', '500.00');

        // ...and the bill uses that charge, not the catalog price.
        foreach (['accepted', 'en_route', 'in_progress'] as $s) {
            $this->postJson("/api/v1/technician/jobs/{$job->id}/status", ['status' => $s])->assertOk();
        }
        $this->postJson("/api/v1/technician/jobs/{$job->id}/invoice", ['items' => [['service_id' => $booking->service_id, 'quantity' => 1]]])
            ->assertCreated()->assertJsonPath('items.0.unit_price', '500.00');
    }

    #[Test]
    public function defaults_are_medium_priority_and_the_service_price(): void
    {
        $this->actingAsUser($this->staff('operator'));
        $this->postJson('/api/v1/admin/bookings', $this->body(['customer_id' => $this->customer()->id]))
            ->assertCreated()->assertJsonPath('priority', 'medium')->assertJsonPath('quoted_amount', '499.00');
    }

    #[Test]
    public function a_wrong_technician_is_refused_and_nothing_is_saved(): void
    {
        $customer = $this->customer();
        $other = $this->technician($this->makeBranch('DEL', '07', 'Delhi'));
        $inactive = $this->technician();
        $inactive->update(['is_active' => false]);
        $this->actingAsUser($this->staff('super_admin'));

        $this->postJson('/api/v1/admin/bookings', $this->body(['customer_id' => $customer->id, 'technician_id' => $other->id]))->assertStatus(422);
        $this->postJson('/api/v1/admin/bookings', $this->body(['customer_id' => $customer->id, 'technician_id' => $inactive->id]))->assertStatus(422);
        $this->postJson('/api/v1/admin/bookings', $this->body(['customer_id' => $customer->id, 'technician_id' => $this->staff('operator')->id]))->assertStatus(422);
        $this->postJson('/api/v1/admin/bookings', $this->body(['customer_id' => $customer->id, 'priority' => 'urgent']))->assertStatus(422);
        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function office_can_change_priority_and_charge_later(): void
    {
        $this->actingAsUser($this->staff('operator'));
        $id = $this->postJson('/api/v1/admin/bookings', $this->body(['customer_id' => $this->customer()->id]))->assertCreated()->json('id');

        $this->patchJson("/api/v1/admin/bookings/{$id}", ['priority' => 'low', 'quoted_amount' => 650])
            ->assertOk()->assertJsonPath('priority', 'low')->assertJsonPath('quoted_amount', '650.00');
    }

    #[Test]
    public function customer_sets_priority_and_alt_mobile_but_never_charge_or_technician(): void
    {
        $customer = $this->customer(phone: '9876533333');
        $tech = $this->technician();
        $this->actingAsUser($this->customerUser($customer));

        $res = $this->postJson('/api/v1/customer/bookings', $this->body([
            'priority' => 'high', 'whatsapp' => '9876544444', 'quoted_amount' => 1, 'technician_id' => $tech->id,
        ]))->assertCreated()->assertJsonPath('priority', 'high')->assertJsonPath('quoted_amount', '499.00');

        $this->assertNull($res->json('time_slot'));
        $this->assertSame('9876544444', $customer->refresh()->whatsapp);
        $this->assertNull(ServiceJob::where('booking_id', $res->json('id'))->value('technician_id'));
    }
}
