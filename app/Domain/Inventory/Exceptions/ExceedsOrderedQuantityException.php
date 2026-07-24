<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

/**
 * Raised when a reservation would claim more than the order line actually ordered.
 *
 * Distinct from InsufficientStockException on purpose. They answer different
 * questions and imply different fixes:
 *
 *   insufficient_stock       "the warehouse does not have it"    → wait, backorder,
 *                                                                  or source elsewhere
 *   exceeds_ordered_quantity "the customer did not ask for it"   → fix the request,
 *                                                                  or amend the order
 *
 * Collapsing them into one error would tell a client to retry later for a
 * mistake that will never resolve on its own.
 */
final class ExceedsOrderedQuantityException extends InventoryException
{
    public static function for(
        int $orderItemId,
        int $requested,
        int $outstanding,
        int $qtyOrdered,
    ): self {
        return new self(
            sprintf(
                'Order line %d has %d unit(s) left to allocate (ordered %d): cannot reserve %d.',
                $orderItemId,
                $outstanding,
                $qtyOrdered,
                $requested,
            ),
            [
                'order_item_id' => $orderItemId,
                'requested' => $requested,
                'outstanding' => $outstanding,
                'qty_ordered' => $qtyOrdered,
                'excess' => max(0, $requested - $outstanding),
            ],
        );
    }

    public function errorCode(): string
    {
        return 'exceeds_ordered_quantity';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
