<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\NotificationLog;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthAndRbacTest extends TestCase
{
    #[Test]
    public function staff_can_log_in_with_email_or_phone(): void
    {
        $u = $this->staff('operator');

        $this->postJson('/api/v1/auth/login', ['login' => $u->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.roles.0', 'operator')->assertJsonStructure(['token']);
        $this->postJson('/api/v1/auth/login', ['login' => $u->phone, 'password' => 'password'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['login' => $u->email, 'password' => 'wrong'])->assertStatus(422);
    }

    #[Test]
    public function inactive_staff_cannot_log_in(): void
    {
        $u = $this->staff('operator');
        $u->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['login' => $u->email, 'password' => 'password'])->assertStatus(422);
    }

    #[Test]
    public function customer_logs_in_with_sms_otp_and_gets_a_profile(): void
    {
        config([
            'services.sms.driver' => 'http',
            'services.sms.url' => 'https://sms.example.in/send?key=K&to={phone}&msg={message}&otp={otp}',
        ]);
        Http::fake(['sms.example.in/*' => Http::response(['ok' => true])]);

        $res = $this->postJson('/api/v1/auth/otp/request', ['phone' => '9876501234'])->assertOk();
        $code = $res->json('code');
        $this->assertNotNull($code);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://sms.example.in/send?key=K&to=919876501234&msg=')
            && str_contains(urldecode($r->url()), $code.' is your SHWAC login code') && str_ends_with($r->url(), '&otp='.$code));

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876501234', 'code' => '000000x'])->assertStatus(422);

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876501234', 'code' => $code, 'name' => 'Neha'])
            ->assertOk()->assertJsonPath('user.user_type', 'customer');

        $customer = Customer::where('phone', '9876501234')->firstOrFail();
        $this->assertSame('Neha', $customer->name);
        $this->assertNotNull($customer->user_id);

        // OTP is single-use
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '9876501234', 'code' => $code])->assertStatus(422);
    }

    #[Test]
    public function account_types_are_isolated(): void
    {
        $c = $this->customer();
        $this->actingAsUser($this->customerUser($c));
        $this->getJson('/api/v1/admin/customers')->assertForbidden();
        $this->getJson('/api/v1/technician/jobs')->assertForbidden();
        $this->getJson('/api/v1/customer/profile')->assertOk();

        $this->actingAsUser($this->technician());
        $this->getJson('/api/v1/admin/customers')->assertForbidden();
        $this->getJson('/api/v1/technician/jobs')->assertOk();
    }

    #[Test]
    public function permissions_are_enforced_per_role(): void
    {
        $this->actingAsUser($this->staff('operator'));
        $this->getJson('/api/v1/admin/customers')->assertOk();
        $this->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->postJson('/api/v1/admin/invoices', [])->assertForbidden();
        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonMissingPath('finance');

        $this->actingAsUser($this->staff('accountant'));
        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonStructure(['finance' => ['receivables']]);
        $this->postJson('/api/v1/admin/complaints', [])->assertForbidden();

        $this->actingAsUser($this->staff('super_admin'));
        $this->getJson('/api/v1/admin/settings')->assertOk()->assertJsonPath('settings.complaints.auto_assign', true);
    }

    #[Test]
    public function branch_staff_only_see_their_branch(): void
    {
        $delhi = $this->makeBranch('DEL', '07', 'Delhi');
        $mine = $this->customer();
        $theirs = $this->customer($delhi, '07');

        $this->actingAsUser($this->staff('operator'));
        $ids = collect($this->getJson('/api/v1/admin/customers')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
        $this->getJson("/api/v1/admin/customers/{$theirs->id}")->assertNotFound();

        // Accountant has branches.all
        $this->actingAsUser($this->staff('accountant'));
        $this->getJson("/api/v1/admin/customers/{$theirs->id}")->assertOk();
    }

    #[Test]
    public function branch_manager_cannot_escalate_privileges(): void
    {
        $this->actingAsUser($this->staff('branch_manager'));

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Sneaky', 'email' => 's@x.test', 'password' => 'Secret123', 'user_type' => 'staff', 'roles' => ['super_admin'],
        ])->assertForbidden();

        $this->postJson('/api/v1/admin/users', [
            'name' => 'New Tech', 'phone' => '9811111111', 'password' => 'Secret123', 'user_type' => 'technician',
        ])->assertCreated()->assertJsonPath('branch_id', $this->branch->id)->assertJsonPath('roles.0.slug', 'technician');
    }
}
