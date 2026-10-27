<?php

namespace Tests\Feature;

use App\Models\CustomerAddress;
use App\Models\Expense;
use App\Models\JobStatusLog;
use App\Models\ServiceJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FieldOpsTest extends TestCase
{
    #[Test]
    public function attendance_requires_consent_and_records_gps(): void
    {
        $tech = $this->technician();
        $this->actingAsUser($tech);

        $this->postJson('/api/v1/technician/attendance/check-in', ['lat' => 28.98, 'lng' => 77.70])->assertStatus(422);
        $this->postJson('/api/v1/technician/consent', ['accepted' => true])->assertOk();

        $this->postJson('/api/v1/technician/attendance/check-in', ['lat' => 28.98, 'lng' => 77.70])->assertCreated();
        $this->postJson('/api/v1/technician/attendance/check-in', ['lat' => 28.99, 'lng' => 77.71])->assertOk(); // second tap is harmless
        $this->postJson('/api/v1/technician/location', ['pings' => [
            ['lat' => 28.985, 'lng' => 77.705, 'accuracy' => 12, 'battery' => 81, 'recorded_at' => now()->subMinutes(5)->toIso8601String()],
            ['lat' => 28.990, 'lng' => 77.710, 'accuracy' => 9, 'recorded_at' => now()->toIso8601String()],
        ]])->assertCreated()->assertJsonPath('stored', 2);

        $this->travel(8)->hours();
        $this->postJson('/api/v1/technician/attendance/check-out', ['lat' => 28.98, 'lng' => 77.70])->assertOk()->assertJsonPath('worked_minutes', 480);
        $this->getJson('/api/v1/technician/today')->assertOk()->assertJsonPath('location_consent', true);

        $this->actingAsUser($this->staff('operator'));
        $live = collect($this->getJson('/api/v1/admin/field/live')->assertOk()->json())->firstWhere('id', $tech->id);
        $this->assertSame('checked_out', $live['duty']);
        $this->assertEquals(28.99, $live['lat']);
        $this->getJson("/api/v1/admin/field/technicians/{$tech->id}/trail?date=2026-10-17")->assertOk()->assertJsonCount(2, 'points');
        $this->getJson('/api/v1/admin/field/attendance?date=2026-10-17')->assertOk()->assertJsonPath('present', 1);
    }

    #[Test]
    public function jobs_come_back_in_nearest_first_route_order(): void
    {
        $tech = $this->technician();
        $c = $this->customer();
        $mk = function (string $no, float $lat, float $lng) use ($c, $tech) {
            $a = CustomerAddress::create(['customer_id' => $c->id, 'line1' => $no, 'city' => 'Meerut', 'state' => 'UP', 'state_code' => '09', 'pincode' => '250001', 'lat' => $lat, 'lng' => $lng]);
            ServiceJob::create(['number' => $no, 'branch_id' => $this->branch->id, 'customer_id' => $c->id, 'customer_address_id' => $a->id, 'technician_id' => $tech->id, 'status' => 'assigned', 'scheduled_date' => today()]);
        };
        $mk('FAR', 29.10, 77.70);
        $mk('NEAR', 28.99, 77.70);
        $mk('MID', 29.04, 77.70);

        $this->actingAsUser($tech);
        $res = $this->getJson('/api/v1/technician/jobs?lat=28.98&lng=77.70')->assertOk();
        $this->assertSame(['NEAR', 'MID', 'FAR'], collect($res->json('data'))->pluck('number')->all());
        $this->assertSame(1, $res->json('data.0.route_position'));
        $this->assertGreaterThan(12, $res->json('route_total_km'));
    }

    #[Test]
    public function offline_replays_with_the_same_idempotency_key_apply_once(): void
    {
        $tech = $this->technician();
        $c = $this->customer();
        $job = ServiceJob::create(['number' => 'JOB-OFF', 'branch_id' => $this->branch->id, 'customer_id' => $c->id,
            'customer_address_id' => $c->addresses()->first()->id, 'technician_id' => $tech->id, 'status' => 'assigned']);
        $this->actingAsUser($tech);

        $headers = ['Idempotency-Key' => 'f3b0c1d2-0000-4a9e-9e1a-7d5c2b1a0001'];
        $this->postJson("/api/v1/technician/jobs/{$job->id}/status", ['status' => 'accepted'], $headers)->assertOk();
        $this->postJson("/api/v1/technician/jobs/{$job->id}/status", ['status' => 'accepted'], $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, JobStatusLog::where('service_job_id', $job->id)->where('to_status', 'accepted')->count());

        // same key on a different endpoint is rejected
        $this->postJson('/api/v1/technician/expenses', ['category' => 'fuel', 'amount' => 50], $headers)->assertStatus(409);

        $exp = ['Idempotency-Key' => 'exp-0000-1111-2222'];
        $this->postJson('/api/v1/technician/expenses', ['category' => 'fuel', 'amount' => 80], $exp)->assertCreated();
        $this->postJson('/api/v1/technician/expenses', ['category' => 'fuel', 'amount' => 80], $exp)->assertCreated();
        $this->assertSame(1, Expense::count());
    }

    #[Test]
    public function expenses_with_receipts_are_approved_by_a_manager_not_self(): void
    {
        Storage::fake('public');
        $tech = $this->technician();
        $this->actingAsUser($tech);
        $id = $this->post('/api/v1/technician/expenses', [
            'category' => 'fuel', 'amount' => 150, 'description' => 'Petrol, Pallavpuram trip',
            'receipt' => UploadedFile::fake()->create('bill.jpg', 100, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        Storage::disk('public')->assertExists(Expense::find($id)->receipt_path);

        $manager = $this->staff('branch_manager');
        $this->actingAsUser($manager);
        $this->getJson('/api/v1/admin/expenses?status=pending')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson("/api/v1/admin/expenses/{$id}/review", ['status' => 'rejected'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/admin/expenses/{$id}/review", ['status' => 'approved'])->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson("/api/v1/admin/expenses/{$id}/review", ['status' => 'approved'])->assertStatus(422);

        $this->actingAsUser($this->staff('accountant'));
        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('finance.expenses_month', '150.00');
    }

    #[Test]
    public function technician_uploads_photos_and_one_signature(): void
    {
        Storage::fake('public');
        $tech = $this->technician();
        $c = $this->customer();
        $job = ServiceJob::create(['number' => 'JOB-PH', 'branch_id' => $this->branch->id, 'customer_id' => $c->id,
            'customer_address_id' => $c->addresses()->first()->id, 'technician_id' => $tech->id, 'status' => 'in_progress']);
        $this->actingAsUser($tech);

        $h = ['Accept' => 'application/json'];
        $this->post("/api/v1/technician/jobs/{$job->id}/attachments", ['type' => 'before', 'file' => UploadedFile::fake()->create('a.jpg', 100, 'image/jpeg')], $h)->assertCreated();
        $this->post("/api/v1/technician/jobs/{$job->id}/attachments", ['type' => 'signature', 'file' => UploadedFile::fake()->create('s.png', 100, 'image/png')], $h)->assertCreated();
        $this->post("/api/v1/technician/jobs/{$job->id}/attachments", ['type' => 'signature', 'file' => UploadedFile::fake()->create('s.png', 100, 'image/png')], $h)->assertStatus(422);

        $this->getJson("/api/v1/technician/jobs/{$job->id}")->assertOk()->assertJsonCount(2, 'attachments');

        $other = $this->technician();
        $this->actingAsUser($other);
        $this->post("/api/v1/technician/jobs/{$job->id}/attachments", ['type' => 'after', 'file' => UploadedFile::fake()->create('x.jpg', 100, 'image/jpeg')], $h)->assertNotFound();
    }

    #[Test]
    public function customer_gets_a_hosted_payment_link_when_the_gateway_is_enabled(): void
    {
        config(['shwac.payments.gateway' => 'razorpay']);
        $this->fakeExternal();
        $c = $this->customer();
        $this->actingAsUser($this->staff('accountant'));
        $inv = $this->postJson('/api/v1/admin/invoices', ['customer_id' => $c->id, 'issue' => true,
            'items' => [['service_id' => $this->service()->id, 'quantity' => 1]]])->assertCreated()->json();

        $this->actingAsUser($this->customerUser($c));
        $this->postJson("/api/v1/customer/invoices/{$inv['id']}/payment-link")->assertOk()->assertJsonPath('short_url', 'https://rzp.io/rzp/abc123');
    }
}
