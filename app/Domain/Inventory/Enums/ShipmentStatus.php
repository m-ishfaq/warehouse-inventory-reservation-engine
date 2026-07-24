<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Shipment lifecycle.
 *
 *   pending ──> dispatched ──┬─> shipped
 *      │            │        ├─> partially_shipped ──> shipped
 *      │            └────────┴─> failed ──> dispatched   (retry)
 *      └─> cancelled
 *
 * `dispatched` means "handed to the provider, outcome unknown". Inventory is
 * deliberately NOT deducted here — only on confirmation. A shipment stranded in
 * `dispatched` by a provider timeout therefore costs a held reservation, never
 * lost or double-counted stock, and a late confirmation can still settle it.
 */
enum ShipmentStatus: string
{
    case Pending = 'pending';
    case Dispatched = 'dispatched';
    case Shipped = 'shipped';
    case PartiallyShipped = 'partially_shipped';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Dispatched, self::Failed, self::Cancelled],
            self::Dispatched => [self::Shipped, self::PartiallyShipped, self::Failed],
            self::PartiallyShipped => [self::Shipped, self::PartiallyShipped, self::Failed, self::Cancelled],
            self::Failed => [self::Dispatched, self::Cancelled],
            self::Shipped, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /**
     * Has this shipment reached a state where inventory was consumed?
     */
    public function hasConsumedInventory(): bool
    {
        return match ($this) {
            self::Shipped, self::PartiallyShipped => true,
            default => false,
        };
    }

    public function isRetryable(): bool
    {
        return $this === self::Failed || $this === self::Dispatched;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Dispatched => 'Dispatched (awaiting confirmation)',
            self::Shipped => 'Shipped',
            self::PartiallyShipped => 'Partially shipped',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'bg-secondary',
            self::Dispatched => 'bg-info text-dark',
            self::Shipped => 'bg-success',
            self::PartiallyShipped => 'bg-primary',
            self::Failed => 'bg-danger',
            self::Cancelled => 'bg-dark',
        };
    }
}
