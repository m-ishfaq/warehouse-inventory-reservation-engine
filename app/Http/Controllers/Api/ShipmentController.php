<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\Services\ShipmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateShipmentRequest;
use App\Jobs\ProcessShipmentJob;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;

class ShipmentController extends Controller
{
    public function __construct(private readonly ShipmentService $shipments) {}

    /**
     * Create a shipment and (by default) queue it for dispatch.
     *
     * Queueing rather than dispatching inline is deliberate even though it makes
     * the endpoint less immediately satisfying: carrier calls are slow and
     * failure-prone, and holding an HTTP request open across one means a carrier
     * outage becomes a web-tier outage.
     */
    public function store(CreateShipmentRequest $request): JsonResponse
    {
        $order = Order::query()->findOrFail($request->validated('order_id'));
        $warehouse = Warehouse::query()->findOrFail($request->validated('warehouse_id'));

        $shipment = $this->shipments->createShipment(
            order: $order,
            warehouse: $warehouse,
            reservationQuantities: $request->reservationQuantities(),
            idempotencyKey: $request->idempotencyKey(),
        );

        if ($request->boolean('dispatch_now', true)) {
            ProcessShipmentJob::dispatch((int) $shipment->getKey());
        }

        return response()->json(['data' => $this->present($shipment->load('items'))], 201);
    }

    public function show(Shipment $shipment): JsonResponse
    {
        return response()->json(['data' => $this->present($shipment->load('items'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Shipment $shipment): array
    {
        return [
            'id' => $shipment->getKey(),
            'order_id' => $shipment->order_id,
            'warehouse_id' => $shipment->warehouse_id,
            'status' => $shipment->status->value,
            'status_label' => $shipment->status->label(),
            'provider_ref' => $shipment->provider_ref,
            'attempts' => $shipment->attempts,
            'last_error' => $shipment->last_error,
            'dispatched_at' => $shipment->dispatched_at?->toIso8601String(),
            'confirmed_at' => $shipment->confirmed_at?->toIso8601String(),
            'items' => $shipment->items->map(static fn (ShipmentItem $item): array => [
                'reservation_id' => $item->reservation_id,
                'qty_requested' => $item->qty_requested,
                'qty_shipped' => $item->qty_shipped,
            ])->values(),
        ];
    }
}
