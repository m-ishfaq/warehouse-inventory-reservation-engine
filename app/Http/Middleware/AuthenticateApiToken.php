<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token authentication for the inventory API.
 *
 * TRADE-OFF, stated plainly: a production deployment would use Sanctum or
 * Passport so tokens are per-user, revocable, and scoped. This is a single
 * shared token from config, which is enough to demonstrate that the mutating
 * endpoints are not open to the world, and small enough to add no dependency.
 * The upgrade path is documented in docs/ARCHITECTURE.md.
 *
 * What it does get right, and what a naive version usually gets wrong:
 *
 *   - hash_equals, not ===. String comparison short-circuits on the first
 *     differing byte, which leaks the token one character at a time to anyone
 *     willing to measure. Constant-time comparison closes that.
 *   - A missing configured token denies everything rather than allowing
 *     everything. Fail closed.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('inventory.api.token', '');

        // Fail closed: an unconfigured token must never mean "no auth required".
        if ($expected === '') {
            return response()->json([
                'error' => 'api_token_not_configured',
                'message' => 'The API is not accepting requests until INVENTORY_API_TOKEN is configured.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $presented = (string) ($request->bearerToken() ?? '');

        if ($presented === '' || ! hash_equals($expected, $presented)) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'A valid bearer token is required.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
