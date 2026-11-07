<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoBusinessSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One-time "clean for the client" reset of a live database:
 *   - deletes all test business data (customers, bookings, jobs, invoices, payments,
 *     complaints, messages, attendance, expenses, logins of customers, sign-in tokens);
 *   - keeps the catalog, settings, templates, branches, products and stock;
 *   - anonymises every staff/technician login except the kept ones (rows stay so
 *     stock history keeps working, but names/emails/phones are removed);
 *   - sets the passwords of the kept logins (Super Admin + demo technicians);
 *   - loads dummy demo data (DemoBusinessSeeder).
 * Runs once per token: the same token never wipes twice (safe on every restart).
 * Everything is one transaction: on any error nothing is changed.
 */
class DemoDataReset
{
    public const TOKEN_KEY = 'maintenance.demo_reset_token';

    /** Demo technicians kept for the client (created if missing). */
    public const DEMO_TECHS = [
        ['email' => 'tech1@shwac.test', 'name' => 'Ravi Kumar', 'phone' => '9000000005'],
        ['email' => 'tech2@shwac.test', 'name' => 'Amit Singh', 'phone' => '9000000006'],
    ];

    /** Deleted in this order so no foreign key is ever left pointing at a deleted row. */
    private const WIPE = [
        'payments', 'payment_links', 'invoice_items', 'invoices',
        'job_attachments', 'job_status_logs', 'expenses', 'service_jobs', 'bookings',
        'complaint_updates', 'complaints',
        'customer_products', 'customer_addresses', 'customers',
        'notification_logs', 'otp_codes', 'idempotency_keys', 'attendances', 'location_pings',
        'personal_access_tokens', 'number_sequences',
    ];

    /**
     * @return array{status: string, message: string}
     */
    public function run(string $token, string $adminEmail, string $adminPassword, string $techPassword): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('A reset token is required (e.g. RESET_DEMO_DATA=yes-2026-11-07).');
        }
        if (Setting::get(self::TOKEN_KEY) === $token) {
            return ['status' => 'skipped', 'message' => "Demo reset with token '{$token}' already ran; nothing changed."];
        }
        $this->assertStrong($adminPassword, 'DEMO_ADMIN_PASSWORD');
        $this->assertStrong($techPassword, 'DEMO_TECH_PASSWORD');
        if (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('DEMO_ADMIN_EMAIL is not a valid email.');
        }

        DB::transaction(function () use ($token, $adminEmail, $adminPassword, $techPassword) {
            $branch = Branch::where('is_active', true)->orderBy('id')->firstOrFail();

            // 1. Logins that stay: the Super Admin and the demo technicians.
            $admin = User::where('email', $adminEmail)->first() ?? new User(['email' => $adminEmail]);
            $admin->fill(['name' => 'Super Admin', 'user_type' => User::TYPE_STAFF, 'branch_id' => null, 'is_active' => true, 'removed_at' => null, 'password' => $adminPassword])->save();
            $admin->roles()->sync(Role::where('slug', 'super_admin')->pluck('id'));
            $keep = [$admin->id];
            foreach (self::DEMO_TECHS as $t) {
                $u = User::where('email', $t['email'])->first() ?? new User(['email' => $t['email']]);
                // Free the demo phone if a test login holds it.
                User::where('phone', $t['phone'])->where(fn ($q) => $q->whereNull('email')->orWhere('email', '!=', $t['email']))->update(['phone' => null]);
                $u->fill(['name' => $t['name'], 'phone' => $t['phone'], 'user_type' => User::TYPE_TECHNICIAN, 'branch_id' => $branch->id,
                    'is_active' => true, 'removed_at' => null, 'password' => $techPassword, 'location_consent_at' => now()])->save();
                $u->roles()->sync(Role::where('slug', 'technician')->pluck('id'));
                $keep[] = $u->id;
            }

            // 2. Test business data.
            foreach (self::WIPE as $table) {
                DB::table($table)->delete();
            }
            DB::table('users')->where('user_type', User::TYPE_CUSTOMER)->delete();

            // 3. Every other staff/technician login: anonymised and blocked (row kept for stock history).
            User::whereIn('user_type', [User::TYPE_STAFF, User::TYPE_TECHNICIAN])->whereNotIn('id', $keep)->get()
                ->each(function (User $u) {
                    $u->forceFill(['name' => 'Removed user #'.$u->id, 'email' => null, 'phone' => null, 'is_active' => false,
                        'removed_at' => $u->removed_at ?? now(), 'password' => Str::random(40)])->save();
                    DB::table('warehouses')->where('user_id', $u->id)->update(['is_active' => false, 'name' => 'Van (removed user)']);
                });

            // 4. Dummy data for the client, then the one-time lock.
            (new DemoBusinessSeeder)->run();
            Setting::put(self::TOKEN_KEY, $token);
        });

        return ['status' => 'done', 'message' => 'Test data removed and demo data loaded.'];
    }

    private function assertStrong(string $password, string $name): void
    {
        if (mb_strlen($password) < 10 || ! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)
            || strcasecmp($password, 'Password@123') === 0) {
            throw new InvalidArgumentException("{$name} must be at least 10 characters with letters and numbers (and not the old default).");
        }
    }
}
