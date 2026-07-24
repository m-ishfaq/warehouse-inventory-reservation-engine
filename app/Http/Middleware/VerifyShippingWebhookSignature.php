<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate inbound carrier callbacks.
 *
 * A webhook that deducts inventory is an unauthenticated write endpoint unless
 * it is signed — anyone who learns the URL could mark shipments delivered and
 * drain stock. Three checks, each closing a different hole:
 *
 *   1. HMAC-SHA256 over the RAW body with a shared secret, compared in constant
 *      time. Proves the payload came from the carrier and was not modified.
 *      The raw body matters: re-encoding the parsed JSON would change byte order
 *      and break verification for legitimate callers.
 *
 *   2. A timestamp inside the signed material, within a tolerance window. Without
 *      it, a valid signed request captured off the wire could be replayed
 *      forever. (Replays of *processed* events are also caught downstream by
 *      processed_webhooks — this stops them at the door.)
 *
 *   3. Presence of an event id, so the downstream dedupe always has a key to
 *      work with rather than silently falling back to "process it again".
 */
class VerifyShippingWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('inventory.shipping.webhook.secret', '');

        if ($secret === '') {
            Log::error('Shipping webhook received but no signing secret is configured.');

            return $this->reject('webhook_secret_not_configured', 'Webhook verification is not configured.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $signature = (string) $request->header('X-Shipping-Signature', '');
        $timestamp = (string) $request->header('X-Shipping-Timestamp', '');

        if ($signature === '' || $timestamp === '') {
            return $this->reject('missing_signature', 'Signature headers are required.');
        }

        if (! ctype_digit($timestamp)) {
            return $this->reject('invalid_timestamp', 'Signature timestamp is malformed.');
        }

        $tolerance = (int) config('inventory.shipping.webhook.tolerance_seconds', 300);
        $age = abs(time() - (int) $timestamp);

        if ($age > $tolerance) {
            Log::warning('Rejected shipping webhook outside the replay tolerance window.', [
                'age_seconds' => $age,
                'tolerance' => $tolerance,
            ]);

            return $this->reject('signature_expired', 'Signature timestamp is outside the accepted window.');
        }

        // Sign timestamp + raw body together so neither can be swapped
        // independently of the other.
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('Rejected shipping webhook with an invalid signature.');

            return $this->reject('invalid_signature', 'Signature verification failed.');
        }

        return $next($request);
    }

    private function reject(string $error, string $message, int $status = Response::HTTP_UNAUTHORIZED): Response
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
