<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Contracts\ShippingProviderInterface;
use App\Domain\Inventory\DTOs\ShippingResponse;
use App\Domain\Inventory\Enums\ShipmentStatus;
use App\Domain\Inventory\Exceptions\InvalidStateTransitionException;
use App\Domain\Inventory\Exceptions\ShipmentTimeoutException;
use App\Models\Order;
use App\Models\ProcessedWebhook;
use App\Models\Reservation;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Dispatch shipments and settle them against inventory.
 *
 * The central decision here: INVENTORY IS NOT DEDUCTED ON DISPATCH, ONLY ON
 * CONFIRMATION.
 *
 * Deducting at dispatch would be simpler and is what most implementations do,
 * but it makes a provider timeout unrecoverable — you have already removed stock
 * for goods that may never have left the building, and you cannot tell the
 * difference between "shipped" and "unknown". Deferring the deduction means the
 * dangerous state (`dispatched`) costs a held reservation and nothing more, and
 * a confirmation arriving an hour late still settles correctly.
 */
final class ShipmentService
{
    public const SCOPE_CREATE = 'shipment.create';

    public const SCOPE_CONFIRM = 'shipment.confirm';

    public function __construct(
        private readonly ShippingProviderInterface $provider,
        private readonly ReservationService $reservations,
        private readonly IdempotencyGuard $idempotency,
    ) {}

    /**
     * Build a pending shipment against existing reservations.
     *
     * Requesting fewer units than a reservation holds is how a partial shipment
     * is expressed: the remainder stays reserved and open.
     *
     * @param  array<int, int>  $reservationQuantities  reservation id => qty to ship
     */
    public function createShipment(
        Order $order,
        Warehouse $warehouse,
        array $reservationQuantities,
        ?string $idempotencyKey = null,
    ): Shipment {
        if ($reservationQuantities === []) {
            throw new InvalidArgumentException('A shipment must contain at least one reservation line.');
        }

        $result = $this->idempotency->execute(
            self::SCOPE_CREATE,
            $idempotencyKey,
            function () use ($order, $warehouse, $reservationQuantities): array {
                $shipment = Shipment::create([
                    'order_id' => $order->getKey(),
                    'warehouse_id' => $warehouse->getKey(),
                    'status' => ShipmentStatus::Pending,
                ]);

                foreach ($reservationQuantities as $reservationId => $qty) {
                    $qty = (int) $qty;

                    if ($qty <= 0) {
                        continue;
                    }

                    $reservation = Reservation::query()->findOrFail($reservationId);

                    if (! $reservation->status->isOpen()) {
                        throw InvalidStateTransitionException::between(
                            'Reservation',
                            $reservation->status,
                            $reservation->status,
                        );
                    }

                    // Never promise the carrier more than the claim still holds.
                    $requested = min($qty, $reservation->qtyOutstanding());

                    if ($requested <= 0) {
                        continue;
                    }

                    ShipmentItem::create([
                        'shipment_id' => $shipment->getKey(),
                        'reservation_id' => $reservation->getKey(),
                        'qty_requested' => $requested,
                    ]);
                }

                return ['shipment_id' => (int) $shipment->getKey()];
            },
        );

        return Shipment::query()->findOrFail($result->payload['shipment_id']);
    }

    /**
     * Hand a shipment to the carrier and act on the answer.
     *
     * Called from the queued job, so it must be safe to run twice concurrently
     * and safe to run again after a crash. Both are handled by the conditional
     * status claim below rather than by an external lock.
     */
    public function process(Shipment $shipment): ShipmentStatus
    {
        if (! $this->claimForDispatch($shipment)) {
            // Another worker already owns this shipment, or it is already
            // settled. Either way there is nothing for us to do — and quietly
            // doing nothing is the correct response to a duplicated job.
            return $shipment->refresh()->status;
        }

        $shipment->refresh();
        $response = $this->provider->dispatch($shipment);

        $shipment->provider_ref = $response->providerRef;
        $shipment->save();

        return match (true) {
            $response->outcome->isRetryable() => $this->handleTimeout($shipment, $response),
            ! $response->isSuccessful() => $this->handlePermanentFailure($shipment, $response),
            default => $this->handleSuccess($shipment, $response),
        };
    }

