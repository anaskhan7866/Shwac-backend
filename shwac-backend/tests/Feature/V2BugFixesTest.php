<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\Operations\BookingService;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Regression tests for the bugs fixed in the V2 pre-deploy scan. */
class V2BugFixesTest extends TestCase
{
    private function bookedJob(?Customer $customer = null): array
    {
        Http::fake();
        $customer ??= $this->customer();
        $booking = app(BookingService::class)->create($customer, $this->service()->refresh(), [
            'scheduled_date' => today()->toDateString(), 'form_data' => ['brand' => 'Kent'],
        ]);

        return [$customer, $booking, ServiceJob::where('booking_id', $booking->id)->firstOrFail()];
    }

    #[Test]
    public function cancelling_the_only_job_of_a_booking_cancels_the_booking(): void
    {
        [, $booking, $job] = $this->bookedJob();
        $this->actingAsUser($this->staff('super_admin'));

        $this->postJson("/api/v1/admin/jobs/{$job->id}/status", ['status' => 'cancelled', 'note' => 'Customer not home'])->assertOk();

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertStringContainsString('Customer not home', $booking->cancel_reason);
    }

    #[Test]
    public function an_assigned_job_must_be_accepted_before_it_can_start(): void
    {
        [, , $job] = $this->bookedJob();
        $this->actingAsUser($this->staff('super_admin'));
        $this->postJson("/api/v1/admin/jobs/{$job->id}/assign", ['technician_id' => $this->technician()->id])->assertOk();

        // The admin job page only offers steps the server accepts.
        $this->postJson("/api/v1/admin/jobs/{$job->id}/status", ['status' => 'in_progress'])->assertStatus(422);
        $this->postJson("/api/v1/admin/jobs/{$job->id}/status", ['status' => 'accepted'])->assertOk()->assertJsonPath('status', 'accepted');
    }

    #[Test]
    public function a_job_cannot_get_a_second_invoice_from_the_admin_panel(): void
    {
        [$customer, $booking, $job] = $this->bookedJob();
        $this->actingAsUser($this->staff('super_admin'));
        $body = ['customer_id' => $customer->id, 'service_job_id' => $job->id, 'items' => [['service_id' => $booking->service_id, 'quantity' => 1]]];

        $this->postJson('/api/v1/admin/invoices', $body)->assertCreated();
        $this->postJson('/api/v1/admin/invoices', $body)->assertStatus(422);
    }

    #[Test]
    public function branch_staff_cannot_invoice_a_job_of_another_branch(): void
    {
        $delhi = $this->makeBranch('DEL', '07', 'Delhi');
        [$customer, $booking, $job] = $this->bookedJob($this->customer($delhi, '07'));
        $this->actingAsUser($this->staff('branch_manager'));

        $this->postJson('/api/v1/admin/invoices', [
            'customer_id' => $customer->id, 'service_job_id' => $job->id, 'items' => [['service_id' => $booking->service_id, 'quantity' => 1]],
        ])->assertNotFound();
    }

    #[Test]
    public function changing_a_customer_phone_moves_their_login_too(): void
    {
        $customer = $this->customer(phone: '9876500001');
        $login = $this->customerUser($customer);
        $this->actingAsUser($this->staff('super_admin'));

        $this->patchJson("/api/v1/admin/customers/{$customer->id}", ['phone' => '9876500002'])->assertOk();
        $this->assertSame('9876500002', $login->refresh()->phone);

        // The customer signs in with the new number and lands on the same record, no duplicate.
        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9876500002'])->assertOk()->json('code');
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876500002', 'code' => $code])
            ->assertOk()->assertJsonPath('user.customer_id', $customer->id);
        $this->assertSame(1, Customer::count());
    }

