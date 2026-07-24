<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Enums\OrderStatus;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Enums\ShipmentStatus;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Inventory\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInventoryFixtures;
use Tests\TestCase;

/**
 * Business rule we chose: shipping N of a reserved M consumes N and leaves M-N
 * reserved and open.
 *
 * The alternative — closing the whole reservation on first shipment — would
 * quietly drop the remainder, and the customer would never receive goods the
 * system believes it sent.
 */
class PartialShipmentTest extends TestCase
{
    use BuildsInventoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory(onHand: 20, qtyOrdered: 10);
    }

    public function test_shipping_part_of_a_reservation_keeps_the_remainder_held(): void
    {
        $reservation = $this->reserve(10);
        $shipment = $this->dispatchedShipment($reservation, 4);

        app(ShipmentService::class)->confirm($shipment, [$reservation->id => 4], 'evt-partial');

        // 4 gone from on-hand, 6 still promised.
        $this->assertStock(onHand: 16, reserved: 6);

        $reservation->refresh();
        $this->assertSame(4, $reservation->qty_consumed);
        $this->assertSame(6, $reservation->qtyOutstanding());
        $this->assertSame(ReservationStatus::PartiallyConsumed, $reservation->status);
    }

    public function test_the_remainder_can_ship_on_a_later_shipment(): void
    {
        $reservation = $this->reserve(10);

        $first = $this->dispatchedShipment($reservation, 4);
        app(ShipmentService::class)->confirm($first, [$reservation->id => 4], 'evt-a');

        $second = $this->dispatchedShipment($reservation->refresh(), 6);
        app(ShipmentService::class)->confirm($second, [$reservation->id => 6], 'evt-b');

        $this->assertStock(onHand: 10, reserved: 0);
        $this->assertSame(ReservationStatus::Consumed, $reservation->refresh()->status);
    }

    public function test_a_shipment_that_ships_less_than_requested_is_partially_shipped(): void
    {
        $reservation = $this->reserve(10);
        $shipment = $this->dispatchedShipment($reservation, 10);

        // Carrier scanned only 7 of the 10 units onto the truck.
        app(ShipmentService::class)->confirm($shipment, [$reservation->id => 7], 'evt-short');

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::PartiallyShipped, $shipment->status);
        // MySQL returns SUM() as a string; cast before an identity comparison.
        $this->assertSame(7, (int) $shipment->items()->sum('qty_shipped'));

        $this->assertStock(onHand: 13, reserved: 3);
    }

    public function test_a_partially_shipped_shipment_can_be_completed_later(): void
    {
        $reservation = $this->reserve(10);
        $shipment = $this->dispatchedShipment($reservation, 10);

        app(ShipmentService::class)->confirm($shipment, [$reservation->id => 7], 'evt-part-1');
        app(ShipmentService::class)->confirm($shipment->refresh(), [$reservation->id => 3], 'evt-part-2');

        $this->assertSame(ShipmentStatus::Shipped, $shipment->refresh()->status);
        $this->assertStock(onHand: 10, reserved: 0);
    }

    public function test_a_shipment_can_never_consume_more_than_the_reservation_holds(): void
    {
        $reservation = $this->reserve(5);
        $shipment = $this->dispatchedShipment($reservation, 5);

        // The carrier claims it shipped 50. It did not, and cannot.
        app(ShipmentService::class)->confirm($shipment, [$reservation->id => 50], 'evt-liar');

        $this->assertStock(onHand: 15, reserved: 0);
        $this->assertSame(5, $reservation->refresh()->qty_consumed);
    }

    public function test_the_remainder_can_be_released_after_a_partial_shipment(): void
    {
        $reservation = $this->reserve(10);
        $shipment = $this->dispatchedShipment($reservation, 4);
        app(ShipmentService::class)->confirm($shipment, [$reservation->id => 4], 'evt-p');

        $released = app(ReservationService::class)->release($reservation->refresh(), reason: 'backorder_cancelled');

        $this->assertSame(6, $released);
        $this->assertStock(onHand: 16, reserved: 0);

        // Stock genuinely moved, so the reservation is Consumed rather than
        // Released even though part of it came back.
        $this->assertSame(ReservationStatus::Consumed, $reservation->refresh()->status);
    }

    public function test_order_status_follows_the_lines(): void
    {
        $reservation = $this->reserve(10);
        $this->assertSame(OrderStatus::Reserved, $this->order->refresh()->status);

        $shipment = $this->dispatchedShipment($reservation, 4);
        app(ShipmentService::class)->confirm($shipment, [$reservation->id => 4], 'evt-status');

        $this->assertSame(OrderStatus::PartiallyShipped, $this->order->refresh()->status);

        $second = $this->dispatchedShipment($reservation->refresh(), 6);
        app(ShipmentService::class)->confirm($second, [$reservation->id => 6], 'evt-status-2');

        $this->assertSame(OrderStatus::Fulfilled, $this->order->refresh()->status);
    }
}
