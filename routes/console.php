<?php

declare(strict_types=1);

use App\Jobs\ExpireReservationsJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled maintenance
|--------------------------------------------------------------------------
|
| Two recurring concerns:
|
|   1. Reservations whose TTL has elapsed must return their stock to available,
|      or an abandoned checkout locks inventory forever.
|
|   2. Pending shipments must keep moving even if the HTTP request that created
|      them never queued a job (a crash between commit and dispatch). This sweep
|      is the safety net that makes that gap self-healing rather than permanent.
|
| withoutOverlapping on both: a long-running sweep must not stack on itself and
| fight for the same inventory locks.
|
*/

Schedule::job(new ExpireReservationsJob)
    ->everyMinute()
    ->withoutOverlapping()
    ->name('expire-reservations')
    ->description('Release reservations whose TTL has elapsed');

Schedule::command('shipments:process --limit=200')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('sweep-pending-shipments')
    ->description('Re-enqueue shipments that never made it onto the queue');
