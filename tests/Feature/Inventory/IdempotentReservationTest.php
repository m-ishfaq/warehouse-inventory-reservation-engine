<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Enums\IdempotencyStatus;
use App\Domain\Inventory\Services\IdempotencyGuard;
use App\Domain\Inventory\Services\ReservationService;
use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsInventoryFixtures;
use Tests\TestCase;

/**
 * Scenario: "the reservation command runs twice" and "the same background job
 * executes multiple times".
 *
 * Both reduce to the same question — does an operation replayed with the same
 * idempotency key do its work twice?
 */
class IdempotentReservationTest extends TestCase
{
    use BuildsInventoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory(onHand: 10);
    }

    public function test_replaying_a_reservation_with_the_same_key_does_no_further_work(): void
    {
        $first = app(ReservationService::class)->reserve(
            order: $this->order,
            lines: [$this->line(3)],
            idempotencyKey: 'order-99-reserve',
        );

        $second = app(ReservationService::class)->reserve(
            order: $this->order,
            lines: [$this->line(3)],
            idempotencyKey: 'order-99-reserve',
        );

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);

        // Same reservation returned, not a new one.
        $this->assertSame($first->reservationIds, $second->reservationIds);

        $this->assertDatabaseCount('reservations', 1);
        $this->assertStock(onHand: 10, reserved: 3);
    }

    public function test_different_keys_produce_independent_reservations(): void
    {
        app(ReservationService::class)->reserve(
            order: $this->order,
            lines: [$this->line(3)],
            idempotencyKey: 'key-a',
        );

        app(ReservationService::class)->reserve(
            order: $this->order,
            lines: [$this->line(3)],
            idempotencyKey: 'key-b',
        );

        $this->assertDatabaseCount('reservations', 2);
        $this->assertStock(onHand: 10, reserved: 6);
    }

    public function test_the_same_key_in_a_different_scope_is_not_a_collision(): void
    {
        $guard = app(IdempotencyGuard::class);

        $a = $guard->execute('scope.one', 'shared-key', fn (): array => ['value' => 'first']);
        $b = $guard->execute('scope.two', 'shared-key', fn (): array => ['value' => 'second']);

        $this->assertSame('first', $a->payload['value']);
        $this->assertSame('second', $b->payload['value']);
        $this->assertFalse($b->replayed);
    }

    public function test_a_failed_operation_leaves_no_claim_behind(): void
    {
        $guard = app(IdempotencyGuard::class);

        try {
            $guard->execute('scope.fail', 'doomed-key', function (): array {
                throw new RuntimeException('boom');
            });
            $this->fail('Expected the exception to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        // The claim row was written inside the transaction that rolled back, so
        // it must be gone — otherwise the retry would be permanently blocked by
        // a claim for work that never happened.
        $this->assertDatabaseCount('idempotency_keys', 0);

        $retry = $guard->execute('scope.fail', 'doomed-key', fn (): array => ['ok' => true]);

        $this->assertFalse($retry->replayed);
        $this->assertTrue($retry->payload['ok']);
    }

    public function test_a_completed_claim_stores_its_response_for_replay(): void
    {
        app(ReservationService::class)->reserve(
            order: $this->order,
            lines: [$this->line(2)],
            idempotencyKey: 'stored-response',
        );

        $record = IdempotencyKey::query()
            ->where('scope', ReservationService::SCOPE_RESERVE)
            ->where('key', 'stored-response')
            ->firstOrFail();

        $this->assertSame(IdempotencyStatus::Completed, $record->status);
        $this->assertNotNull($record->completed_at);
        $this->assertIsArray($record->response);
        $this->assertArrayHasKey('reservation_ids', $record->response);
    }

    public function test_operations_without_a_key_are_not_deduplicated(): void
    {
        // Explicit: omitting the key is opting out. The API documents
        // Idempotency-Key as strongly recommended for exactly this reason.
        $this->reserve(2);
        $this->reserve(2);

        $this->assertDatabaseCount('reservations', 2);
        $this->assertStock(onHand: 10, reserved: 4);
    }

    private function line(int $qty): \App\Domain\Inventory\DTOs\ReservationLine
    {
        return new \App\Domain\Inventory\DTOs\ReservationLine(
            (int) $this->orderItem->id,
            (int) $this->product->id,
            (int) $this->warehouse->id,
            $qty,
        );
    }
}
