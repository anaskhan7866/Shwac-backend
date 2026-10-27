<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * route middleware: user.type:customer  |  user.type:technician
 * Also blocks deactivated accounts on every authenticated request.
 */
class EnsureUserType
{
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $user = $request->user();
        if (! $user || ! $user->is_active) {
            abort(403, 'Account is inactive.');
        }
        if ($types !== [] && ! in_array($user->user_type, $types, true)) {
            abort(403, 'This area is not available for your account type.');
        }

        return $next($request);
    }
}
