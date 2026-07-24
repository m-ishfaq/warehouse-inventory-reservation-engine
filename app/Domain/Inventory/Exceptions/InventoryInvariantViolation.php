<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

/**
 * The "this should be impossible" exception.
 *
 * Raised by the ledger when a posting would leave inventory in a state the
 * domain says cannot exist (negative stock, reserved exceeding on-hand). The DB
 * CHECK constraints would also catch these, but failing here gives a readable
 * error and a stack trace pointing at the caller instead of an opaque SQLSTATE.
 *
 * If this is ever thrown in production it means there is a bug in the engine,
 * not bad input — which is why it maps to 500 rather than 422.
 */
final class InventoryInvariantViolation extends InventoryException
{
    public static function negativeOnHand(int $productId, int $warehouseId, int $resulting): self
    {
        return new self(
            sprintf('Movement would drive on-hand stock negative (%d) for product %d in warehouse %d.', $resulting, $productId, $warehouseId),
            ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'resulting_on_hand' => $resulting],
        );
    }

    public static function negativeReserved(int $productId, int $warehouseId, int $resulting): self
    {
        return new self(
            sprintf('Movement would drive reserved stock negative (%d) for product %d in warehouse %d.', $resulting, $productId, $warehouseId),
            ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'resulting_reserved' => $resulting],
        );
    }

    public static function reservedExceedsOnHand(int $productId, int $warehouseId, int $reserved, int $onHand): self
    {
        return new self(
            sprintf('Movement would leave reserved (%d) above on-hand (%d) for product %d in warehouse %d.', $reserved, $onHand, $productId, $warehouseId),
            ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'reserved' => $reserved, 'on_hand' => $onHand],
        );
    }

    public function errorCode(): string
    {
        return 'inventory_invariant_violation';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
