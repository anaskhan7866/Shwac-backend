<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Staff / technician login with email or phone + password. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);

        $key = 'login:'.mb_strtolower($data['login']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $user = User::query()
            ->whereIn('user_type', [User::TYPE_STAFF, User::TYPE_TECHNICIAN])
            ->where(fn ($q) => $q->where('email', $data['login'])->orWhere('phone', $data['login']))
            ->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['login' => 'Invalid credentials.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['login' => 'Account is inactive. Contact your administrator.']);
        }
        RateLimiter::clear($key);
        $user->update(['last_login_at' => now()]);

        return $this->tokenResponse($user, $data['device_name'] ?? 'api');
    }

    public function requestOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'regex:/^[6-9]\d{9}$/']]);

        return response()->json(['message' => 'OTP sent on WhatsApp.'] + $otp->request($data['phone']));
    }

    public function verifyOtp(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'code' => ['required', 'digits_between:4,8'],
            'name' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);

        $user = $otp->verify($data['phone'], $data['code'], $data['name'] ?? null);

        return $this->tokenResponse($user, $data['device_name'] ?? 'customer-app');
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    private function tokenResponse(User $user, string $device): JsonResponse
    {
        $abilities = match ($user->user_type) {
            User::TYPE_CUSTOMER => ['customer'],
            User::TYPE_TECHNICIAN => ['technician'],
            default => ['staff'],
        };

        return response()->json([
            'token' => $user->createToken($device, $abilities)->plainTextToken,
            'user' => $this->profile($user),
        ]);
    }

    private function profile(User $user): array
    {
        $user->loadMissing('roles:id,slug,name', 'branch:id,name,code');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'user_type' => $user->user_type,
            'branch' => $user->branch,
            'roles' => $user->roles->pluck('slug'),
            'permissions' => $user->isSuperAdmin() ? ['*'] : $user->permissionSlugs(),
            'customer_id' => $user->isCustomer() ? $user->customer?->id : null,
        ];
    }
}
