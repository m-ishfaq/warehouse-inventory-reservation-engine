<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Every way inventory can move.
 *
 * The ledger stores explicit signed deltas per movement rather than deriving
 * them from the type, so reconciliation never has to interpret business meaning.
 * The helpers below exist for validation and display only — they describe what a
 * given type is *expected* to do, and the ledger asserts the caller agrees.
 */
enum MovementType: string
{
    /** Goods physically arrive. on_hand +qty */
    case Receipt = 'receipt';

    /** A claim is placed on stock. reserved +qty */
    case Reserve = 'reserve';

    /** A claim is handed back voluntarily. reserved -qty */
    case Release = 'release';

    /** A claim is handed back by the TTL sweep. reserved -qty */
    case Expire = 'expire';

    /** Stock leaves on a confirmed shipment. on_hand -qty, reserved -qty */
    case Ship = 'ship';

    /** Stock leaves one warehouse in a transfer. on_hand -qty */
    case TransferOut = 'transfer_out';

    /** Stock arrives at the other warehouse in a transfer. on_hand +qty */
    case TransferIn = 'transfer_in';

    /** Manual correction (stock take, damage, shrinkage). on_hand ±qty */
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Goods receipt',
            self::Reserve => 'Reserved',
            self::Release => 'Reservation released',
            self::Expire => 'Reservation expired',
            self::Ship => 'Shipped',
            self::TransferOut => 'Transfer out',
            self::TransferIn => 'Transfer in',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * Does this movement change physical stock?
     */
    public function touchesOnHand(): bool
    {
        return match ($this) {
            self::Receipt, self::Ship, self::TransferOut, self::TransferIn, self::Adjustment => true,
            self::Reserve, self::Release, self::Expire => false,
        };
    }

    /**
     * Does this movement change the reserved bucket?
     */
    public function touchesReserved(): bool
    {
        return match ($this) {
            self::Reserve, self::Release, self::Expire, self::Ship => true,
            self::Receipt, self::TransferOut, self::TransferIn, self::Adjustment => false,
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Receipt, self::TransferIn => 'bg-success',
            self::Reserve => 'bg-primary',
            self::Release, self::Expire => 'bg-secondary',
            self::Ship, self::TransferOut => 'bg-danger',
            self::Adjustment => 'bg-warning text-dark',
        };
    }
}
