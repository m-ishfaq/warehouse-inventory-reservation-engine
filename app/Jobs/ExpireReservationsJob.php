<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Inventory\Services\ReservationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Release reservations whose TTL has passed.
 *
 * ShouldBeUnique because two overlapping sweeps would both try to settle the
 * same rows. They would not corrupt anything — settle() re-reads under a lock
 * and returns 0 for an already-settled reservation — but they would waste
 * lock time on the hot inventory rows for no benefit.
 */
class ExpireReservationsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly ?int $limit = null) {}

    public function handle(ReservationService $reservations): void
    {
        $result = $reservations->expireDue(limit: $this->limit);

        if ($result['expired'] > 0) {
            Log::info('Expired reservations released stock.', $result);
        }
    }
}
