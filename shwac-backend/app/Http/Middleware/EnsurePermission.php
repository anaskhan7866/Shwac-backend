<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * route middleware: permission:invoices.manage  (any of several: permission:a|b)
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        if (! $user || ! $user->isStaff()) {
            abort(403, 'Staff access only.');
        }

        foreach (explode('|', $permissions) as $p) {
            if ($user->hasPermission($p)) {
                return $next($request);
            }
        }

        abort(403, 'You do not have permission to do this.');
    }
}
