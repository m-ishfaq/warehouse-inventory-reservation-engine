<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\Services\ReservationService;
use Illuminate\Console\Command;

class ExpireReservationsCommand extends Command
{
    protected $signature = 'reservations:expire
                            {--limit= : Maximum reservations to sweep in this run}';

    protected $description = 'Release reservations whose TTL has elapsed, returning the stock to available';

    public function handle(ReservationService $reservations): int
    {
        $limit = $this->option('limit');

        $result = $reservations->expireDue(limit: $limit !== null ? (int) $limit : null);

        if ($result['expired'] === 0) {
            $this->info('No reservations were due for expiry.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Expired %d reservation(s), returning %d unit(s) to available stock.',
            $result['expired'],
            $result['released_qty'],
        ));

        return self::SUCCESS;
    }
}
