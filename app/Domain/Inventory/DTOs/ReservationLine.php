<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use InvalidArgumentException;

/**
 * One requested claim: "hold N of this product in this warehouse for this line".
 *
 * Immutable and self-validating, so an impossible request (zero quantity, wrong
 * warehouse) is rejected at the boundary rather than halfway through a
 * transaction that has already taken locks.
 */
final readonly class ReservationLine
{
    public function __construct(
        public int $orderItemId,
        public int $productId,
        public int $warehouseId,
        public int $qty,
    ) {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Reservation quantity must be greater than zero.');
        }
    }

    /**
     * @param array{order_item_id:int, product_id:int, warehouse_id:int, qty:int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            orderItemId: (int) $data['order_item_id'],
            productId: (int) $data['product_id'],
            warehouseId: (int) $data['warehouse_id'],
            qty: (int) $data['qty'],
        );
    }

    /**
     * Stable identity of the inventory row this line touches. Used to group
     * multiple lines that hit the same stock position so they are locked once.
     */
    public function pairKey(): string
    {
        return $this->productId.':'.$this->warehouseId;
    }

    /**
     * @return array{order_item_id:int, product_id:int, warehouse_id:int, qty:int}
     */
    public function toArray(): array
    {
        return [
            'order_item_id' => $this->orderItemId,
            'product_id' => $this->productId,
            'warehouse_id' => $this->warehouseId,
            'qty' => $this->qty,
        ];
    }
}
