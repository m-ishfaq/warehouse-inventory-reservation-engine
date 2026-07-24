<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Exceptions\InventoryInvariantViolation;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The single writer of inventory quantities.
 *
 * Nothing else in the application is permitted to touch `inventory.on_hand_qty`
 * or `inventory.reserved_qty`. Every change goes through post(), which writes
 * the movement row and the cache update inside one transaction. That is what
 * makes the ledger authoritative rather than decorative: there is no code path
 * that can change stock without leaving a trace explaining why.
 *
 * Two structural guarantees are enforced here rather than trusted:
 *
 *   1. Callers must already be inside a transaction with the row locked. We
 *      assert the transaction; the lock is provided by lockPairs().
 *   2. No posting may leave inventory in an impossible state. The DB CHECK
 *      constraints would also catch this, but failing here yields a readable
 *      exception pointing at the offending caller.
 */
final class InventoryLedger
{
    /**
     * Take pessimistic locks on every stock position an operation will touch.
     *
     * Locks are acquired in ascending inventory.id order, ALWAYS. That rules out
     * the classic cycle: two concurrent multi-line orders touching the same
     * products can never hold these locks in opposite order and wait on each
     * other.
     *
     * It rules out that cycle and no other. Ordering governs only the locks taken
     * HERE — anything locked earlier in the same transaction sits outside it and
     * can still deadlock. See ensureRowsExist() for a case where exactly that
     * happened, and docs/ARCHITECTURE.md §4 for the write-up. An earlier version
     * of this docblock called deadlock "structurally impossible"; it was not, and
     * the concurrency suite proved it.
     *
     * @param  list<array{product_id:int, warehouse_id:int}>  $pairs
     * @return array<string, Inventory>  keyed "productId:warehouseId"
     */
    public function lockPairs(array $pairs): array
    {
        $this->assertInTransaction();

        if ($pairs === []) {
            return [];
        }

        $unique = [];
        foreach ($pairs as $pair) {
            $unique[$pair['product_id'].':'.$pair['warehouse_id']] = [
                'product_id' => (int) $pair['product_id'],
                'warehouse_id' => (int) $pair['warehouse_id'],
            ];
        }

        $this->ensureRowsExist($unique);

        $rows = Inventory::query()
            ->tap(fn ($query) => $this->whereAnyPair($query, $unique))
            ->orderBy('id')          // deterministic lock ordering — see docblock
            ->lockForUpdate()
            ->get();

        $locked = [];
        foreach ($rows as $row) {
            $locked[$row->product_id.':'.$row->warehouse_id] = $row;
        }

        return $locked;
    }

    /**
     * Convenience wrapper for single-position operations.
     */
    public function lockPair(int $productId, int $warehouseId): Inventory
    {
        $locked = $this->lockPairs([['product_id' => $productId, 'warehouse_id' => $warehouseId]]);

        return $locked[$productId.':'.$warehouseId];
    }

    /**
     * Goods physically arrive at the warehouse.
     */
    public function receipt(Inventory $inventory, int $qty, ?Model $reference = null, array $context = []): InventoryMovement
    {
        return $this->post($inventory, MovementType::Receipt, $qty, $qty, 0, $reference, $context);
    }

    /**
     * Place a claim on stock. on_hand is untouched — the goods are still here,
     * they are simply spoken for.
     */
    public function reserve(Inventory $inventory, int $qty, ?Model $reference = null, array $context = []): InventoryMovement
    {
        return $this->post($inventory, MovementType::Reserve, $qty, 0, $qty, $reference, $context);
    }

    /**
     * Hand a claim back. `$expired` distinguishes a deliberate cancellation from
     * a TTL sweep, which matters when auditing why stock freed up.
     */
    public function release(Inventory $inventory, int $qty, ?Model $reference = null, array $context = [], bool $expired = false): InventoryMovement
    {
        return $this->post(
            $inventory,
            $expired ? MovementType::Expire : MovementType::Release,
            $qty,
            0,
            -$qty,
            $reference,
            $context,
        );
    }

    /**
     * Stock physically leaves on a confirmed shipment.
     *
     * This is the only movement that lowers both buckets at once: the goods are
     * gone AND the reservation that authorised their departure is consumed. Doing
     * it as one atomic posting is what stops a crash between "deduct stock" and
     * "close reservation" from leaking phantom availability.
     */
    public function ship(Inventory $inventory, int $qty, ?Model $reference = null, array $context = []): InventoryMovement
    {
        return $this->post($inventory, MovementType::Ship, $qty, -$qty, -$qty, $reference, $context);
    }

    public function transferOut(Inventory $inventory, int $qty, ?Model $reference = null, array $context = []): InventoryMovement
    {
        return $this->post($inventory, MovementType::TransferOut, $qty, -$qty, 0, $reference, $context);
    }

    public function transferIn(Inventory $inventory, int $qty, ?Model $reference = null, array $context = []): InventoryMovement
    {
        return $this->post($inventory, MovementType::TransferIn, $qty, $qty, 0, $reference, $context);
    }

    /**
     * Manual correction: stock take, damage, shrinkage. Signed.
     */
    public function adjust(Inventory $inventory, int $delta, ?Model $reference = null, array $context = []): InventoryMovement
    {
        if ($delta === 0) {
            throw new InvalidArgumentException('An adjustment must be non-zero.');
        }

        return $this->post($inventory, MovementType::Adjustment, abs($delta), $delta, 0, $reference, $context);
    }

