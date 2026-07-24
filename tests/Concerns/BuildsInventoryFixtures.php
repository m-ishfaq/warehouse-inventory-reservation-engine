<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Inventory\DTOs\ReservationLine;
use App\Domain\Inventory\Enums\ShipmentStatus;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Inventory\Services\ShipmentService;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\Shipment;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared fixture builders.
 *
 * Opening stock is always posted through the ledger, never written straight to
 * the inventory table. If tests seeded quantities directly they would silently
 * pass while the ledger disagreed with the cache — which would make the
 * reconciliation tests meaningless, since they would be reconciling against
 * data that never went through the code under test.
 */
trait BuildsInventoryFixtures
{
    protected Product $product;

    protected Warehouse $warehouse;

    protected Order $order;

    protected OrderItem $orderItem;

    /**
     * Build a product, a warehouse, opening stock, and an order line big enough
     * to reserve against.
     */
    protected function setUpInventory(int $onHand = 10, int $qtyOrdered = 50): void
    {
        $this->product = Product::factory()->create();
        $this->warehouse = Warehouse::factory()->create();

        $this->receiveStock($this->product, $this->warehouse, $onHand);

        $this->order = Order::factory()->create();
        $this->orderItem = OrderItem::factory()->create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'qty_ordered' => $qtyOrdered,
        ]);
    }

    protected function receiveStock(Product $product, Warehouse $warehouse, int $qty): void
    {
        if ($qty <= 0) {
            return;
        }

        $ledger = app(InventoryLedger::class);

        DB::transaction(function () use ($ledger, $product, $warehouse, $qty): void {
            $inventory = $ledger->lockPair((int) $product->id, (int) $warehouse->id);
            $ledger->receipt($inventory, $qty, $product, ['source' => 'test_fixture']);
        });
    }

    protected function inventory(?Product $product = null, ?Warehouse $warehouse = null): Inventory
    {
        return Inventory::query()
            ->forPair(
                (int) ($product ?? $this->product)->id,
                (int) ($warehouse ?? $this->warehouse)->id,
            )
            ->firstOrFail();
    }

    protected function reserve(int $qty, ?string $idempotencyKey = null, bool $allowPartial = false): Reservation
    {
        $result = app(ReservationService::class)->reserve(
            order: $this->order,
            lines: [new ReservationLine(
                (int) $this->orderItem->id,
                (int) $this->product->id,
                (int) $this->warehouse->id,
                $qty,
            )],
            idempotencyKey: $idempotencyKey,
            allowPartial: $allowPartial,
        );

        return Reservation::query()->findOrFail($result->reservationIds[0]);
    }

    /**
     * A shipment already handed to the carrier, so tests can drive confirmation
     * without going through the randomised provider.
     */
    protected function dispatchedShipment(Reservation $reservation, int $qty): Shipment
    {
        $shipment = app(ShipmentService::class)->createShipment(
            order: $this->order,
            warehouse: $this->warehouse,
            reservationQuantities: [(int) $reservation->id => $qty],
        );

        $shipment->forceFill([
            'status' => ShipmentStatus::Dispatched,
            'provider_ref' => 'TEST-'.Str::upper(Str::random(10)),
            'dispatched_at' => now(),
            'attempts' => 1,
        ])->save();

        return $shipment;
    }

    /**
     * The invariant every test should be able to assert: the cache equals the
     * sum of the ledger, for every position touched.
     */
    protected function assertLedgerReconciles(?Product $product = null, ?Warehouse $warehouse = null): void
    {
        $productId = (int) ($product ?? $this->product)->id;
        $warehouseId = (int) ($warehouse ?? $this->warehouse)->id;

        $sums = DB::table('inventory_movements')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->selectRaw('COALESCE(SUM(on_hand_delta), 0) as on_hand, COALESCE(SUM(reserved_delta), 0) as reserved')
            ->first();

        $cached = Inventory::query()->forPair($productId, $warehouseId)->firstOrFail();

        $this->assertSame(
            (int) $sums->on_hand,
            $cached->on_hand_qty,
            'Cached on-hand quantity drifted from the movement ledger.',
        );

        $this->assertSame(
            (int) $sums->reserved,
            $cached->reserved_qty,
            'Cached reserved quantity drifted from the movement ledger.',
        );
    }

    /**
     * Assert the stock position, then assert it is internally consistent.
     */
    protected function assertStock(int $onHand, int $reserved, ?Product $product = null, ?Warehouse $warehouse = null): void
    {
        $inventory = $this->inventory($product, $warehouse);

        $this->assertSame($onHand, $inventory->on_hand_qty, 'Unexpected on-hand quantity.');
        $this->assertSame($reserved, $inventory->reserved_qty, 'Unexpected reserved quantity.');
        $this->assertSame($onHand - $reserved, $inventory->availableQty(), 'Available quantity is inconsistent.');

        $this->assertLedgerReconciles($product, $warehouse);
    }
}
