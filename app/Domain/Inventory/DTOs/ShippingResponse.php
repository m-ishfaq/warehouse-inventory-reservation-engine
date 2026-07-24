<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Enums\ShippingOutcome;

/**
 * What a shipping provider tells us happened.
 *
 * `shippedQuantities` is keyed by reservation id, which is how partial
 * shipments arrive in the real world: the carrier scanned 3 of the 5 units on
 * the line and the rest stayed on the dock.
 */
final readonly class ShippingResponse
{
    /**
     * @param array<int, int> $shippedQuantities reservation id => qty actually shipped
     */
    public function __construct(
        public ShippingOutcome $outcome,
        public ?string $providerRef = null,
        public array $shippedQuantities = [],
        public ?string $eventId = null,
        public ?string $error = null,
        /**
         * Provider behaviour flag: the confirmation will arrive twice. The engine
         * must produce identical inventory either way.
         */
        public bool $sendsDuplicateConfirmation = false,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->outcome->isSuccessful();
    }

    public function totalShipped(): int
    {
        return array_sum($this->shippedQuantities);
    }
}
