<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\TransferNotAllowedException;
use App\Models\Product;
use App\Models\Warehouse;
use InvalidArgumentException;

/**
 * Move stock between warehouses.
 *
 * The quest leaves "can inventory be transferred while reserved?" open. Our
 * answer: only the unreserved surplus may move. Reserved stock is pinned to the
 * warehouse that promised it.
 *
 * The alternative — re-pointing reservations at the destination — was rejected
 * deliberately. It sounds more flexible but it silently changes which warehouse
 * will pick an order, which breaks carrier routing, delivery estimates, and any
 * pick list already printed on the floor. Refusing the transfer surfaces the
 * conflict to a human who can decide, instead of quietly relocating a promise.
 * See docs/ARCHITECTURE.md.
 */
final class InventoryTransferService
{
    public const SCOPE_TRANSFER = 'inventory.transfer';

    public function __construct(
        private readonly InventoryLedger $ledger,
        private readonly IdempotencyGuard $idempotency,
    ) {}

    /**
     * @return array{transferred: int, from_available: int, to_available: int}
     */
    public function transfer(
        Product $product,
        Warehouse $from,
        Warehouse $to,
        int $qty,
        ?string $idempotencyKey = null,
        ?string $reason = null,
    ): array {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Transfer quantity must be greater than zero.');
        }

        if ($from->getKey() === $to->getKey()) {
            throw TransferNotAllowedException::sameWarehouse((int) $from->getKey());
        }

        $result = $this->idempotency->execute(
            self::SCOPE_TRANSFER,
            $idempotencyKey,
            function () use ($product, $from, $to, $qty, $reason): array {
                // Both positions locked in one call, so ordering is handled for
                // us. Locking source then destination by hand would let a
                // simultaneous reverse transfer deadlock against us.
                $locked = $this->ledger->lockPairs([
                    ['product_id' => $product->getKey(), 'warehouse_id' => $from->getKey()],
                    ['product_id' => $product->getKey(), 'warehouse_id' => $to->getKey()],
                ]);

                $source = $locked[$product->getKey().':'.$from->getKey()];
                $destination = $locked[$product->getKey().':'.$to->getKey()];

                // Transferable is availability, NOT on-hand: shipping the goods
                // somebody else has been promised is the same bug as overselling,
                // just with an extra step.
                $transferable = $source->availableQty();

                if ($transferable < $qty) {
                    throw TransferNotAllowedException::reservedStock(
                        (int) $product->getKey(),
                        (int) $from->getKey(),
                        $qty,
                        max(0, $transferable),
                    );
                }

                $context = array_filter([
                    'reason' => $reason,
                    'from_warehouse_id' => $from->getKey(),
                    'to_warehouse_id' => $to->getKey(),
                ]);

                $this->ledger->transferOut($source, $qty, $product, $context);
                $this->ledger->transferIn($destination, $qty, $product, $context);

                return [
                    'transferred' => $qty,
                    'from_available' => $source->availableQty(),
                    'to_available' => $destination->availableQty(),
                ];
            },
        );

        return [
            'transferred' => (int) ($result->payload['transferred'] ?? 0),
            'from_available' => (int) ($result->payload['from_available'] ?? 0),
            'to_available' => (int) ($result->payload['to_available'] ?? 0),
        ];
    }
}
