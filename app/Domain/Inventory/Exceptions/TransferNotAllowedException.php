<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

/**
 * Raised when a warehouse transfer would move stock that is already promised.
 *
 * This encodes one of the quest's intentionally-undefined rules: reserved stock
 * is pinned to its warehouse. Only the unreserved surplus may move. See
 * docs/ARCHITECTURE.md for why this beats silently re-pointing reservations.
 */
final class TransferNotAllowedException extends InventoryException
{
    public static function reservedStock(
        int $productId,
        int $fromWarehouseId,
        int $requested,
        int $transferable,
    ): self {
        return new self(
            sprintf(
                'Cannot transfer %d of product %d out of warehouse %d: only %d is free of reservations.',
                $requested,
                $productId,
                $fromWarehouseId,
                $transferable,
            ),
            [
                'product_id' => $productId,
                'from_warehouse_id' => $fromWarehouseId,
                'requested' => $requested,
                'transferable' => $transferable,
            ],
        );
    }

    public static function sameWarehouse(int $warehouseId): self
    {
        return new self(
            sprintf('Source and destination warehouse are identical (%d).', $warehouseId),
            ['warehouse_id' => $warehouseId],
        );
    }

    public function errorCode(): string
    {
        return 'transfer_not_allowed';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
