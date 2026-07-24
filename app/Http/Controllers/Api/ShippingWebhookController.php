<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\Services\ShipmentService;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound carrier callbacks.
 *
 * Signature verification happens in middleware before this runs. What is left
 * here is the second half of the duplicate story: the same event id arriving
 * twice must produce one inventory deduction and TWO successful responses.
 *
 * Answering 200 to a duplicate is not laziness — a carrier that receives 4xx
 * treats delivery as failed and retries forever. "Already handled" is a success.
 */
class ShippingWebhookController extends Controller
{
    public function __construct(private readonly ShipmentService $shipments) {}

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'string', 'max:191'],
            'provider_ref' => ['required', 'string', 'max:128'],
            'lines' => ['sometimes', 'array'],
            'lines.*.reservation_id' => ['required_with:lines', 'integer'],
            'lines.*.qty' => ['required_with:lines', 'integer', 'min:0'],
        ]);

        $shipment = Shipment::query()
            ->where('provider_ref', $validated['provider_ref'])
            ->first();

        if ($shipment === null) {
            // 202, not 404: the carrier may legitimately confirm before our
            // dispatch transaction has committed. Telling it "not found" would
            // make it give up on an event we will want shortly.
            return response()->json([
                'status' => 'accepted_unmatched',
                'message' => 'No shipment matches that provider reference yet.',
            ], 202);
        }

        $quantities = [];

        foreach ($validated['lines'] ?? [] as $line) {
            $quantities[(int) $line['reservation_id']] = (int) $line['qty'];
        }

        $result = $this->shipments->confirm($shipment, $quantities, $validated['event_id']);

        return response()->json([
            'status' => $result['replayed'] ? 'duplicate_ignored' : 'processed',
            'data' => $result,
        ]);
    }
}
