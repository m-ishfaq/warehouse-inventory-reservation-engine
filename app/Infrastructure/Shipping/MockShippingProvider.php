<?php

declare(strict_types=1);

namespace App\Infrastructure\Shipping;

use App\Domain\Inventory\Contracts\ShippingProviderInterface;
use App\Domain\Inventory\DTOs\ShippingResponse;
use App\Domain\Inventory\Enums\ShippingOutcome;
use App\Models\Shipment;
use Illuminate\Support\Str;

/**
 * Fake carrier exhibiting the five behaviours the quest requires: success,
 * permanent failure, timeout, duplicate delivery confirmation, and delayed
 * confirmation.
 *
 * Randomised by default, but pinnable to one outcome through
 * config('inventory.shipping.mock.forced_outcome'). That switch is what turns an
 * unreproducible dice roll into a test assertion and a repeatable demo — the
 * whole test suite and `php artisan demo:scenario` depend on it.
 */
final class MockShippingProvider implements ShippingProviderInterface
{
    public function name(): string
    {
        return 'mock-carrier';
    }

    public function dispatch(Shipment $shipment): ShippingResponse
    {
        $outcome = $this->decideOutcome();
        $providerRef = $shipment->provider_ref ?? 'MOCK-'.Str::upper(Str::random(12));

        return match ($outcome) {
            ShippingOutcome::PermanentFailure => new ShippingResponse(
                outcome: $outcome,
                providerRef: $providerRef,
                error: 'Carrier rejected the consignment: destination not serviceable.',
            ),

            ShippingOutcome::Timeout => new ShippingResponse(
                outcome: $outcome,
                providerRef: $providerRef,
                error: 'No response from carrier within the request window.',
            ),

            ShippingOutcome::DelayedSuccess => $this->afterDelay(
                fn (): ShippingResponse => $this->successResponse($shipment, $outcome, $providerRef),
            ),

            default => $this->successResponse($shipment, $outcome, $providerRef),
        };
    }

    private function successResponse(Shipment $shipment, ShippingOutcome $outcome, string $providerRef): ShippingResponse
    {
        $quantities = [];

        // Queried rather than accessed as a property: lazy loading is disabled
        // outside production, and the provider is called from a job where the
        // relation has not necessarily been eager loaded.
        foreach ($shipment->items()->get() as $item) {
            // The carrier ships what it was handed. Partial shipments in this
            // system originate from the warehouse requesting fewer units than
            // were reserved, not from the carrier inventing a shortfall —
            // modelling it the other way round would hide the real decision.
            $quantities[(int) $item->reservation_id] = $item->qty_requested;
        }

        return new ShippingResponse(
            outcome: $outcome,
            providerRef: $providerRef,
            shippedQuantities: $quantities,
            // One event id, delivered once or twice depending on the outcome.
            // Reusing the id across both deliveries is exactly what makes the
            // duplicate detectable.
            eventId: 'evt_'.Str::lower(Str::random(24)),
            sendsDuplicateConfirmation: $outcome === ShippingOutcome::DuplicateConfirmation,
        );
    }

    /**
     * @param  callable(): ShippingResponse  $then
     */
    private function afterDelay(callable $then): ShippingResponse
    {
        $seconds = (int) config('inventory.shipping.mock.delay_seconds', 2);

        // Never actually sleep in tests — a suite that takes minutes stops being
        // run, and the delay proves nothing the state machine does not already.
        if ($seconds > 0 && ! app()->runningUnitTests()) {
            sleep($seconds);
        }

        return $then();
    }

    private function decideOutcome(): ShippingOutcome
    {
        $forced = config('inventory.shipping.mock.forced_outcome');

        if (is_string($forced) && $forced !== '') {
            return ShippingOutcome::from($forced);
        }

        /** @var array<string, int> $weights */
        $weights = config('inventory.shipping.mock.weights', []);

        $total = array_sum($weights);

        if ($total <= 0) {
            return ShippingOutcome::Success;
        }

        $roll = random_int(1, $total);
        $cursor = 0;

        foreach ($weights as $outcome => $weight) {
            $cursor += (int) $weight;

            if ($roll <= $cursor) {
                return ShippingOutcome::from($outcome);
            }
        }

        return ShippingOutcome::Success;
    }
}
