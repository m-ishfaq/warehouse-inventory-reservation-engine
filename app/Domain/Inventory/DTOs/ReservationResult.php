<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

/**
 * Outcome of a reservation attempt.
 *
 * `shortfalls` is populated only in partial mode. In strict (default) mode an
 * unsatisfiable line throws instead, because silently under-reserving an order
 * is how ERP systems end up shipping the wrong quantities.
 */
final readonly class ReservationResult
{
    /**
     * @param list<int> $reservationIds
     * @param list<array{product_id:int, warehouse_id:int, requested:int, reserved:int, shortfall:int}> $shortfalls
     */
    public function __construct(
        public array $reservationIds,
        public array $shortfalls = [],
        public bool $replayed = false,
    ) {}

    public function isComplete(): bool
    {
        return $this->shortfalls === [];
    }

    public function withReplayFlag(bool $replayed): self
    {
        return new self($this->reservationIds, $this->shortfalls, $replayed);
    }

    /**
     * @return array{reservation_ids: list<int>, shortfalls: list<array<string, int>>, complete: bool}
     */
    public function toArray(): array
    {
        return [
            'reservation_ids' => $this->reservationIds,
            'shortfalls' => $this->shortfalls,
            'complete' => $this->isComplete(),
        ];
    }

    /**
     * @param array{reservation_ids?: list<int>, shortfalls?: list<array<string, int>>} $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            reservationIds: array_map('intval', $payload['reservation_ids'] ?? []),
            shortfalls: $payload['shortfalls'] ?? [],
        );
    }
}
