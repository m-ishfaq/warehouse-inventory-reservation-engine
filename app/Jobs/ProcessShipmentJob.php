<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Inventory\Enums\ShipmentStatus;
use App\Domain\Inventory\Exceptions\ShipmentTimeoutException;
use App\Domain\Inventory\Services\ShipmentService;
use App\Models\Shipment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hand one shipment to the carrier.
 *
 * The job is intentionally thin. All the safety lives in ShipmentService, which
 * means "the worker crashed and the job ran again" and "somebody called the API
 * twice" are the same code path — there is no queue-specific correctness logic
 * that could drift from the synchronous one.
 */
class ProcessShipmentJob implements ShouldQueue
{
    use Queueable;

    /**
     * Retries exist for the timeout case only. A permanently rejected shipment
     * returns normally rather than throwing, so it never burns an attempt.
     */
    public int $tries = 5;

    /**
     * Exponential-ish backoff: a carrier that just timed out is unlikely to
     * answer a second later, and hammering it makes the timeout more likely.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 120];

    /**
     * Give up on a shipment that has been retried for an hour; at that point a
     * human needs to reconcile with the carrier.
     */
    public int $timeout = 120;

    public function __construct(public readonly int $shipmentId) {}

    /**
     * Belt to the ShipmentService's braces: stops two workers pulling the same
     * shipment at the same time. The conditional status claim in the service
     * would still make that safe — this just avoids the wasted work.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->shipmentId))->releaseAfter(30)];
    }

    public function handle(ShipmentService $shipments): void
    {
        $shipment = Shipment::query()->find($this->shipmentId);

        if ($shipment === null) {
            Log::warning('ProcessShipmentJob: shipment no longer exists.', ['shipment_id' => $this->shipmentId]);

            return;
        }

        // Terminal shipments are a no-op, not an error. A duplicated job for an
        // already-shipped consignment must not touch inventory.
        if ($shipment->status === ShipmentStatus::Shipped || $shipment->status === ShipmentStatus::Cancelled) {
            return;
        }

        try {
            $shipments->process($shipment);
        } catch (ShipmentTimeoutException $exception) {
            // Rethrow so the queue retries. The shipment stays in `dispatched`
            // and holds its reservations; no inventory has moved, so a retry is
            // free of side effects.
            Log::info('Shipment timed out; releasing job for retry.', [
                'shipment_id' => $this->shipmentId,
                'attempt' => $this->attempts(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessShipmentJob exhausted its retries.', [
            'shipment_id' => $this->shipmentId,
            'exception' => $exception->getMessage(),
        ]);

        // Deliberately does NOT release reservations. A shipment we cannot get
        // an answer about may still be in transit; freeing the stock here could
        // sell the same goods twice. It is parked for human reconciliation.
        Shipment::query()
            ->whereKey($this->shipmentId)
            ->where('status', ShipmentStatus::Dispatched->value)
            ->update([
                'last_error' => 'Retries exhausted: '.$exception->getMessage(),
                'updated_at' => now(),
            ]);
    }
}
