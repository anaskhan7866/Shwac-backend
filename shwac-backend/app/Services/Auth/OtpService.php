<?php

namespace App\Services\Auth;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Messaging\SmsSender;
use App\Services\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Passwordless customer login: OTP delivered by SMS.
 */
class OtpService
{
    public function __construct(private SmsSender $sms, private NumberGenerator $numbers) {}

    /** @return array{expires_in:int, code?:string} */
    public function request(string $phone): array
    {
        $key = 'otp:'.$phone;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['phone' => 'Too many OTP requests. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        RateLimiter::hit($key, 600);

        $len = (int) config('shwac.otp.length', 6);
        $code = str_pad((string) random_int(0, 10 ** $len - 1), $len, '0', STR_PAD_LEFT);
        $ttl = (int) config('shwac.otp.ttl_minutes', 5);

        OtpCode::where('phone', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        OtpCode::create(['phone' => $phone, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes($ttl)]);

        // Login codes go by SMS (DLT-registered template), the only paid channel in the default setup.
        try {
            $this->sms->send($phone, str_replace('{otp}', $code, (string) config('services.sms.otp_text')), ['otp' => $code]);
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['phone' => 'Could not send the SMS right now. Please try again in a minute.']);
        }

        $out = ['expires_in' => $ttl * 60];
        if (config('shwac.otp.expose_in_response') || app()->environment('local', 'testing')) {
            $out['code'] = $code;
        }

        return $out;
    }

    /** Verify and return (creating if needed) the customer user. */
    public function verify(string $phone, string $code, ?string $name = null): User
    {
        $otp = OtpCode::where('phone', $phone)->whereNull('consumed_at')->latest('id')->first();

        if (! $otp || $otp->expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => 'OTP expired. Please request a new one.']);
        }
        if ($otp->attempts >= (int) config('shwac.otp.max_attempts', 5)) {
            $otp->update(['consumed_at' => now()]);
            throw ValidationException::withMessages(['code' => 'Too many wrong attempts. Please request a new OTP.']);
        }
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            throw ValidationException::withMessages(['code' => 'Incorrect OTP.']);
        }
        $otp->update(['consumed_at' => now()]);

        return DB::transaction(function () use ($phone, $name) {
            $customer = Customer::where('phone', $phone)->first();
            $user = $customer?->user ?? User::where('phone', $phone)->first();

            if ($user && ! $user->isCustomer()) {
                throw ValidationException::withMessages(['phone' => 'This number belongs to a staff account. Use staff login.']);
            }

            if (! $user) {
                $user = User::create([
                    'name' => $customer?->name ?? $name ?? 'Customer',
                    'phone' => $phone,
                    'user_type' => User::TYPE_CUSTOMER,
                    'branch_id' => $customer?->branch_id,
                ]);
                $user->assignRole('customer');
            }

            if (! $customer) {
                $branch = Branch::where('is_active', true)->orderBy('id')->firstOrFail();
                $customer = Customer::create([
                    'code' => $this->numbers->for('customer'),
                    'branch_id' => $branch->id,
                    'name' => $user->name,
                    'phone' => $phone,
                    'whatsapp' => $phone,
                    'user_id' => $user->id,
                ]);
            } elseif (! $customer->user_id) {
                $customer->update(['user_id' => $user->id]);
            }

            $user->update(['last_login_at' => now(), 'branch_id' => $customer->branch_id]);

            return $user;
        });
    }
}