    /**
     * The one place inventory quantities change.
     *
     * @param  array<string, mixed>  $context
     */
    private function post(
        Inventory $inventory,
        MovementType $type,
        int $qty,
        int $onHandDelta,
        int $reservedDelta,
        ?Model $reference,
        array $context,
    ): InventoryMovement {
        $this->assertInTransaction();

        if ($qty <= 0) {
            throw new InvalidArgumentException('Movement quantity must be greater than zero.');
        }

        $newOnHand = $inventory->on_hand_qty + $onHandDelta;
        $newReserved = $inventory->reserved_qty + $reservedDelta;

        // ------------------------------------------------------------------
        // Invariants. These mirror the DB CHECK constraints deliberately: the
        // constraints are the last line of defence, these are the readable one.
        // ------------------------------------------------------------------
        if ($newOnHand < 0) {
            throw InventoryInvariantViolation::negativeOnHand($inventory->product_id, $inventory->warehouse_id, $newOnHand);
        }

        if ($newReserved < 0) {
            throw InventoryInvariantViolation::negativeReserved($inventory->product_id, $inventory->warehouse_id, $newReserved);
        }

        if ($newReserved > $newOnHand) {
            throw InventoryInvariantViolation::reservedExceedsOnHand($inventory->product_id, $inventory->warehouse_id, $newReserved, $newOnHand);
        }

        // Picked and packed are subsets of reserved, so they must shrink when a
        // shipment consumes the reservation above them.
        //
        // INERT TODAY: no code raises either column, so both are always 0 and
        // min(0, n) is always 0. Kept because it is the correct behaviour the
        // moment a pick/pack workflow lands, and because deleting it would mean
        // whoever adds that workflow has to rediscover the interaction. It costs
        // two comparisons. See docs/ARCHITECTURE.md for what pick/pack would need.
        $newPicked = min($inventory->picked_qty, $newReserved);
        $newPacked = min($inventory->packed_qty, $newPicked);

        $inventory->on_hand_qty = $newOnHand;
        $inventory->reserved_qty = $newReserved;
        $inventory->picked_qty = $newPicked;
        $inventory->packed_qty = $newPacked;
        $inventory->version = $inventory->version + 1;
        $inventory->save();

        return InventoryMovement::create([
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'type' => $type,
            'qty' => $qty,
            'on_hand_delta' => $onHandDelta,
            'reserved_delta' => $reservedDelta,
            'on_hand_after' => $newOnHand,
            'reserved_after' => $newReserved,
            'reference_type' => $reference !== null ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'actor' => $this->resolveActor(),
            'context' => $context === [] ? null : $context,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Create any stock positions that do not exist yet — and, critically, issue
     * no write at all when they already do.
     *
     * The early return is not an optimisation. An unconditional INSERT IGNORE
     * here caused real deadlocks under contention, and the mechanism is worth
     * spelling out because it is invisible in the SQL:
     *
     *   InnoDB's duplicate-key handling for INSERT IGNORE takes a SHARED lock on
     *   the row it collided with. Eight concurrent reservations for the same SKU
     *   therefore all acquired S on the same row, and then all asked for X via
     *   the SELECT ... FOR UPDATE that follows. Every transaction was waiting for
     *   every other to drop its S lock before it could upgrade — a textbook
     *   S-to-X upgrade deadlock, and one that the ascending-id lock ordering
     *   cannot prevent, because the offending lock was taken before the ordered
     *   acquisition even began.
     *
     * Reading first with a plain, non-locking SELECT means the overwhelmingly
     * common case (the position already exists) takes no lock at all and cannot
     * deadlock. Only the genuinely-first reservation for a (product, warehouse)
     * pair reaches the INSERT.
     *
     * insertOrIgnore rather than firstOrCreate is still right for that case: two
     * concurrent first-time reservations would otherwise race the unique index
     * and one would die. If those two also collide on the S lock, the surrounding
     * DB::transaction retry absorbs it — and that is a once-per-position event,
     * not the hot path.
     *
     * @param  array<string, array{product_id:int, warehouse_id:int}>  $pairs
     */
    private function ensureRowsExist(array $pairs): void
    {
        $existing = Inventory::query()
            ->tap(fn ($query) => $this->whereAnyPair($query, $pairs))
            ->get(['product_id', 'warehouse_id'])
            ->mapWithKeys(static fn (Inventory $row): array => [
                $row->product_id.':'.$row->warehouse_id => true,
            ])
            ->all();

        $missing = array_diff_key($pairs, $existing);

        if ($missing === []) {
            return;
        }

        $now = now();

        $rows = array_map(static fn (array $pair): array => [
            'product_id' => $pair['product_id'],
            'warehouse_id' => $pair['warehouse_id'],
            'on_hand_qty' => 0,
            'reserved_qty' => 0,
            'picked_qty' => 0,
            'packed_qty' => 0,
            'version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($missing));

        Inventory::query()->insertOrIgnore($rows);
    }

    /**
     * Constrain a query to a set of (product, warehouse) pairs.
     *
     * Shared by the existence check and the locking read so the two can never
     * disagree about which rows an operation touches.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Inventory>  $query
     * @param  array<string, array{product_id:int, warehouse_id:int}>  $pairs
     */
    private function whereAnyPair($query, array $pairs): void
    {
        $query->where(function ($outer) use ($pairs): void {
            foreach ($pairs as $pair) {
                $outer->orWhere(function ($inner) use ($pair): void {
                    $inner->where('product_id', $pair['product_id'])
                        ->where('warehouse_id', $pair['warehouse_id']);
                });
            }
        });
    }

    private function resolveActor(): string
    {
        $id = Auth::id();

        return $id !== null ? 'user:'.$id : 'system';
    }

    /**
     * Refuse to post outside a transaction.
     *
     * Without this, a caller who forgot the transaction would still "work" in
     * testing and then corrupt stock the first time a shipment failed halfway.
     * Failing loudly and immediately is much cheaper than that.
     */
    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'InventoryLedger must be used inside a database transaction so that the movement and the cache update commit together.'
            );
        }
    }
}
