<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * Base for every domain failure the inventory engine can raise.
 *
 * Carrying a stable machine-readable code means the HTTP layer can map domain
 * failures to status codes without a growing instanceof ladder, and API clients
 * can branch on the code rather than on message text.
 */
abstract class InventoryException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    abstract public function errorCode(): string;

    /**
     * HTTP status this failure should surface as.
     */
    public function httpStatus(): int
    {
        return 422;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => $this->errorCode(),
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}
