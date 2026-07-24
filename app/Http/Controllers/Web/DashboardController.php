<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Inventory\Services\StockQueryService;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\Warehouse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operator dashboard.
 *
 * Read-only by design. Every mutation in this system goes through the API or the
 * console so there is exactly one code path per operation — a dashboard that
 * reserved stock through its own controller would be a second implementation to
 * keep correct, and the second one is always the one that drifts.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly StockQueryService $stock) {}

    public function index(Request $request): View
    {
        $warehouseId = $request->integer('warehouse_id') ?: null;

        return view('dashboard', [
            'warehouses' => Warehouse::query()->orderBy('code', 'asc')->get(),
            'selectedWarehouse' => $warehouseId,
            'snapshots' => $this->stock->snapshots($warehouseId),
            'openReservations' => $this->stock->openReservations($warehouseId),
            'expiringSoon' => $this->stock->reservationsExpiringWithin(10),
            'recentShipments' => Shipment::query()
                ->with('warehouse:id,code')
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    /**
     * Polled by the page so stock levels stay live without a full reload.
     */
    public function snapshot(Request $request): JsonResponse
    {
        $warehouseId = $request->integer('warehouse_id') ?: null;

        return response()->json([
            'data' => $this->stock->snapshots($warehouseId)
                ->map(static fn($snapshot): array => $snapshot->toArray())
                ->values(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Movement history for one stock position — the audit drill-down.
     */
    public function movements(int $productId, int $warehouseId): JsonResponse
    {
        return response()->json([
            'data' => $this->stock->movementHistory($productId, $warehouseId, 50)
                ->map(static fn($movement): array => [
                    'type' => $movement->type->value,
                    'label' => $movement->type->label(),
                    'badge' => $movement->type->badgeClass(),
                    'qty' => $movement->qty,
                    'on_hand_delta' => $movement->on_hand_delta,
                    'reserved_delta' => $movement->reserved_delta,
                    'on_hand_after' => $movement->on_hand_after,
                    'reserved_after' => $movement->reserved_after,
                    'actor' => $movement->actor,
                    'occurred_at' => $movement->occurred_at?->format('Y-m-d H:i:s'),
                ])->values(),
        ]);
    }
}
