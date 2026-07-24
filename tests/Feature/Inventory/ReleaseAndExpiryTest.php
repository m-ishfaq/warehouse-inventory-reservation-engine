<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Services\ReservationService;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInventoryFixtures;
use Tests\TestCase;

/**
 * Scenarios: "warehouse operators cancel reservations" and "can reservations
 * expire?" — the rule we chose is yes, with a configurable TTL.
 */
class ReleaseAndExpiryTest extends TestCase
{
    use BuildsInventoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory(onHand: 10);
    }

    public function test_releasing_returns_stock_to_available(): void
    {
        $reservation = $this->reserve(6);
        $this->assertStock(onHand: 10, reserved: 6);

        $released = app(ReservationService::class)->release($reservation);

        $this->assertSame(6, $released);
        $this->assertStock(onHand: 10, reserved: 0);
        $this->assertSame(ReservationStatus::Released, $reservation->refresh()->status);
        $this->assertNotNull($reservation->closed_at);
    }

    public function test_releasing_twice_is_a_no_op_rather_than_an_error(): void
    {
        $reservation = $this->reserve(6);

        $first = app(ReservationService::class)->release($reservation);
        $second = app(ReservationService::class)->release($reservation->refresh());

        $this->assertSame(6, $first);

        // Converging silently, not throwing: a double-clicked cancel button or a
        // retried job must not become an error page or a second stock return.
        $this->assertSame(0, $second);
        $this->assertStock(onHand: 10, reserved: 0);
    }

    public function test_a_partial_release_keeps_the_reservation_open(): void
    {
        $reservation = $this->reserve(8);

        $released = app(ReservationService::class)->release($reservation, qty: 3);

        $this->assertSame(3, $released);
        $this->assertStock(onHand: 10, reserved: 5);

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Active, $reservation->status);
        $this->assertSame(5, $reservation->qtyOutstanding());
    }

    public function test_releasing_more_than_is_held_releases_only_what_is_held(): void
    {
        $reservation = $this->reserve(4);

        $released = app(ReservationService::class)->release($reservation, qty: 99);

        // Clamped, not throwing — and critically, not returning 99 units of
        // stock that were never reserved in the first place.
        $this->assertSame(4, $released);
        $this->assertStock(onHand: 10, reserved: 0);
    }

    public function test_the_sweep_releases_reservations_past_their_ttl(): void
    {
        $reservation = $this->reserve(5);

        Reservation::query()->whereKey($reservation->id)->update([
            'expires_at' => now()->subMinutes(5),
        ]);

        $result = app(ReservationService::class)->expireDue();

        $this->assertSame(1, $result['expired']);
        $this->assertSame(5, $result['released_qty']);
        $this->assertStock(onHand: 10, reserved: 0);
        $this->assertSame(ReservationStatus::Expired, $reservation->refresh()->status);
    }

    public function test_the_sweep_leaves_reservations_that_have_not_expired(): void
    {
        $this->reserve(5); // default TTL is in the future

        $result = app(ReservationService::class)->expireDue();

        $this->assertSame(0, $result['expired']);
        $this->assertStock(onHand: 10, reserved: 5);
    }

    public function test_reservations_with_no_expiry_are_never_swept(): void
    {
        $reservation = $this->reserve(5);

        Reservation::query()->whereKey($reservation->id)->update(['expires_at' => null]);

        $result = app(ReservationService::class)->expireDue();

        $this->assertSame(0, $result['expired']);
        $this->assertStock(onHand: 10, reserved: 5);
    }

    public function test_expiry_records_a_distinct_movement_type(): void
    {
        $reservation = $this->reserve(5);
        Reservation::query()->whereKey($reservation->id)->update(['expires_at' => now()->subMinute()]);

        app(ReservationService::class)->expireDue();

        // 'expire' rather than 'release', so an auditor can tell an abandoned
        // cart apart from an operator cancelling an order.
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->product->id,
            'type' => 'expire',
            'qty' => 5,
            'reserved_delta' => -5,
        ]);
    }

    public function test_the_sweep_respects_its_batch_limit(): void
    {
        foreach (range(1, 3) as $ignored) {
            $reservation = $this->reserve(1);
            Reservation::query()->whereKey($reservation->id)->update(['expires_at' => now()->subMinute()]);
        }

        $result = app(ReservationService::class)->expireDue(limit: 2);

        $this->assertSame(2, $result['expired']);
        $this->assertStock(onHand: 10, reserved: 1);
    }

    public function test_releasing_is_idempotent_by_key(): void
    {
        $reservation = $this->reserve(4);

        $first = app(ReservationService::class)->release($reservation, idempotencyKey: 'release-42');
        $second = app(ReservationService::class)->release($reservation->refresh(), idempotencyKey: 'release-42');

        $this->assertSame(4, $first);
        $this->assertSame(4, $second, 'A replay reports the original result.');
        $this->assertStock(onHand: 10, reserved: 0);
    }
}
