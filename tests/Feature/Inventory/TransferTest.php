<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Exceptions\TransferNotAllowedException;
use App\Domain\Inventory\Services\InventoryTransferService;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInventoryFixtures;
use Tests\TestCase;

/**
 * Scenario: "products are transferred between warehouses while reservations
 * exist".
 *
 * Our chosen rule: only the unreserved surplus may move. Reserved stock is
 * pinned to the warehouse that promised it.
 */
class TransferTest extends TestCase
{
    use BuildsInventoryFixtures;
    use RefreshDatabase;

    private Warehouse $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory(onHand: 10);
        $this->destination = Warehouse::factory()->create();
    }

    public function test_unreserved_stock_moves_freely(): void
    {
        app(InventoryTransferService::class)->transfer($this->product, $this->warehouse, $this->destination, 4);

        $this->assertStock(onHand: 6, reserved: 0);
        $this->assertStock(onHand: 4, reserved: 0, warehouse: $this->destination);
    }

    public function test_reserved_stock_cannot_be_transferred_away(): void
    {
        $this->reserve(8);

        $this->expectException(TransferNotAllowedException::class);

        // 10 on hand but only 2 free.
        app(InventoryTransferService::class)->transfer($this->product, $this->warehouse, $this->destination, 5);
    }

    public function test_exactly_the_unreserved_surplus_can_be_transferred(): void
    {
        $this->reserve(8);

        app(InventoryTransferService::class)->transfer($this->product, $this->warehouse, $this->destination, 2);

        $this->assertStock(onHand: 8, reserved: 8);
        $this->assertStock(onHand: 2, reserved: 0, warehouse: $this->destination);
    }

    public function test_a_refused_transfer_moves_nothing(): void
    {
        $this->reserve(8);

        try {
            app(InventoryTransferService::class)->transfer($this->product, $this->warehouse, $this->destination, 5);
        } catch (TransferNotAllowedException) {
            // expected
        }

        $this->assertStock(onHand: 10, reserved: 8);
        $this->assertDatabaseMissing('inventory_movements', ['type' => 'transfer_out']);
        $this->assertDatabaseMissing('inventory_movements', ['type' => 'transfer_in']);
    }

    public function test_transferring_to_the_same_warehouse_is_refused(): void
    {
        $this->expectException(TransferNotAllowedException::class);

        app(InventoryTransferService::class)->transfer($this->product, $this->warehouse, $this->warehouse, 1);
    }

    public function test_a_transfer_writes_a_matched_pair_of_movements(): void
    {
        app(InventoryTransferService::class)->transfer($this->product, $this->warehouse, $this->destination, 3);

        $this->assertDatabaseHas('inventory_movements', [
            'warehouse_id' => $this->warehouse->id,
            'type' => 'transfer_out',
            'qty' => 3,
            'on_hand_delta' => -3,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'warehouse_id' => $this->destination->id,
            'type' => 'transfer_in',
            'qty' => 3,
            'on_hand_delta' => 3,
        ]);
    }

    public function test_a_transfer_is_idempotent_by_key(): void
    {
        app(InventoryTransferService::class)->transfer(
            $this->product, $this->warehouse, $this->destination, 3, idempotencyKey: 'transfer-1',
        );

        app(InventoryTransferService::class)->transfer(
            $this->product, $this->warehouse, $this->destination, 3, idempotencyKey: 'transfer-1',
        );

        // Replayed, not repeated — otherwise a retried transfer silently
        // relocates the stock twice.
        $this->assertStock(onHand: 7, reserved: 0);
        $this->assertStock(onHand: 3, reserved: 0, warehouse: $this->destination);
    }

    public function test_stock_transferred_in_becomes_reservable_at_the_destination(): void
    {
        app(InventoryTransferService::class)->transfer($this->product, $this->warehouse, $this->destination, 5);

        $result = app(\App\Domain\Inventory\Services\ReservationService::class)->reserve(
            order: $this->order,
            lines: [new \App\Domain\Inventory\DTOs\ReservationLine(
                (int) $this->orderItem->id,
                (int) $this->product->id,
                (int) $this->destination->id,
                5,
            )],
        );

        $this->assertCount(1, $result->reservationIds);
        $this->assertStock(onHand: 5, reserved: 5, warehouse: $this->destination);
    }
}
