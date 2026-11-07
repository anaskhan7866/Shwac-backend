<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\DemoDataReset;
use App\Services\Operations\BookingService;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** One-time "clean for the client" reset. */
class DemoDataResetTest extends TestCase
{
    private const ADMIN_PW = 'ClientAdmin2026';
    private const TECH_PW = 'FieldTech2026x';

    private User $realTech;

    private Customer $realCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        (new ServiceCatalogSeeder)->run();

        // "Real" test data that must disappear.
        $this->realCustomer = $this->customer(phone: '7568175224');
        $this->customerUser($this->realCustomer);
        app(BookingService::class)->create($this->realCustomer, Service::where('code', 'HA-AC')->firstOrFail(), [
            'scheduled_date' => today()->toDateString(), 'form_data' => ['service_type' => 'Repair', 'warranty_status' => 'AMC', 'defect_code' => 'Noise'],
        ]);
        $this->realTech = $this->technician();
        $this->realTech->update(['name' => 'newtech', 'email' => 'newtec@gmail.com', 'phone' => '7500000443']);
        User::factory()->withRole('super_admin')->create(['email' => 'admin@shwac.test', 'password' => 'Password@123']);
    }

    private function reset(string $token = 'yes-1'): array
    {
        return app(DemoDataReset::class)->run($token, 'admin@shwac.test', self::ADMIN_PW, self::TECH_PW);
    }

    #[Test]
    public function real_test_data_is_removed_and_dummy_data_loaded(): void
    {
        $this->assertSame('done', $this->reset()['status']);

        // Real people are gone.
        $this->assertNull(Customer::where('phone', '7568175224')->first());
        $this->assertSame(0, User::where('user_type', 'customer')->count());
        $this->realTech->refresh();
        $this->assertNull($this->realTech->email);
        $this->assertNull($this->realTech->phone);
        $this->assertStringStartsWith('Removed user #', $this->realTech->name);
        $this->assertFalse((bool) $this->realTech->is_active);

        // Dummy data is there, numbering restarted.
        $this->assertSame(6, Customer::count());
        $this->assertSame(0, Customer::where('phone', 'not like', '900001000%')->count());
        $this->assertSame(6, Booking::count());
        $this->assertStringEndsWith('000001', Booking::orderBy('id')->value('number'));
        $this->assertSame(['high', 'low', 'medium'], Booking::distinct()->orderBy('priority')->pluck('priority')->all());
        $this->assertSame('paid', Invoice::sole()->status);
        $this->assertSame(1, Complaint::count());
        $this->assertTrue(Booking::whereNull('time_slot')->exists()); // "Any time" calls too

        // Catalog kept.
        $this->assertSame(8, Service::where('is_active', true)->count());
    }

    #[Test]
    public function the_kept_logins_get_the_new_passwords(): void
    {
        $this->reset();

        $admin = User::where('email', 'admin@shwac.test')->sole();
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertTrue(Hash::check(self::ADMIN_PW, $admin->password));
        $this->assertFalse(Hash::check('Password@123', $admin->password));
        foreach (['tech1@shwac.test', 'tech2@shwac.test'] as $email) {
            $t = User::where('email', $email)->sole();
            $this->assertTrue($t->isTechnician() && $t->is_active);
            $this->assertTrue(Hash::check(self::TECH_PW, $t->password));
        }

        $this->postJson('/api/v1/auth/login', ['login' => 'admin@shwac.test', 'password' => self::ADMIN_PW])->assertOk();
        $this->postJson('/api/v1/auth/login', ['login' => 'admin@shwac.test', 'password' => 'Password@123'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['login' => '9000000005', 'password' => self::TECH_PW])->assertOk();
        $this->postJson('/api/v1/auth/login', ['login' => 'newtec@gmail.com', 'password' => 'password'])->assertStatus(422);
    }

    #[Test]
    public function the_same_token_never_wipes_twice(): void
    {
        $this->reset('yes-1');
        $first = Booking::orderBy('id')->pluck('id')->all();

        $this->assertSame('skipped', $this->reset('yes-1')['status']);
        $this->assertSame($first, Booking::orderBy('id')->pluck('id')->all());
        $this->assertSame('yes-1', Setting::get(DemoDataReset::TOKEN_KEY));
    }

    #[Test]
    public function weak_passwords_or_no_token_change_nothing(): void
    {
        foreach ([['yes-1', 'short1', self::TECH_PW], ['yes-1', 'Password@123', self::TECH_PW], ['yes-1', self::ADMIN_PW, 'onlyletterspw'], ['', self::ADMIN_PW, self::TECH_PW]] as [$token, $a, $t]) {
            try {
                app(DemoDataReset::class)->run($token, 'admin@shwac.test', $a, $t);
                $this->fail('Expected the reset to refuse.');
            } catch (InvalidArgumentException) {
                // expected
            }
        }
        $this->assertNotNull(Customer::where('phone', '7568175224')->first());
        $this->assertSame(1, Booking::count());
    }

    #[Test]
    public function the_artisan_command_reads_the_environment(): void
    {
        putenv('DEMO_ADMIN_PASSWORD='.self::ADMIN_PW);
        putenv('DEMO_TECH_PASSWORD=weak');
        $this->artisan('shwac:reset-demo-data', ['--token' => 'yes-cmd'])->assertExitCode(1);
        $this->assertSame(1, Booking::count());

        putenv('DEMO_TECH_PASSWORD='.self::TECH_PW);
        $this->artisan('shwac:reset-demo-data', ['--token' => 'yes-cmd'])->assertExitCode(0);
        $this->assertSame(6, Booking::count());
        putenv('DEMO_ADMIN_PASSWORD');
        putenv('DEMO_TECH_PASSWORD');
    }
}
