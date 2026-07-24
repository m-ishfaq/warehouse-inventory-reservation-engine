<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

/**
 * Raised when an identical operation is still in flight on another connection.
 *
 * 409 rather than 500: the caller did nothing wrong, they simply arrived while
 * their own earlier request was mid-execution. The correct client behaviour is
 * to back off and retry the same idempotency key, which will then replay the
 * stored response.
 */
final class IdempotencyConflictException extends InventoryException
{
    public static function inFlight(string $scope, string $key): self
    {
        return new self(
            sprintf('An operation with idempotency key "%s" is already in progress for scope "%s".', $key, $scope),
            ['scope' => $scope, 'key' => $key],
        );
    }

    public function errorCode(): string
    {
        return 'idempotency_conflict';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
