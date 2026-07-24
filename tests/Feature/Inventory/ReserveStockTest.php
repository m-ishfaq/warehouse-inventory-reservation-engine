<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\DTOs\ReservationLine;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Exceptions\ExceedsOrderedQuantityException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Services\ReservationService;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInventoryFixtures;
use Tests\TestCase;

/**
 * Business rule: available = on_hand - reserved, and it may never go negative.
 *
 * These tests protect the single most expensive failure this system can have —
 * promising stock that does not exist.
 */
class ReserveStockTest extends TestCase
{
    use BuildsInventoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory(onHand: 10);
    }

    public function test_reserving_holds_stock_without_removing_it(): void
    {
        $this->reserve(4);

        // On-hand is untouched: the goods are still physically in the warehouse,
        // they are simply spoken for. Only a confirmed shipment lowers on-hand.
        $this->assertStock(onHand: 10, reserved: 4);
    }

    public function test_reserving_the_exact_remaining_quantity_succeeds(): void
    {
        $this->reserve(10);

        $this->assertStock(onHand: 10, reserved: 10);
    }

    public function test_reserving_more_than_available_is_refused(): void
    {
        $this->expectException(InsufficientStockException::class);

        $this->reserve(11);
    }

    public function test_a_refused_reservation_leaves_no_trace(): void
    {
        try {
            $this->reserve(11);
        } catch (InsufficientStockException) {
            // expected
        }

        // The whole operation rolls back — no half-written reservation row, no
        // orphaned ledger movement.
        $this->assertStock(onHand: 10, reserved: 0);
        $this->assertDatabaseCount('reservations', 0);

        // Scoped to THIS stock position, not a global count. Classes that use
        // DatabaseTruncation commit their fixtures rather than rolling them back,
        // so a global count silently depends on which tests ran first — it would
        // pass alone and fail in a full run, which is the worst kind of test.
        $this->assertSame(
            1,
            InventoryMovement::query()
                ->forPair((int) $this->product->id, (int) $this->warehouse->id)
                ->count(),
            'Only the opening receipt should exist for this position.',
        );
    }

    public function test_second_reservation_cannot_claim_stock_the_first_already_holds(): void
    {
        $this->reserve(7);

        $this->expectException(InsufficientStockException::class);

        // 10 on hand, 7 already promised — only 3 are actually available.
        $this->reserve(4);
    }

    public function test_insufficient_stock_exception_reports_the_shortfall(): void
    {
        $this->reserve(8);

        try {
            $this->reserve(5);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException $exception) {
            // The client needs the numbers, not just a message — it may want to
            // offer the customer a partial order instead of failing outright.
            $this->assertSame(5, $exception->context['requested']);
            $this->assertSame(2, $exception->context['available']);
            $this->assertSame(3, $exception->context['shortfall']);
        }
    }

    public function test_partial_mode_grants_what_is_available_and_reports_the_gap(): void
    {
        $result = app(ReservationService::class)->reserve(
            order: $this->order,
            lines: [new ReservationLine(
                (int) $this->orderItem->id,
                (int) $this->product->id,
                (int) $this->warehouse->id,
                15,
            )],
            allowPartial: true,
        );

        $this->assertFalse($result->isComplete());
        $this->assertCount(1, $result->shortfalls);
        $this->assertSame(5, $result->shortfalls[0]['shortfall']);

        $this->assertStock(onHand: 10, reserved: 10);
    }

    public function test_multiple_lines_on_the_same_product_cannot_double_claim_the_same_units(): void
    {
        // Two lines, 6 each, against 10 units. The second must see the first
        // line's grant — the locked row is mutated in place as we go.
        $this->expectException(InsufficientStockException::class);

        app(ReservationService::class)->reserve(
            order: $this->order,
            lines: [
                new ReservationLine((int) $this->orderItem->id, (int) $this->product->id, (int) $this->warehouse->id, 6),
                new ReservationLine((int) $this->orderItem->id, (int) $this->product->id, (int) $this->warehouse->id, 6),
            ],
        );
    }

    public function test_a_multi_line_order_is_all_or_nothing(): void
    {
        $otherProduct = Product::factory()->create();
        $this->receiveStock($otherProduct, $this->warehouse, 2);

        $otherItem = OrderItem::factory()->create([
            'order_id' => $this->order->id,
            'product_id' => $otherProduct->id,
            'qty_ordered' => 50,
        ]);

        try {
            app(ReservationService::class)->reserve(
                order: $this->order,
                lines: [
                    new ReservationLine((int) $this->orderItem->id, (int) $this->product->id, (int) $this->warehouse->id, 5),
                    // 3 requested against 2 in stock — a STOCK failure, not an
                    // order-line one, so this test keeps testing what it says.
                    new ReservationLine((int) $otherItem->id, (int) $otherProduct->id, (int) $this->warehouse->id, 3),
                ],
            );
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        // The satisfiable line must NOT have been reserved. Silently
        // half-reserving an order is how the wrong quantity gets shipped.
        $this->assertStock(onHand: 10, reserved: 0);
        $this->assertStock(onHand: 2, reserved: 0, product: $otherProduct);
    }

    public function test_cannot_reserve_more_than_the_order_line_ordered(): void
    {
        // 10 units in stock, but the customer only ordered 5. Stock is not the
        // binding constraint here — the order is.
        $this->orderItem->update(['qty_ordered' => 5]);

        $this->expectException(ExceedsOrderedQuantityException::class);

        $this->reserve(6);
    }

    public function test_repeated_reservations_cannot_exceed_the_order_line_in_total(): void
    {
        $this->orderItem->update(['qty_ordered' => 5]);

        $this->reserve(3);

        try {
            $this->reserve(3);   // 3 + 3 = 6 > 5
            $this->fail('Expected ExceedsOrderedQuantityException.');
        } catch (ExceedsOrderedQuantityException $exception) {
            $this->assertSame(2, $exception->context['outstanding']);
            $this->assertSame(1, $exception->context['excess']);
        }

        // The refused attempt left nothing behind.
        $this->assertStock(onHand: 10, reserved: 3);
        $this->assertSame(3, $this->orderItem->refresh()->qty_reserved);
    }

    public function test_two_lines_against_the_same_order_line_share_its_remaining_quantity(): void
    {
        // Regression guard: an in-place SQL increment would leave the order item
        // stale in memory, so the second line would re-read the original
        // outstanding figure and both would allocate the same units.
        $this->orderItem->update(['qty_ordered' => 5]);

        $this->expectException(ExceedsOrderedQuantityException::class);

        app(ReservationService::class)->reserve(
            order: $this->order,
            lines: [
                new ReservationLine((int) $this->orderItem->id, (int) $this->product->id, (int) $this->warehouse->id, 3),
                new ReservationLine((int) $this->orderItem->id, (int) $this->product->id, (int) $this->warehouse->id, 3),
            ],
        );
    }

    public function test_partial_mode_is_clamped_by_the_order_line_too(): void
    {
        $this->orderItem->update(['qty_ordered' => 4]);

        $result = app(ReservationService::class)->reserve(
            order: $this->order,
            lines: [new ReservationLine(
                (int) $this->orderItem->id,
                (int) $this->product->id,
                (int) $this->warehouse->id,
                9,
            )],
            allowPartial: true,
        );

        // Stock could satisfy 9; the order line could not.
        $this->assertSame(4, $result->shortfalls[0]['reserved']);
        $this->assertSame('ordered_quantity', $result->shortfalls[0]['limited_by']);
        $this->assertStock(onHand: 10, reserved: 4);
    }

    public function test_shortfall_reports_which_ceiling_bit(): void
    {
        $this->orderItem->update(['qty_ordered' => 50]);

        $result = app(ReservationService::class)->reserve(
            order: $this->order,
            lines: [new ReservationLine(
                (int) $this->orderItem->id,
                (int) $this->product->id,
                (int) $this->warehouse->id,
                20,
            )],
            allowPartial: true,
        );

        // Here it is stock that binds — a different problem with a different fix,
        // which is why the two are not collapsed into one error.
        $this->assertSame('available_stock', $result->shortfalls[0]['limited_by']);
    }

    public function test_released_quantity_becomes_reservable_on_the_order_line_again(): void
    {
        $this->orderItem->update(['qty_ordered' => 5]);

        $reservation = $this->reserve(5);
        app(ReservationService::class)->release($reservation);

        // Releasing gives the allocation back to the line, not just to stock.
        $this->assertSame(0, $this->orderItem->refresh()->qty_reserved);
        $this->reserve(5);

        $this->assertStock(onHand: 10, reserved: 5);
    }

    public function test_reservations_are_created_with_the_configured_ttl(): void
    {
        config(['inventory.reservation.default_ttl_minutes' => 45]);

        $reservation = $this->reserve(1);

        $this->assertNotNull($reservation->expires_at);
        $this->assertEqualsWithDelta(
            now()->addMinutes(45)->timestamp,
            $reservation->expires_at->timestamp,
            5,
        );
    }

    public function test_reserving_records_a_movement_and_a_history_event(): void
    {
        $reservation = $this->reserve(3);

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'reserve',
            'qty' => 3,
            'on_hand_delta' => 0,
            'reserved_delta' => 3,
        ]);

        $this->assertDatabaseHas('reservation_events', [
            'reservation_id' => $reservation->id,
            'to_status' => ReservationStatus::Active->value,
            'qty_delta' => 3,
            'reason' => 'reserved',
        ]);
    }

    public function test_stock_in_one_warehouse_does_not_satisfy_a_claim_on_another(): void
    {
        $otherWarehouse = Warehouse::factory()->create();
        $this->receiveStock($this->product, $otherWarehouse, 100);

        $this->expectException(InsufficientStockException::class);

        // 100 units exist — but not here. Reservations are per-warehouse.
        $this->reserve(50);
    }
}
