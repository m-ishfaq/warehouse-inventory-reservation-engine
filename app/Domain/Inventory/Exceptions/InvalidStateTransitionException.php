<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use BackedEnum;

/**
 * Raised when something attempts an illegal lifecycle move — releasing an
 * already-consumed reservation, confirming a cancelled shipment, and so on.
 *
 * This is the guard that makes duplicate webhooks and re-run jobs safe *even if*
 * the idempotency layer were bypassed: a second confirmation finds the shipment
 * already in a terminal state and is rejected rather than deducting stock twice.
 */
final class InvalidStateTransitionException extends InventoryException
{
    public static function between(string $subject, BackedEnum $from, BackedEnum $to): self
    {
        return new self(
            sprintf('%s cannot transition from "%s" to "%s".', $subject, (string) $from->value, (string) $to->value),
            [
                'subject' => $subject,
                'from' => $from->value,
                'to' => $to->value,
            ],
        );
    }

    public function errorCode(): string
    {
        return 'invalid_state_transition';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
