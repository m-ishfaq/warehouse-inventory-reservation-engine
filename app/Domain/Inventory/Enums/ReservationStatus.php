<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Reservation lifecycle.
 *
 *   active ──┬─> partially_consumed ──┬─> consumed      (stock shipped)
 *            │                        ├─> released      (remainder handed back)
 *            │                        └─> expired       (TTL swept the remainder)
 *            ├─> consumed
 *            ├─> released
 *            └─> expired
 *
 * consumed / released / expired are terminal. Keeping the transition table here
 * rather than in the service means an illegal transition is impossible to write
 * by accident anywhere in the codebase, including from a future controller or
 * console command that has not been written yet.
 */
enum ReservationStatus: string
{
    case Active = 'active';
    case PartiallyConsumed = 'partially_consumed';
    case Consumed = 'consumed';
    case Released = 'released';
    case Expired = 'expired';

    /**
     * Statuses that still hold stock and therefore still block availability.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Active, self::PartiallyConsumed => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::PartiallyConsumed, self::Consumed, self::Released, self::Expired],
            self::PartiallyConsumed => [self::PartiallyConsumed, self::Consumed, self::Released, self::Expired],
            self::Consumed, self::Released, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::PartiallyConsumed => 'Partially consumed',
            self::Consumed => 'Consumed',
            self::Released => 'Released',
            self::Expired => 'Expired',
        };
    }

    /**
     * Bootstrap-ready badge class for the dashboard.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'bg-primary',
            self::PartiallyConsumed => 'bg-info text-dark',
            self::Consumed => 'bg-success',
            self::Released => 'bg-secondary',
            self::Expired => 'bg-warning text-dark',
        };
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isOpen()),
        );
    }
}
