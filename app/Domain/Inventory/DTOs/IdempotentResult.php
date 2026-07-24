<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

/**
 * What the idempotency guard hands back.
 *
 * `replayed` matters to callers: a replayed reservation must NOT re-emit domain
 * events or re-queue jobs, or an at-least-once retry would still produce
 * at-least-once side effects — which is the whole thing we are preventing.
 */
final readonly class IdempotentResult
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public bool $replayed,
        public array $payload,
    ) {}

    public function isFresh(): bool
    {
        return ! $this->replayed;
    }
}
