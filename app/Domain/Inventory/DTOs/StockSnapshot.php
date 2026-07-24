<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

/**
 * The answer to "what is the stock position right now".
 *
 * Reports available / reserved / on-hand / shipped-to-date. It deliberately does
 * NOT report picked or packed.
 *
 * The brief lists Picked and Packed as business stages, and the schema carries
 * columns and CHECK constraints for them — but this engine implements
 * reserve → ship only, so both would be permanently zero. Publishing a field
 * that is structurally always 0 is worse than omitting it: it reads as a working
 * feature returning no data, rather than as scope that was consciously not
 * built. See docs/ARCHITECTURE.md for what implementing them would require.
 */
final readonly class StockSnapshot
{
    public function __construct(
        public int $productId,
        public string $productSku,
        public int $warehouseId,
        public string $warehouseCode,
        public int $onHand,
        public int $reserved,
        public int $shippedToDate,
    ) {}

    public function available(): int
    {
        return $this->onHand - $this->reserved;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'sku' => $this->productSku,
            'warehouse_id' => $this->warehouseId,
            'warehouse_code' => $this->warehouseCode,
            'on_hand' => $this->onHand,
            'available' => $this->available(),
            'reserved' => $this->reserved,
            'shipped_to_date' => $this->shippedToDate,
        ];
    }
}