    #[Test]
    public function changing_a_customer_phone_to_another_login_number_is_refused(): void
    {
        $customer = $this->customer(phone: '9876500003');
        $this->customerUser($customer);
        $taken = $this->staff('operator')->phone;
        $this->actingAsUser($this->staff('super_admin'));

        $this->patchJson("/api/v1/admin/customers/{$customer->id}", ['phone' => $taken])->assertStatus(422);
        $this->assertSame('9876500003', $customer->refresh()->phone);
    }

    #[Test]
    public function otp_login_never_creates_a_duplicate_customer_for_an_existing_login(): void
    {
        $customer = $this->customer(phone: '9876500004');
        $login = $this->customerUser($customer);
        // Older data: the login kept a number the customer record no longer has.
        $customer->update(['phone' => '9876500005']);

        $code = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9876500004'])->assertOk()->json('code');
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876500004', 'code' => $code])
            ->assertOk()->assertJsonPath('user.id', $login->id)->assertJsonPath('user.customer_id', $customer->id);
        $this->assertSame(1, Customer::count());
    }

    #[Test]
    public function only_one_address_stays_default_after_an_edit(): void
    {
        $customer = $this->customer();
        $first = $customer->addresses()->firstOrFail();
        $first->update(['is_default' => true]);
        $second = $customer->addresses()->create(['line1' => 'Shop 2', 'city' => 'Meerut', 'state' => 'Uttar Pradesh', 'state_code' => '09', 'pincode' => '250002']);
        $this->actingAsUser($this->staff('super_admin'));

        $this->patchJson("/api/v1/admin/customers/{$customer->id}/addresses/{$second->id}", ['is_default' => true])->assertOk();

        $this->assertSame([$second->id], $customer->addresses()->where('is_default', true)->pluck('id')->all());
    }

    #[Test]
    public function the_v2_catalog_has_two_categories_and_keeps_used_services(): void
    {
        [, $booking] = $this->bookedJob(); // an old RO service that already has a booking
        $unused = Service::create(['service_category_id' => ServiceCategory::firstOrCreate(['slug' => 'old'], ['name' => 'Old'])->id,
            'name' => 'Old unused', 'code' => 'OLD-1', 'base_price' => 100, 'tax_rate' => 18]);

        (new ServiceCatalogSeeder)->run();
        (new ServiceCatalogSeeder)->run(); // running twice changes nothing

        $this->assertSame(['appliances', 'solar'], ServiceCategory::orderBy('sort_order')->pluck('slug')->all());
        $this->assertSame(8, Service::where('is_active', true)->count());
        $this->assertNull(Service::find($unused->id));
        $used = Service::findOrFail($booking->service_id);
        $this->assertFalse((bool) $used->is_active);
        $this->assertSame((int) Booking::findOrFail($booking->id)->service_id, (int) $used->id);

        $catalog = $this->getJson('/api/v1/catalog')->assertOk();
        $this->assertSame(['Home Appliances', 'Solar'], collect($catalog->json('categories'))->pluck('name')->all());
        $ac = Service::where('code', 'HA-AC')->firstOrFail();
        $required = collect($ac->form_schema)->where('required', true)->pluck('key')->values()->all();
        $this->assertSame(['service_type', 'warranty_status', 'defect_code'], $required);
    }

    #[Test]
    public function booking_a_v2_service_enforces_required_fields_only(): void
    {
        Http::fake();
        (new ServiceCatalogSeeder)->run();
        $customer = $this->customer();
        $this->actingAsUser($this->customerUser($customer));
        $ac = Service::where('code', 'HA-AC')->firstOrFail();

        $this->postJson('/api/v1/customer/bookings', ['service_id' => $ac->id, 'scheduled_date' => today()->toDateString(), 'form_data' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['service_type', 'warranty_status', 'defect_code']);

        // Optional fields (brand, tonnage, gas type…) can be left out.
        $this->postJson('/api/v1/customer/bookings', ['service_id' => $ac->id, 'scheduled_date' => today()->toDateString(), 'form_data' => [
            'service_type' => 'Gas Filling', 'warranty_status' => 'AMC', 'defect_code' => 'Not Cooling',
        ]])->assertCreated();
    }
}
