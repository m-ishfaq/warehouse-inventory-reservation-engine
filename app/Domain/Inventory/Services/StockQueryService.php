<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\StockSnapshot;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Read side of the engine.
 *
 * Answers the seven questions the quest says the system must be able to answer
 * at any point. Kept separate from the write services on purpose: reads never
 * take locks, never open transactions, and never mutate — so a reporting screen
 * can never contend with the reservation hot path.
 */
final class StockQueryService
{
    /**
     * Current position for one product in one warehouse.
     */
    public function snapshot(int $productId, int $warehouseId): ?StockSnapshot
    {
        $row = Inventory::query()
            ->with(['product:id,sku', 'warehouse:id,code'])
            ->forPair($productId, $warehouseId)
            ->first();

        return $row === null ? null : $this->toSnapshot($row);
    }

    /**
     * Positions across the whole estate, optionally narrowed.
     *
     * @return SupportCollection<int, StockSnapshot>
     */
    public function snapshots(?int $warehouseId = null, ?int $productId = null): SupportCollection
    {
        $rows = Inventory::query()
            ->with(['product:id,sku', 'warehouse:id,code'])
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->get();

        $shipped = $this->shippedTotals(
            $rows->pluck('product_id')->all(),
            $rows->pluck('warehouse_id')->all(),
        );

        return $rows->map(fn (Inventory $row): StockSnapshot => $this->toSnapshot($row, $shipped));
    }

    /**
     * Full movement history — the audit trail behind any number above.
     *
     * @return Collection<int, InventoryMovement>
     */
    public function movementHistory(int $productId, int $warehouseId, int $limit = 100): Collection
    {
        return InventoryMovement::query()
            ->forPair($productId, $warehouseId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Which reservations are still holding stock.
     *
     * @return Collection<int, Reservation>
     */
    public function openReservations(?int $warehouseId = null, ?int $productId = null): Collection
    {
        return Reservation::query()
            ->open()
            ->with(['product:id,sku', 'warehouse:id,code', 'orderItem:id,order_id,product_id'])
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Which orders have already consumed inventory, and how much.
     *
     * Sourced from the ledger rather than from order columns: if the two ever
     * disagree, the ledger is right by definition.
     *
     * @return SupportCollection<int, object>
     */
    public function ordersThatConsumedInventory(int $limit = 100): SupportCollection
    {
        return InventoryMovement::query()
            ->where('type', MovementType::Ship)
            ->where('reference_type', \App\Models\Shipment::class)
            ->join('shipments', 'shipments.id', '=', 'inventory_movements.reference_id')
            ->join('orders', 'orders.id', '=', 'shipments.order_id')
            ->groupBy('orders.id', 'orders.order_number', 'orders.status')
            ->orderByRaw('MAX(inventory_movements.occurred_at) DESC')
            ->limit($limit)
            ->get([
                'orders.id as order_id',
                'orders.order_number',
                'orders.status',
                \Illuminate\Support\Facades\DB::raw('SUM(inventory_movements.qty) as qty_consumed'),
                \Illuminate\Support\Facades\DB::raw('MAX(inventory_movements.occurred_at) as last_movement_at'),
            ]);
    }

    /**
     * Reservations approaching expiry — the operational warning list.
     *
     * @return Collection<int, Reservation>
     */
    public function reservationsExpiringWithin(int $minutes = 10): Collection
    {
        return Reservation::query()
            ->whereIn('status', ReservationStatus::openValues())
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addMinutes($minutes)])
            ->with(['product:id,sku', 'warehouse:id,code'])
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Lifetime shipped quantity per (product, warehouse), read from the ledger.
     *
     * @param  list<int>  $productIds
     * @param  list<int>  $warehouseIds
     * @return array<string, int>
     */
    private function shippedTotals(array $productIds, array $warehouseIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return InventoryMovement::query()
            ->where('type', MovementType::Ship)
            ->whereIn('product_id', array_unique($productIds))
            ->whereIn('warehouse_id', array_unique($warehouseIds))
            ->groupBy('product_id', 'warehouse_id')
            ->get(['product_id', 'warehouse_id', \Illuminate\Support\Facades\DB::raw('SUM(qty) as total')])
            ->mapWithKeys(static fn ($row): array => [
                $row->product_id.':'.$row->warehouse_id => (int) $row->total,
            ])
            ->all();
    }

    /**
     * @param  array<string, int>|null  $shippedTotals
     */
    private function toSnapshot(Inventory $row, ?array $shippedTotals = null): StockSnapshot
    {
        $key = $row->product_id.':'.$row->warehouse_id;

        $shipped = $shippedTotals !== null
            ? ($shippedTotals[$key] ?? 0)
            : (int) InventoryMovement::query()
                ->forPair($row->product_id, $row->warehouse_id)
                ->where('type', MovementType::Ship)
                ->sum('qty');

        return new StockSnapshot(
            productId: (int) $row->product_id,
            productSku: (string) ($row->product?->sku ?? ''),
            warehouseId: (int) $row->warehouse_id,
            warehouseCode: (string) ($row->warehouse?->code ?? ''),
            onHand: $row->on_hand_qty,
            reserved: $row->reserved_qty,
            shippedToDate: $shipped,
        );
    }
}
