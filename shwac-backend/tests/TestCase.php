<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(10, 0));
        $this->seed([RolesAndPermissionsSeeder::class, SystemSeeder::class]);
        $this->branch = $this->makeBranch('HQ', '09', 'Uttar Pradesh');

        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.phone_number_id' => '123456',
            'services.whatsapp.access_token' => 'wa-token',
            'services.whatsapp.app_secret' => 'wa-secret',
            'services.whatsapp.verify_token' => 'verify-me',
            'services.razorpay.key_id' => 'rzp_test_key',
            'services.razorpay.key_secret' => 'rzp_secret',
            'services.razorpay.webhook_secret' => 'rzp_webhook_secret',
            'services.upi.vpa' => 'shwac@okaxis',
        ]);
        Http::preventStrayRequests();
    }

    protected function makeBranch(string $code, string $stateCode, string $state): Branch
    {
        return Branch::create([
            'name' => $code.' Branch', 'code' => $code, 'state' => $state, 'state_code' => $stateCode,
            'invoice_prefix' => $code === 'HQ' ? 'SHW' : $code,
        ]);
    }

    protected function staff(string $role, ?Branch $branch = null): User
    {
        return User::factory()->withRole($role)->create(['branch_id' => ($branch ?? $this->branch)->id]);
    }

    protected function technician(?Branch $branch = null): User
    {
        return User::factory()->technician()->create(['branch_id' => ($branch ?? $this->branch)->id]);
    }

    protected function actingAsUser(User $user): static
    {
        Sanctum::actingAs($user->fresh(), ['*']);

        return $this;
    }

    protected function customer(?Branch $branch = null, string $stateCode = '09', ?string $phone = null): Customer
    {
        $branch ??= $this->branch;
        $phone ??= (string) random_int(7000000000, 9999999999);
        $c = Customer::create([
            'code' => 'CUS-'.$phone, 'branch_id' => $branch->id, 'name' => 'Test Customer', 'phone' => $phone,
        ]);
        $c->addresses()->create([
            'line1' => '1 Test Street', 'city' => 'Meerut', 'state' => $stateCode === '09' ? 'Uttar Pradesh' : 'Other',
            'state_code' => $stateCode, 'pincode' => '250001', 'is_default' => true,
        ]);

        return $c;
    }

    protected function customerUser(Customer $customer): User
    {
        $u = User::create(['name' => $customer->name, 'phone' => $customer->phone, 'user_type' => 'customer', 'branch_id' => $customer->branch_id]);
        $u->assignRole('customer');
        $customer->update(['user_id' => $u->id]);

        return $u;
    }

    protected function service(array $attrs = []): Service
    {
        $cat = ServiceCategory::firstOrCreate(['slug' => 'ro'], ['name' => 'RO']);

        return Service::create($attrs + [
            'service_category_id' => $cat->id, 'name' => 'RO General Service', 'code' => 'RO-'.random_int(100, 999),
            'base_price' => 499, 'tax_rate' => 18, 'form_schema' => [
                ['key' => 'brand', 'label' => 'Brand', 'type' => 'select', 'options' => ['Kent', 'Aquaguard'], 'required' => true],
            ],
        ]);
    }

    protected function fakeExternal(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST'.random_int(1, 99999)]]]),
            'api.razorpay.com/v1/payment_links' => Http::response(['id' => 'plink_TEST1', 'short_url' => 'https://rzp.io/rzp/abc123', 'status' => 'created']),
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_TEST1', 'amount' => 58900, 'currency' => 'INR', 'receipt' => 'x', 'status' => 'created']),
        ]);
    }
}
