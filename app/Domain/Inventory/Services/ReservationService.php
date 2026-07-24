<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\ReservationLine;
use App\Domain\Inventory\DTOs\ReservationResult;
use App\Domain\Inventory\Enums\OrderStatus;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Exceptions\ExceedsOrderedQuantityException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidStateTransitionException;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Reservation;
use App\Models\ReservationEvent;
use App\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reserve, release, expire and consume claims on stock.
 *
 * Every public method is either idempotent by key or idempotent by state:
 * releasing an already-released reservation returns 0 rather than throwing, and
 * consuming beyond a reservation's outstanding quantity is refused. That means a
 * retried job or a duplicated command converges on the same inventory position
 * no matter how many times it lands.
 */
final class ReservationService
{
    public const SCOPE_RESERVE = 'reservation.reserve';

    public const SCOPE_RELEASE = 'reservation.release';

    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly IdempotencyGuard $idempotency,
    ) {}

    /**
     * Place claims for one or more order lines.
     *
     * Strict mode (default) is all-or-nothing: if any line cannot be satisfied
     * the whole transaction rolls back. That is the right default for an ERP —
     * silently under-reserving an order is how the wrong quantity gets shipped
     * and nobody notices until the customer complains.
     *
     * Partial mode grants what is available and reports the shortfall, which is
     * what a warehouse operator wants when they are deliberately splitting an
     * order across a backorder.
     *
     * @param  list<ReservationLine>  $lines
     */
    public function reserve(
        Order $order,
        array $lines,
        ?string $idempotencyKey = null,
        ?bool $allowPartial = null,
        ?int $ttlMinutes = null,
    ): ReservationResult {
        if ($lines === []) {
            throw new InvalidArgumentException('At least one reservation line is required.');
        }

        $allowPartial ??= (bool) config('inventory.reservation.allow_partial_by_default', false);
        $ttlMinutes ??= (int) config('inventory.reservation.default_ttl_minutes', 30);

        $result = $this->idempotency->execute(
            self::SCOPE_RESERVE,
            $idempotencyKey,
            fn (): array => $this->performReserve($order, $lines, $allowPartial, $ttlMinutes),
        );

        return ReservationResult::fromArray($result->payload)->withReplayFlag($result->replayed);
    }

    /**
     * @param  list<ReservationLine>  $lines
     * @return array{reservation_ids: list<int>, shortfalls: list<array<string, int>>}
     */
    private function performReserve(Order $order, array $lines, bool $allowPartial, int $ttlMinutes): array
    {
        // One lock acquisition for the whole order, in ascending inventory.id
        // order. Locking per-line inside the loop would reintroduce exactly the
        // deadlock this design exists to avoid.
        $locked = $this->ledger->lockPairs(array_map(
            static fn (ReservationLine $line): array => [
                'product_id' => $line->productId,
                'warehouse_id' => $line->warehouseId,
            ],
            $lines,
        ));

        // Order lines are locked AFTER inventory, never before. Lock ordering has
        // to be consistent across the whole service: consume() and settle() also
        // touch inventory first and order_items second, so a reservation and a
        // shipment racing on the same line can never hold the two in opposite
        // order and wait on each other.
        $orderItems = OrderItem::query()
            ->whereIn('id', array_values(array_unique(array_map(
                static fn (ReservationLine $line): int => $line->orderItemId,
                $lines,
            ))))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $expiresAt = $ttlMinutes > 0 ? now()->addMinutes($ttlMinutes) : null;

        $reservationIds = [];
        $shortfalls = [];

        foreach ($lines as $line) {
            /** @var Inventory $inventory */
            $inventory = $locked[$line->pairKey()];

            /** @var OrderItem $orderItem */
            $orderItem = $orderItems[$line->orderItemId];

            // Availability is re-read from the locked row, never from whatever
            // the caller saw before the transaction started. Two users racing
            // for the last item both pass validation outside the lock; only one
            // of them passes this check.
            //
            // Note this reflects grants already made earlier in this same loop:
            // the ledger mutates the locked model in place, so two lines for the
            // same product cannot both claim the same unit.
            $available = $inventory->availableQty();

            // A reservation has TWO independent ceilings, and both are checked
            // under their own lock:
            //
            //   available   — what the warehouse physically has spare
            //   outstanding — what this order line still has left to allocate
            //
            // Only checking stock lets a caller reserve 100 units against a line
            // that ordered 5. The DB CHECK constraint does catch that, but by
            // then it is a 500 with SQL in it rather than a 409 the client can
            // act on — and an invariant reached is a bug, not a validation.
            $outstanding = $orderItem->qtyOutstanding();

            if (! $allowPartial) {
                if ($outstanding < $line->qty) {
                    throw ExceedsOrderedQuantityException::for(
                        $line->orderItemId,
                        $line->qty,
                        $outstanding,
                        $orderItem->qty_ordered,
                    );
                }

                if ($available < $line->qty) {
                    throw InsufficientStockException::for(
                        $line->productId,
                        $line->warehouseId,
                        $line->qty,
                        max(0, $available),
                    );
                }
            }

            $granted = min($line->qty, max(0, $available), max(0, $outstanding));

            if ($granted < $line->qty) {
                $shortfalls[] = [
                    'product_id' => $line->productId,
                    'warehouse_id' => $line->warehouseId,
                    'order_item_id' => $line->orderItemId,
                    'requested' => $line->qty,
                    'reserved' => $granted,
                    'shortfall' => $line->qty - $granted,
                    // Which ceiling bit — the client's next move differs entirely.
                    'limited_by' => $available < $outstanding ? 'available_stock' : 'ordered_quantity',
                ];
            }

            if ($granted === 0) {
                continue;
            }

            $reservation = Reservation::create([
                'order_item_id' => $line->orderItemId,
                'product_id' => $line->productId,
                'warehouse_id' => $line->warehouseId,
                'qty' => $granted,
                'status' => ReservationStatus::Active,
                'expires_at' => $expiresAt,
            ]);

            $this->ledger->reserve($inventory, $granted, $reservation, [
                'order_id' => $order->getKey(),
                'order_item_id' => $line->orderItemId,
            ]);

            $this->recordEvent($reservation, null, ReservationStatus::Active, $granted, 'reserved');

            // Read-modify-write is safe here precisely because the row is locked,
            // and it is REQUIRED rather than merely allowed: a bare SQL increment
            // would leave $orderItem stale in memory, so a second line against
            // the same order line would re-read the old outstanding figure and
            // both lines would allocate the same units.
            $orderItem->qty_reserved += $granted;
            $orderItem->save();

            $reservationIds[] = (int) $reservation->getKey();
        }

        $this->syncOrderStatus($order->fresh() ?? $order);

        return [
            'reservation_ids' => $reservationIds,
            'shortfalls' => $shortfalls,
        ];
    }

    /**
     * Hand a claim back to available stock.
     *
     * Returns the quantity actually released, which is 0 when the reservation
     * was already settled. Returning 0 rather than throwing is what makes a
     * retried cancellation safe.
     */
    public function release(
        Reservation $reservation,
        ?int $qty = null,
        string $reason = 'manual_release',
        ?string $idempotencyKey = null,
    ): int {
        $result = $this->idempotency->execute(
            self::SCOPE_RELEASE,
            $idempotencyKey,
            fn (): array => ['released' => $this->settle($reservation->getKey(), $qty, $reason, expired: false)],
        );

        return (int) ($result->payload['released'] ?? 0);
    }

    /**
     * Sweep reservations whose TTL has passed.
     *
     * Each reservation settles in its own transaction so that one poisoned row
     * cannot block the whole sweep — a nightly job that dies halfway is worse
     * than one that skips a record and logs it.
     *
     * @return array{expired: int, released_qty: int}
     */
    public function expireDue(?Carbon $at = null, ?int $limit = null): array
    {
        $at ??= now();
        $limit ??= (int) config('inventory.reservation.expiry_batch_size', 500);

        $ids = Reservation::query()
            ->expiredBy($at)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $expired = 0;
        $releasedQty = 0;

        foreach ($ids as $id) {
            $released = DB::transaction(
                fn (): int => $this->settle((int) $id, null, 'expired', expired: true),
                (int) config('inventory.locking.transaction_attempts', 3),
            );

            if ($released > 0) {
                $expired++;
                $releasedQty += $released;
            }
        }

        return ['expired' => $expired, 'released_qty' => $releasedQty];
    }

    /**
     * Consume part or all of a reservation because stock physically shipped.
     *
     * Called by ShipmentService inside its own transaction. Refuses to consume
     * more than the reservation still holds, which is the guard that makes a
     * duplicate delivery confirmation harmless even if it somehow reached this
     * far past the webhook dedupe and the shipment state machine.
     */
    public function consume(Reservation $reservation, int $qty, Shipment $shipment): int
    {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Consumed quantity must be greater than zero.');
        }

        $locked = $this->lockReservation($reservation->getKey());
        $outstanding = $locked->qtyOutstanding();

        if ($outstanding === 0) {
            return 0;
        }

        if ($qty > $outstanding) {
            throw InsufficientStockException::for(
                $locked->product_id,
                $locked->warehouse_id,
                $qty,
                $outstanding,
            );
        }

        $inventory = $this->ledger->lockPair($locked->product_id, $locked->warehouse_id);

        // One atomic posting lowers on-hand and releases the claim together.
        $this->ledger->ship($inventory, $qty, $shipment, [
            'reservation_id' => $locked->getKey(),
            'shipment_id' => $shipment->getKey(),
        ]);

        $from = $locked->status;
        $locked->qty_consumed += $qty;

        $target = $locked->qtyOutstanding() === 0
            ? ReservationStatus::Consumed
            : ReservationStatus::PartiallyConsumed;

        $this->transitionTo($locked, $target);

        $this->recordEvent($locked, $from, $target, -$qty, 'shipped', [
            'shipment_id' => $shipment->getKey(),
        ]);

        OrderItem::query()->whereKey($locked->order_item_id)->update([
            'qty_shipped' => DB::raw('qty_shipped + '.$qty),
            'qty_reserved' => DB::raw('GREATEST(CAST(qty_reserved AS SIGNED) - '.$qty.', 0)'),
        ]);

        $this->syncOrderStatusForReservation($locked);

        return $qty;
    }

    /**
     * Shared settlement path for manual release and TTL expiry.
     *
     * Takes an id rather than a model because it must re-read the row under a
     * lock: the caller's copy may be stale, and acting on stale quantities is
     * precisely how double-releases return stock that was already returned.
     */
    private function settle(int $reservationId, ?int $qty, string $reason, bool $expired): int
    {
        $reservation = $this->lockReservation($reservationId);

        $outstanding = $reservation->qtyOutstanding();

        // Already settled — a replay, a double-click, or a sweep that raced with
        // a manual cancellation. Converge silently.
        if ($outstanding === 0 || $reservation->status->isTerminal()) {
            return 0;
        }

        $releaseQty = $qty === null ? $outstanding : min($qty, $outstanding);

        if ($releaseQty <= 0) {
            return 0;
        }

        $inventory = $this->ledger->lockPair($reservation->product_id, $reservation->warehouse_id);

        $this->ledger->release($inventory, $releaseQty, $reservation, [
            'reason' => $reason,
        ], expired: $expired);

        $from = $reservation->status;
        $reservation->qty_released += $releaseQty;

        if ($reservation->qtyOutstanding() === 0) {
            // A reservation that shipped part of its quantity and gave back the
            // rest is "consumed" from the order's point of view — stock did move.
            $target = match (true) {
                $reservation->qty_consumed > 0 => ReservationStatus::Consumed,
                $expired => ReservationStatus::Expired,
                default => ReservationStatus::Released,
            };

            $this->transitionTo($reservation, $target);
        } else {
            $reservation->save();
            $target = $reservation->status;
        }

        $this->recordEvent($reservation, $from, $target, -$releaseQty, $reason);

        OrderItem::query()
            ->whereKey($reservation->order_item_id)
            ->update([
                'qty_reserved' => DB::raw('GREATEST(CAST(qty_reserved AS SIGNED) - '.$releaseQty.', 0)'),
            ]);

        $this->syncOrderStatusForReservation($reservation);

        return $releaseQty;
    }

    /**
     * Re-read a reservation under a row lock.
     */
    private function lockReservation(int $id): Reservation
    {
        return Reservation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Apply a lifecycle change, refusing anything the state machine forbids.
     */
    private function transitionTo(Reservation $reservation, ReservationStatus $target): void
    {
        $current = $reservation->status;

        if ($current !== $target && ! $current->canTransitionTo($target)) {
            throw InvalidStateTransitionException::between('Reservation', $current, $target);
        }

        $reservation->status = $target;

        if ($target->isTerminal()) {
            $reservation->closed_at = now();
        }

        $reservation->save();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordEvent(
        Reservation $reservation,
        ?ReservationStatus $from,
        ReservationStatus $to,
        int $qtyDelta,
        string $reason,
        array $context = [],
    ): void {
        ReservationEvent::create([
            'reservation_id' => $reservation->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'qty_delta' => $qtyDelta,
            'reason' => $reason,
            'actor' => auth()->id() !== null ? 'user:'.auth()->id() : 'system',
            'context' => $context === [] ? null : $context,
        ]);
    }

    private function syncOrderStatusForReservation(Reservation $reservation): void
    {
        $order = Order::query()
            ->whereHas('items', fn ($query) => $query->whereKey($reservation->order_item_id))
            ->first();

        if ($order !== null) {
            $this->syncOrderStatus($order);
        }
    }

    /**
     * Derive the order's headline status from its lines.
     *
     * Kept derived rather than stored-and-mutated so it can never drift out of
     * step with the lines it summarises.
     */
    private function syncOrderStatus(Order $order): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            return;
        }

        $items = $order->items()->get();

        if ($items->isEmpty()) {
            return;
        }

        $ordered = $items->sum('qty_ordered');
        $shipped = $items->sum('qty_shipped');
        $reserved = $items->sum('qty_reserved');
        $cancelled = $items->sum('qty_cancelled');

        $status = match (true) {
            $shipped + $cancelled >= $ordered => OrderStatus::Fulfilled,
            $shipped > 0 => OrderStatus::PartiallyShipped,
            $reserved + $shipped + $cancelled >= $ordered => OrderStatus::Reserved,
            $reserved > 0 => OrderStatus::PartiallyReserved,
            default => OrderStatus::Draft,
        };

        if ($order->status !== $status) {
            $order->status = $status;
            $order->save();
        }
    }
}
