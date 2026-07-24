<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\Enums\ShipmentStatus;
use App\Jobs\ProcessShipmentJob;
use App\Models\Shipment;
use Illuminate\Console\Command;

/**
 * Queue every shipment that is waiting to go out.
 *
 * Dispatching jobs rather than processing inline is the point: it forces the
 * engine through the same at-least-once delivery path production uses, so the
 * retry and duplicate handling is exercised by ordinary operation instead of
 * only by tests.
 */
class ProcessShipmentsCommand extends Command
{
    protected $signature = 'shipments:process
                            {--shipment= : Process a single shipment id}
                            {--include-failed : Also retry shipments the carrier previously rejected}
                            {--sync : Run inline instead of queueing (useful for demos)}
                            {--limit=100 : Maximum shipments to enqueue}';

    protected $description = 'Enqueue pending shipments for carrier dispatch';

    public function handle(): int
    {
        $statuses = [ShipmentStatus::Pending->value];

        if ($this->option('include-failed')) {
            $statuses[] = ShipmentStatus::Failed->value;
        }

        $shipments = Shipment::query()
            ->when($this->option('shipment'), fn ($q, $v) => $q->whereKey((int) $v))
            ->when(! $this->option('shipment'), fn ($q) => $q->whereIn('status', $statuses))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($shipments->isEmpty()) {
            $this->info('Nothing to dispatch.');

            return self::SUCCESS;
        }

        foreach ($shipments as $shipment) {
            $job = new ProcessShipmentJob((int) $shipment->getKey());

            if ($this->option('sync')) {
                dispatch_sync($job);
                $this->line(sprintf('  #%d → %s', $shipment->getKey(), $shipment->refresh()->status->value));
            } else {
                dispatch($job);
                $this->line(sprintf('  #%d queued', $shipment->getKey()));
            }
        }

        $this->info(sprintf('%d shipment(s) processed.', $shipments->count()));

        return self::SUCCESS;
    }
}
