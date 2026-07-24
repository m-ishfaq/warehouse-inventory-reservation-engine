<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\DTOs\ReservationLine;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Services\ReservationService;
use App\Models\Order;
use Illuminate\Console\Command;
use Throwable;

/**
 * Attempt a single reservation and report the outcome as JSON.
 *
 * This exists so the concurrency test can spawn genuinely parallel OS processes
 * against the same stock row. Simulating concurrency inside one PHP process is
 * not possible in any honest way: the second connection's `SELECT ... FOR
 * UPDATE` blocks, and a single-threaded test would deadlock waiting for a commit
 * it can never reach. Separate processes are the only way to prove the lock does
 * what the design claims.
 *
 * Hidden from `artisan list` — it is test scaffolding, not an operator tool.
 */
class TryReserveCommand extends Command
{
    protected $hidden = true;

    protected $signature = 'inventory:try-reserve
                            {order : Order id}
                            {item : Order item id}
                            {product : Product id}
                            {warehouse : Warehouse id}
                            {--qty=1 : Quantity to attempt}
                            {--key= : Optional idempotency key}
                            {--start-at= : Unix timestamp (float) to synchronise the attempt on}';

    protected $description = 'Internal: attempt one reservation and print the outcome as JSON';

    public function handle(ReservationService $reservations): int
    {
        // Spin until the shared start time so every process fires at once.
        // Without a barrier the processes stagger by however long PHP takes to
        // boot, and the race we are trying to test never actually happens.
        $startAt = $this->option('start-at');

        if ($startAt !== null) {
            $target = (float) $startAt;

            while (microtime(true) < $target) {
                usleep(200);
            }
        }

        try {
            $order = Order::query()->findOrFail((int) $this->argument('order'));

            $result = $reservations->reserve(
                order: $order,
                lines: [new ReservationLine(
                    (int) $this->argument('item'),
                    (int) $this->argument('product'),
                    (int) $this->argument('warehouse'),
                    (int) $this->option('qty'),
                )],
                idempotencyKey: $this->option('key') ?: null,
            );

            $this->output->writeln((string) json_encode([
                'ok' => true,
                'reservation_ids' => $result->reservationIds,
                'replayed' => $result->replayed,
            ]));
        } catch (InventoryException $exception) {
            $this->output->writeln((string) json_encode([
                'ok' => false,
                'error' => $exception->errorCode(),
                'message' => $exception->getMessage(),
            ]));
        } catch (Throwable $exception) {
            $this->output->writeln((string) json_encode([
                'ok' => false,
                'error' => 'unexpected',
                'message' => $exception->getMessage(),
            ]));
        }

        // Always exit 0: a refused reservation is a valid outcome, not a crash,
        // and the test needs to read every process's JSON regardless.
        return self::SUCCESS;
    }
}
