<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Inventory\Contracts\ShippingProviderInterface;
use App\Infrastructure\Shipping\MockShippingProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, class-string<ShippingProviderInterface>>
     */
    private const SHIPPING_DRIVERS = [
        'mock' => MockShippingProvider::class,
    ];

    public function register(): void
    {
        // The one binding that keeps the domain free of carrier knowledge.
        // Adding a real carrier means writing a class and adding a line here —
        // no service, job, or controller changes. That is the payoff for
        // depending on ShippingProviderInterface rather than a concrete class.
        $this->app->bind(ShippingProviderInterface::class, function (): ShippingProviderInterface {
            $driver = (string) config('inventory.shipping.driver', 'mock');

            $class = self::SHIPPING_DRIVERS[$driver] ?? throw new InvalidArgumentException(
                sprintf('Unknown shipping driver [%s]. Register it in AppServiceProvider.', $driver)
            );

            return $this->app->make($class);
        });
    }

    public function boot(): void
    {
        // Fail loudly on a lazy-loaded relation in development. In an inventory
        // engine an accidental N+1 inside a locked transaction does not just cost
        // latency — it lengthens how long rows stay locked under contention.
        Model::preventLazyLoading(! $this->app->environment('production'));

        // Mass assignment is already constrained by explicit $fillable on every
        // model; this makes a forgotten one fail loudly rather than silently
        // accepting whatever the request body contained.
        Model::preventSilentlyDiscardingAttributes(! $this->app->environment('production'));

        $this->configureRateLimiting();
    }

    /**
     * Reservation creation is throttled harder than everything else.
     *
     * An unthrottled reserve endpoint is a denial-of-service against stock
     * itself: an attacker can hold the entire catalogue without ever paying,
     * and every held unit is a sale you cannot make. Rate limiting is a
     * business control here, not just an infrastructure one.
     */
    private function configureRateLimiting(): void
    {
        $perMinute = (int) config('inventory.api.rate_limit_per_minute', 60);

        RateLimiter::for('reservations', static fn (Request $request): Limit => Limit::perMinute($perMinute)
            ->by($request->bearerToken() ?: (string) $request->ip())
            ->response(static fn (): JsonResponse => response()->json([
                'error' => 'rate_limited',
                'message' => 'Too many reservation attempts. Slow down.',
            ], 429)));
    }
}