    /**
     * Atomically claim a shipment for dispatch.
     *
     * A conditional UPDATE, not a read-then-write: the WHERE clause is the lock.
     * If two workers pick up the same job, exactly one UPDATE matches a row and
     * the other sees 0 affected and backs off. No advisory locks, no race.
     */
    private function claimForDispatch(Shipment $shipment): bool
    {
        $claimable = [ShipmentStatus::Pending->value, ShipmentStatus::Failed->value];

        $affected = Shipment::query()
            ->whereKey($shipment->getKey())
            ->whereIn('status', $claimable)
            ->update([
                'status' => ShipmentStatus::Dispatched->value,
                'dispatched_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    private function handleTimeout(Shipment $shipment, ShippingResponse $response): never
    {
        $shipment->last_error = $response->error;
        $shipment->save();

        Log::warning('Shipment dispatch timed out; leaving in dispatched state for retry.', [
            'shipment_id' => $shipment->getKey(),
            'provider_ref' => $shipment->provider_ref,
        ]);

        // Left in `dispatched`, NOT rolled back to pending. If the carrier
        // actually did take the goods, a late confirmation must still be able to
        // settle this shipment — and it can, because provider_ref is recorded.
        throw ShipmentTimeoutException::for((int) $shipment->getKey(), $shipment->provider_ref);
    }

    private function handlePermanentFailure(Shipment $shipment, ShippingResponse $response): ShipmentStatus
    {
        $this->transitionTo($shipment, ShipmentStatus::Failed);

        $shipment->last_error = $response->error;
        $shipment->save();

        // Reservations are deliberately NOT released here. The goods are still
        // promised to this order; whether to free them is a commercial decision
        // (retry with another carrier? cancel the line?), not something a failed
        // API call should make on the operator's behalf.
        Log::error('Shipment permanently rejected by carrier.', [
            'shipment_id' => $shipment->getKey(),
            'error' => $response->error,
        ]);

        return ShipmentStatus::Failed;
    }

    private function handleSuccess(Shipment $shipment, ShippingResponse $response): ShipmentStatus
    {
        $eventId = $response->eventId ?? 'auto_'.$shipment->getKey().'_'.$shipment->attempts;

        $this->confirm($shipment, $response->shippedQuantities, $eventId);

        // The provider told us it will send the same confirmation twice. Replay
        // it immediately rather than waiting for the webhook, so the duplicate
        // path is exercised on every run instead of only in production.
        if ($response->sendsDuplicateConfirmation) {
            $this->confirm($shipment, $response->shippedQuantities, $eventId);
        }

        return $shipment->refresh()->status;
    }

    /**
     * Settle a shipment against inventory.
     *
     * Three independent guards make this safe to call any number of times with
     * the same event:
     *
     *   1. processed_webhooks — the same provider event id is recorded once.
     *   2. the idempotency table — the same confirm operation replays.
     *   3. the shipment state machine — a settled shipment refuses to settle again.
     *
     * Belt, braces, and a second belt. Any one of them would do; having all
     * three means removing or misconfiguring one does not cost correctness.
     *
     * @param  array<int, int>  $shippedQuantities  reservation id => qty shipped
     * @return array{shipment_id:int, status:string, shipped:int, replayed:bool}
     */
    public function confirm(Shipment $shipment, array $shippedQuantities, ?string $eventId = null): array
    {
        $result = $this->idempotency->execute(
            self::SCOPE_CONFIRM,
            $eventId,
            function () use ($shipment, $shippedQuantities, $eventId): array {
                $locked = Shipment::query()
                    ->whereKey($shipment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($eventId !== null && ! $this->recordWebhookEvent($eventId, $locked)) {
                    return $this->settledPayload($locked, 0);
                }

                if ($locked->status === ShipmentStatus::Shipped) {
                    // Already fully settled. Converge silently — a retrying
                    // carrier must get a success, not an error.
                    return $this->settledPayload($locked, 0);
                }

                if (! $locked->status->canTransitionTo(ShipmentStatus::PartiallyShipped)
                    && ! $locked->status->canTransitionTo(ShipmentStatus::Shipped)) {
                    throw InvalidStateTransitionException::between(
                        'Shipment',
                        $locked->status,
                        ShipmentStatus::Shipped,
                    );
                }

                $totalShipped = 0;

                foreach ($locked->items()->get() as $item) {
                    $requested = $item->qty_requested - $item->qty_shipped;

                    if ($requested <= 0) {
                        continue;
                    }

                    $reported = $shippedQuantities[(int) $item->reservation_id] ?? $requested;
                    $qty = min($requested, max(0, (int) $reported));

                    if ($qty === 0) {
                        continue;
                    }

                    $reservation = Reservation::query()->findOrFail($item->reservation_id);

                    // The ledger posting and the reservation update happen here,
                    // inside the same transaction as the shipment status change.
                    $consumed = $this->reservations->consume($reservation, $qty, $locked);

                    if ($consumed > 0) {
                        $item->qty_shipped += $consumed;
                        $item->save();
                        $totalShipped += $consumed;
                    }
                }

                $this->finaliseStatus($locked);

                return $this->settledPayload($locked, $totalShipped);
            },
        );

        return [
            'shipment_id' => (int) ($result->payload['shipment_id'] ?? $shipment->getKey()),
            'status' => (string) ($result->payload['status'] ?? $shipment->status->value),
            'shipped' => (int) ($result->payload['shipped'] ?? 0),
            'replayed' => $result->replayed,
        ];
    }

    /**
     * Record the provider event, returning false if we have seen it before.
     *
     * The unique index does the detection; catching the duplicate is cheaper and
     * more honest than a SELECT-then-INSERT, which has a race between the two.
     */
    private function recordWebhookEvent(string $eventId, Shipment $shipment): bool
    {
        try {
            ProcessedWebhook::create([
                'provider' => $this->provider->name(),
                'provider_event_id' => $eventId,
                'event_type' => 'shipment.confirmed',
                'payload' => [
                    'shipment_id' => $shipment->getKey(),
                    'provider_ref' => $shipment->provider_ref,
                ],
                'processed_at' => now(),
            ]);

            return true;
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            Log::info('Duplicate shipment confirmation ignored.', [
                'event_id' => $eventId,
                'shipment_id' => $shipment->getKey(),
            ]);

            return false;
        }
    }

    /**
     * Decide whether the shipment is fully or partially settled.
     */
    private function finaliseStatus(Shipment $shipment): void
    {
        $items = $shipment->items()->get();

        $fullyShipped = $items->every(static fn (ShipmentItem $item): bool => $item->isFullyShipped());
        $anyShipped = $items->contains(static fn (ShipmentItem $item): bool => $item->qty_shipped > 0);

        $target = match (true) {
            $fullyShipped && $anyShipped => ShipmentStatus::Shipped,
            $anyShipped => ShipmentStatus::PartiallyShipped,
            default => $shipment->status,
        };

        if ($target !== $shipment->status) {
            $this->transitionTo($shipment, $target);
        }

        if ($target->hasConsumedInventory() && $shipment->confirmed_at === null) {
            $shipment->confirmed_at = now();
            $shipment->save();
        }
    }

    private function transitionTo(Shipment $shipment, ShipmentStatus $target): void
    {
        if ($shipment->status !== $target && ! $shipment->status->canTransitionTo($target)) {
            throw InvalidStateTransitionException::between('Shipment', $shipment->status, $target);
        }

        $shipment->status = $target;
        $shipment->save();
    }

    /**
     * @return array{shipment_id:int, status:string, shipped:int}
     */
    private function settledPayload(Shipment $shipment, int $shipped): array
    {
        return [
            'shipment_id' => (int) $shipment->getKey(),
            'status' => $shipment->status->value,
            'shipped' => $shipped,
        ];
    }
}
