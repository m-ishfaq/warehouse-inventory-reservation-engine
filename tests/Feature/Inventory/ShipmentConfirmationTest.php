<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Enums\ShipmentStatus;
use App\Domain\Inventory\Exceptions\ShipmentTimeoutException;
use App\Domain\Inventory\Services\ShipmentService;
use App\Models\ProcessedWebhook;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInventoryFixtures;
use Tests\TestCase;

/**
 * Scenarios: duplicate shipment webhook, worker retry after failure, shipment
 * timeout followed by confirmation.
 *
 * The design decision under test throughout: inventory is deducted on
 * CONFIRMATION, never on dispatch.
 */
class ShipmentConfirmationTest extends TestCase
{
    use BuildsInventoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory(onHand: 10);
    }

    public function test_confirmation_deducts_stock_and_consumes_the_reservation(): void
    {
        $reservation = $this->reserve(4);
        $shipment = $this->dispatchedShipment($reservation, 4);

        // Dispatched but unconfirmed: stock is still on hand, still reserved.
        $this->assertStock(onHand: 10, reserved: 4);

        app(ShipmentService::class)->confirm($shipment, [$reservation->id => 4], 'evt-1');

        $this->assertStock(onHand: 6, reserved: 0);
        $this->assertSame(ReservationStatus::Consumed, $reservation->refresh()->status);
        $this->assertSame(ShipmentStatus::Shipped, $shipment->refresh()->status);
        $this->assertNotNull($shipment->confirmed_at);
    }

    public function test_a_duplicate_confirmation_deducts_nothing_further(): void
    {
        $reservation = $this->reserve(4);
        $shipment = $this->dispatchedShipment($reservation, 4);

        $first = app(ShipmentService::class)->confirm($shipment, [$reservation->id => 4], 'evt-dup');
        $second = app(ShipmentService::class)->confirm($shipment->refresh(), [$reservation->id => 4], 'evt-dup');

        $this->assertFalse($first['replayed']);
        $this->assertSame(4, $first['shipped']);

        // A replay returns the ORIGINAL response, it does not return zero. That
        // is the point of idempotency: the caller who retried gets the same
        // answer their first attempt would have received, so they cannot tell
        // whether the retry was needed — and must not conclude "0 shipped".
        $this->assertTrue($second['replayed']);
        $this->assertSame(4, $second['shipped']);

        // The assertion that actually proves nothing happened twice: stock moved
        // once, and one event was recorded.
        $this->assertStock(onHand: 6, reserved: 0);
        $this->assertDatabaseCount('processed_webhooks', 1);
        $this->assertSame(1, \App\Models\InventoryMovement::query()->where('type', 'ship')->count());
    }

    public function test_a_duplicate_event_id_is_recorded_only_once(): void
    {
        $reservation = $this->reserve(2);
        $shipment = $this->dispatchedShipment($reservation, 2);

        app(ShipmentService::class)->confirm($shipment, [$reservation->id => 2], 'evt-once');
        app(ShipmentService::class)->confirm($shipment->refresh(), [$reservation->id => 2], 'evt-once');

        $this->assertSame(1, ProcessedWebhook::query()->where('provider_event_id', 'evt-once')->count());
    }

    public function test_a_worker_rerun_after_a_crash_does_not_double_deduct(): void
    {
        $reservation = $this->reserve(3);
        $shipment = $this->dispatchedShipment($reservation, 3);

        // Worker A does the work then dies before acking the job.
        app(ShipmentService::class)->confirm($shipment, [$reservation->id => 3], 'evt-crash');
        $this->assertStock(onHand: 7, reserved: 0);

        // Queue redelivers; worker B runs the identical operation.
        app(ShipmentService::class)->confirm($shipment->refresh(), [$reservation->id => 3], 'evt-crash');

        $this->assertStock(onHand: 7, reserved: 0);
    }

    public function test_a_timeout_leaves_the_shipment_recoverable_and_stock_untouched(): void
    {
        config(['inventory.shipping.mock.forced_outcome' => 'timeout']);

        $reservation = $this->reserve(3);
        $shipment = app(ShipmentService::class)->createShipment(
            $this->order,
            $this->warehouse,
            [(int) $reservation->id => 3],
        );

        try {
            app(ShipmentService::class)->process($shipment);
            $this->fail('Expected ShipmentTimeoutException.');
        } catch (ShipmentTimeoutException) {
            // expected — the queue will retry
        }

        $shipment->refresh();

        // Parked in `dispatched`, NOT rolled back to pending: if the carrier did
        // take the goods, a late confirmation must still be able to settle it.
        $this->assertSame(ShipmentStatus::Dispatched, $shipment->status);
        $this->assertNotNull($shipment->provider_ref);
        $this->assertStock(onHand: 10, reserved: 3);
    }

    public function test_a_late_confirmation_after_a_timeout_still_settles_correctly(): void
    {
        config(['inventory.shipping.mock.forced_outcome' => 'timeout']);

        $reservation = $this->reserve(3);
        $shipment = app(ShipmentService::class)->createShipment(
            $this->order,
            $this->warehouse,
            [(int) $reservation->id => 3],
        );

        try {
            app(ShipmentService::class)->process($shipment);
        } catch (ShipmentTimeoutException) {
            // expected
        }

        app(ShipmentService::class)->confirm($shipment->refresh(), [$reservation->id => 3], 'evt-late');

        $this->assertStock(onHand: 7, reserved: 0);
        $this->assertSame(ShipmentStatus::Shipped, $shipment->refresh()->status);
    }

    public function test_a_permanent_carrier_rejection_does_not_release_the_reservation(): void
    {
        config(['inventory.shipping.mock.forced_outcome' => 'permanent_failure']);

        $reservation = $this->reserve(3);
        $shipment = app(ShipmentService::class)->createShipment(
            $this->order,
            $this->warehouse,
            [(int) $reservation->id => 3],
        );

        app(ShipmentService::class)->process($shipment);

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Failed, $shipment->status);
        $this->assertNotNull($shipment->last_error);

        // The goods are still promised to this order. Whether to free them is a
        // commercial decision, not something a failed API call should make.
        $this->assertStock(onHand: 10, reserved: 3);
        $this->assertSame(ReservationStatus::Active, $reservation->refresh()->status);
    }

    public function test_the_provider_duplicate_confirmation_outcome_deducts_once(): void
    {
        config(['inventory.shipping.mock.forced_outcome' => 'duplicate_confirmation']);

        $reservation = $this->reserve(5);
        $shipment = app(ShipmentService::class)->createShipment(
            $this->order,
            $this->warehouse,
            [(int) $reservation->id => 5],
        );

        // The mock provider deliberately delivers the same event twice.
        app(ShipmentService::class)->process($shipment);

        $this->assertStock(onHand: 5, reserved: 0);
    }

    public function test_two_workers_racing_the_same_shipment_dispatch_it_once(): void
    {
        config(['inventory.shipping.mock.forced_outcome' => 'success']);

        $reservation = $this->reserve(2);
        $shipment = app(ShipmentService::class)->createShipment(
            $this->order,
            $this->warehouse,
            [(int) $reservation->id => 2],
        );

        app(ShipmentService::class)->process($shipment);

        // Second worker picks up the same job. The conditional status claim
        // matches zero rows, so it does nothing at all.
        app(ShipmentService::class)->process($shipment->refresh());

        $this->assertStock(onHand: 8, reserved: 0);
        $this->assertSame(1, Shipment::query()->whereKey($shipment->id)->value('attempts'));
    }
}
