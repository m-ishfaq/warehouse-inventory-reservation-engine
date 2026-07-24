<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

/**
 * Raised when a reservation cannot be satisfied from available stock.
 *
 * Deliberately carries the numbers rather than just a message: when two users
 * race for the last item, the loser's client needs to know how much *is*
 * available so it can offer a partial order instead of just failing.
 */
final class InsufficientStockException extends InventoryException
{
    public static function for(
        int $productId,
        int $warehouseId,
        int $requested,
        int $available,
    ): self {
        return new self(
            sprintf(
                'Insufficient stock for product %d in warehouse %d: requested %d, available %d.',
                $productId,
                $warehouseId,
                $requested,
                $available,
            ),
            [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'requested' => $requested,
                'available' => $available,
                'shortfall' => max(0, $requested - $available),
            ],
        );
    }

    public function errorCode(): string
    {
        return 'insufficient_stock';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
