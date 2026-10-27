<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Offline sync safety. The apps send every write with an `Idempotency-Key` header
 * (a UUID generated when the action was taken, even if offline). If the same key
 * arrives again — a retry after a dropped connection, or a queue replay — the stored
 * response is returned and the action is NOT applied a second time.
 *
 * Only successful (2xx) and validation (422) outcomes are stored; server errors can be retried.
 */
class Idempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $user = $request->user();
        if (! $key || ! $user || $request->isMethodSafe()) {
            return $next($request);
        }
        if (! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) {
            abort(400, 'Invalid Idempotency-Key.');
        }

        $route = $request->method().' '.$request->path();
        $existing = IdempotencyKey::where('user_id', $user->id)->where('key', $key)->first();
        if ($existing) {
            if ($existing->route !== $route) {
                abort(409, 'Idempotency-Key was already used for a different request.');
            }

            return response($existing->response, $existing->status_code, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
        }

        $response = $next($request);
        $status = $response->getStatusCode();

        if (($status >= 200 && $status < 300) || $status === 422) {
            try {
                IdempotencyKey::create([
                    'user_id' => $user->id, 'key' => $key, 'route' => $route,
                    'status_code' => $status, 'response' => (string) $response->getContent(), 'created_at' => now(),
                ]);
            } catch (QueryException) {
                // concurrent duplicate: the first writer wins, this response is still correct
            }
        }

        return $response;
    }
}
