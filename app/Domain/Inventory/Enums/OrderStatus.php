<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case PartiallyReserved = 'partially_reserved';
    case Reserved = 'reserved';
    case PartiallyShipped = 'partially_shipped';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return match ($this) {
            self::Fulfilled, self::Cancelled => false,
            default => true,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PartiallyReserved => 'Partially reserved',
            self::Reserved => 'Reserved',
            self::PartiallyShipped => 'Partially shipped',
            self::Fulfilled => 'Fulfilled',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-secondary',
            self::PartiallyReserved => 'bg-info text-dark',
            self::Reserved => 'bg-primary',
            self::PartiallyShipped => 'bg-warning text-dark',
            self::Fulfilled => 'bg-success',
            self::Cancelled => 'bg-dark',
        };
    }
}
