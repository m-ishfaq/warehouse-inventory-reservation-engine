<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\DTOs\ReservationLine;
use App\Domain\Inventory\Enums\ShipmentStatus;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Inventory\Services\InventoryTransferService;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Inventory\Services\ShipmentService;
use App\Domain\Inventory\Enums\MovementType;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProcessedWebhook;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\Shipment;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Drive each required failure scenario end to end and show the numbers.
 *
 * Why this exists: "trust me, it handles duplicates" is worth nothing in a
 * review. This command creates an isolated fixture, performs the failure, and
 * prints stock before and after so the invariant can be *seen* holding. It is
 * the backbone of the video walkthrough and it doubles as a smoke test that the
 * whole stack — services, ledger, provider, state machines — is wired together.
 *
 *   php artisan demo:scenario                 # list scenarios
 *   php artisan demo:scenario last-item       # run one
 *   php artisan demo:scenario --all           # run all ten
 */
class DemoScenarioCommand extends Command
{
    protected $signature = 'demo:scenario
                            {name? : Scenario to run}
                            {--all : Run every scenario in order}
                            {--keep : Do not roll back the fixture afterwards}';

    protected $description = 'Demonstrate the engine surviving each required failure scenario';

    /**
     * @var array<string, string>
     */
    private const SCENARIOS = [
        'last-item' => 'Two users reserve the last remaining item',
        'duplicate-command' => 'The reservation command runs twice with the same key',
        'duplicate-webhook' => 'A shipment confirmation arrives more than once',
        'worker-retry' => 'A worker crashes mid-processing and the job runs again',
        'timeout-then-confirm' => 'Carrier times out, then confirms late',
        'cancellation' => 'A reservation is cancelled and stock returns',
        'partial-shipment' => 'Only part of a reservation ships',
        'transfer' => 'Stock is transferred while reservations exist',
        'expiry' => 'A reservation TTL elapses and the sweep releases it',
        'rollback' => 'A transaction fails halfway and leaves nothing behind',
    ];

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ShipmentService $shipments,
        private readonly InventoryTransferService $transfers,
        private readonly InventoryLedger $ledger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = $this->argument('name');

        if ($this->option('all')) {
            foreach (array_keys(self::SCENARIOS) as $scenario) {
                $this->runScenario($scenario);
                $this->newLine();
            }

            return self::SUCCESS;
        }

        if ($name === null) {
            $this->info('Available scenarios:');
            $this->newLine();

            foreach (self::SCENARIOS as $key => $description) {
                $this->line(sprintf('  <fg=cyan>%-22s</> %s', $key, $description));
            }

            $this->newLine();
            $this->line('  Run one:  <fg=yellow>php artisan demo:scenario last-item</>');
            $this->line('  Run all:  <fg=yellow>php artisan demo:scenario --all</>');

            return self::SUCCESS;
        }

        if (! array_key_exists($name, self::SCENARIOS)) {
            $this->error(sprintf('Unknown scenario [%s].', $name));

            return self::FAILURE;
        }

        $this->runScenario($name);

        return self::SUCCESS;
    }

    private function runScenario(string $name): void
    {
        $this->line('');
        $this->line('<fg=black;bg=cyan> '.str_pad(strtoupper($name), 24).'</> '.self::SCENARIOS[$name]);
        $this->line(str_repeat('─', 78));

        $fixture = $this->makeFixture($name);

        try {
            match ($name) {
                'last-item' => $this->scenarioLastItem($fixture),
                'duplicate-command' => $this->scenarioDuplicateCommand($fixture),
                'duplicate-webhook' => $this->scenarioDuplicateWebhook($fixture),
                'worker-retry' => $this->scenarioWorkerRetry($fixture),
                'timeout-then-confirm' => $this->scenarioTimeoutThenConfirm($fixture),
                'cancellation' => $this->scenarioCancellation($fixture),
                'partial-shipment' => $this->scenarioPartialShipment($fixture),
                'transfer' => $this->scenarioTransfer($fixture),
                'expiry' => $this->scenarioExpiry($fixture),
                'rollback' => $this->scenarioRollback($fixture),
                default => throw new RuntimeException('Unhandled scenario.'),
            };
        } finally {
            config(['inventory.shipping.mock.forced_outcome' => null]);
        }

        $this->line(str_repeat('─', 78));
        $this->reconcile($fixture);
    }

    // ------------------------------------------------------------------
    // Scenarios
    // ------------------------------------------------------------------

    /**
     * The canonical race. Both callers see "1 available" before acting; only one
     * of them still sees it once the row lock is held.
     *
     * Run sequentially here for readability — the genuinely parallel proof lives
     * in tests/Concurrency/ConcurrentReservationTest, which opens real
     * simultaneous connections. The mechanism being demonstrated is identical:
     * availability is re-read inside the lock, never trusted from before it.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function scenarioLastItem(array $fixture): void
    {
        $this->stock($fixture, 'Opening position (1 unit in stock)');

        $this->step('User A reserves the last unit');
        $this->reservations->reserve($fixture['order'], [
            new ReservationLine($fixture['item']->getKey(), $fixture['product']->getKey(), $fixture['warehouse']->getKey(), 1),
        ]);
        $this->ok('Reserved.');

        $this->step('User B attempts the same unit');
        $this->expectFailure(fn () => $this->reservations->reserve($fixture['order'], [
            new ReservationLine($fixture['item']->getKey(), $fixture['product']->getKey(), $fixture['warehouse']->getKey(), 1),
        ]));

        $this->stock($fixture, 'Final position — one reserved, none oversold');
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function scenarioDuplicateCommand(array $fixture): void
    {
        $key = 'demo-'.Str::random(12);

        $this->stock($fixture, 'Opening position');

        $this->step('First call with Idempotency-Key '.$key);
        $first = $this->reservations->reserve($fixture['order'], [
            new ReservationLine($fixture['item']->getKey(), $fixture['product']->getKey(), $fixture['warehouse']->getKey(), 3),
        ], idempotencyKey: $key);
        $this->ok(sprintf('Created reservation(s): %s', implode(', ', $first->reservationIds)));

        $this->step('Identical call, identical key');
        $second = $this->reservations->reserve($fixture['order'], [
            new ReservationLine($fixture['item']->getKey(), $fixture['product']->getKey(), $fixture['warehouse']->getKey(), 3),
        ], idempotencyKey: $key);

        $this->ok(sprintf(
            'Replayed: %s — same reservation(s): %s',
            $second->replayed ? 'yes' : 'NO (bug!)',
            implode(', ', $second->reservationIds),
        ));

        $this->stock($fixture, 'Reserved 3, not 6 — the second call did no work');
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function scenarioDuplicateWebhook(array $fixture): void
    {
        $reservation = $this->reserve($fixture, 4);
        $shipment = $this->dispatchShipment($fixture, $reservation, 4);

        $this->stock($fixture, 'Reserved 4, dispatched, nothing deducted yet');

        $eventId = 'evt_demo_'.Str::random(16);

        $this->step('Carrier confirms (event '.$eventId.')');
        $first = $this->shipments->confirm($shipment, [$reservation->getKey() => 4], $eventId);
        $this->ok(sprintf(
            'Shipped %d unit(s). Ledger now holds %d ship movement(s).',
            $first['shipped'],
            $this->shipMovements($fixture),
        ));

        $this->step('Carrier sends the SAME event again');
        $second = $this->shipments->confirm($shipment->refresh(), [$reservation->getKey() => 4], $eventId);

        // The replayed response repeats the ORIGINAL figure — it is not a second
        // deduction. Reporting it as "shipped N more" would say the opposite of
        // what happened, so the movement count is what gets shown as proof.
        $this->ok(sprintf(
            'Replayed: %s. Response repeats the original (shipped=%d) — no new work done.',
            $second['replayed'] ? 'yes' : 'NO (bug!)',
            $second['shipped'],
        ));
        $this->ok(sprintf(
            'Ledger STILL holds %d ship movement(s), and %d webhook event(s) recorded.',
            $this->shipMovements($fixture),
            ProcessedWebhook::query()->where('provider_event_id', $eventId)->count(),
        ));

        $this->stock($fixture, 'Deducted exactly once');
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function scenarioWorkerRetry(array $fixture): void
    {
        $reservation = $this->reserve($fixture, 2);
        $shipment = $this->dispatchShipment($fixture, $reservation, 2);

        $this->stock($fixture, 'Before processing');

        $this->step('Worker confirms, then dies before acknowledging the job');
        $eventId = 'evt_retry_'.Str::random(12);
        $this->shipments->confirm($shipment, [$reservation->getKey() => 2], $eventId);
        $this->ok(sprintf(
            'Inventory deducted; job never acked. Ledger holds %d ship movement(s).',
            $this->shipMovements($fixture),
        ));

        $this->step('Queue redelivers the job — worker runs the identical work again');
        $again = $this->shipments->confirm($shipment->refresh(), [$reservation->getKey() => 2], $eventId);
        $this->ok(sprintf(
            'Replayed: %s — the response repeats the original, it is not a second deduction.',
            $again['replayed'] ? 'yes' : 'NO (bug!)',
        ));
        $this->ok(sprintf(
            'Ledger STILL holds %d ship movement(s).',
            $this->shipMovements($fixture),
        ));

        $this->stock($fixture, 'Still deducted exactly once');
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function scenarioTimeoutThenConfirm(array $fixture): void
    {
        $reservation = $this->reserve($fixture, 3);
        $shipment = $this->shipments->createShipment(
            $fixture['order'],
            $fixture['warehouse'],
            [$reservation->getKey() => 3],
        );

        $this->stock($fixture, 'Reserved, not yet dispatched');

        $this->step('Carrier times out — outcome genuinely unknown');
        config(['inventory.shipping.mock.forced_outcome' => 'timeout']);
        $this->expectFailure(fn () => $this->shipments->process($shipment));

        $shipment->refresh();
        $this->ok(sprintf('Shipment parked in "%s" — reservation still held, no stock deducted.', $shipment->status->value));
        $this->stock($fixture, 'Nothing lost: the timeout costs a held reservation, not stock');

        $this->step('Confirmation arrives late and settles the shipment');
        $this->shipments->confirm($shipment, [$reservation->getKey() => 3], 'evt_late_'.Str::random(12));
        $this->ok('Settled correctly despite the earlier timeout.');

        $this->stock($fixture, 'Final position');
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function scenarioCancellation(array $fixture): void
    {
        $reservation = $this->reserve($fixture, 5);
        $this->stock($fixture, 'Reserved 5');

        $this->step('Warehouse operator cancels the reservation');
        $released = $this->reservations->release($reservation, reason: 'operator_cancelled');
        $this->ok(sprintf('Released %d unit(s).', $released));

        $this->step('Operator clicks cancel a second time');
        $again = $this->reservations->release($reservation->refresh(), reason: 'operator_cancelled');
        $this->ok(sprintf('Released %d more — a settled reservation converges rather than throwing.', $again));

        $this->stock($fixture, 'Stock fully returned, released once');
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function scenarioPartialShipment(array $fixture): void
    {
        $reservation = $this->reserve($fixture, 5);
        $this->stock($fixture, 'Reserved 5');

        $this->step('Only 3 of the 5 units make it onto the truck');
        $shipment = $this->dispatchShipment($fixture, $reservation, 3);
        $this->shipments->confirm($shipment, [$reservation->getKey() => 3], 'evt_partial_'.Str::random(12));

        $reservation->refresh();
        $this->ok(sprintf(
            'Consumed %d, still holding %d, status "%s".',
            $reservation->qty_consumed,
            $reservation->qtyOutstanding(),
            $reservation->status->value,
        ));

        $this->stock($fixture, 'Remainder stays reserved — it is still promised to this order');
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function scenarioTransfer(array $fixture): void
    {
        $destination = Warehouse::query()->create([
            'code' => 'WH-DEMO-'.Str::upper(Str::random(4)),
            'name' => 'Demo Destination',
        ]);

        $this->reserve($fixture, 8);
        $this->stock($fixture, 'On hand 10, reserved 8 — only 2 are free');

        $this->step('Attempt to transfer 5 units (more than is free)');
        $this->expectFailure(fn () => $this->transfers->transfer(
            $fixture['product'],
            $fixture['warehouse'],
            $destination,
            5,
        ));

        $this->step('Transfer 2 units (exactly the unreserved surplus)');
        $result = $this->transfers->transfer($fixture['product'], $fixture['warehouse'], $destination, 2);
        $this->ok(sprintf('Moved %d unit(s). Source available now %d.', $result['transferred'], $result['from_available']));

        $this->stock($fixture, 'Reserved stock never left the warehouse that promised it');
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function scenarioExpiry(array $fixture): void
    {
        $this->step('Reserve with a TTL that has already elapsed');
        $this->reservations->reserve($fixture['order'], [
            new ReservationLine($fixture['item']->getKey(), $fixture['product']->getKey(), $fixture['warehouse']->getKey(), 6),
        ], ttlMinutes: 0);

        // ttlMinutes: 0 means "no expiry" by design, so age the row explicitly
        // rather than pretending the TTL path did something it did not.
        Reservation::query()
            ->where('product_id', $fixture['product']->getKey())
            ->update(['expires_at' => now()->subMinutes(5)]);

        $this->stock($fixture, 'Reserved 6, TTL already past');

        $this->step('The sweep runs');
        $result = $this->reservations->expireDue();
        $this->ok(sprintf('Expired %d reservation(s), returning %d unit(s).', $result['expired'], $result['released_qty']));

        $this->stock($fixture, 'Stock back to available');
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function scenarioRollback(array $fixture): void
    {
        $this->stock($fixture, 'Opening position');

        $this->step('Begin a transaction, reserve, then throw before commit');

        try {
            DB::transaction(function () use ($fixture): void {
                $inventory = $this->ledger->lockPair(
                    (int) $fixture['product']->getKey(),
                    (int) $fixture['warehouse']->getKey(),
                );

                $this->ledger->reserve($inventory, 4, $fixture['order']);
                $this->line('    <fg=gray>… reserved 4 inside the transaction</>');

                throw new RuntimeException('Simulated crash after the ledger posting.');
            });
        } catch (RuntimeException $exception) {
            $this->ok('Caught: '.$exception->getMessage());
        }

        $this->stock($fixture, 'Ledger row AND cache update rolled back together — no orphan movement');
    }

    // ------------------------------------------------------------------
    // Fixture + output helpers
    // ------------------------------------------------------------------

    /**
     * Every scenario gets its own product and warehouse so runs never interfere
     * and the printed numbers are unambiguous.
     *
     * @return array<string, mixed>
     */
    private function makeFixture(string $name): array
    {
        $onHand = $name === 'last-item' ? 1 : 10;

        $product = Product::query()->create([
            'sku' => 'DEMO-'.Str::upper(Str::random(8)),
            'name' => 'Demo product for '.$name,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'WH-D'.Str::upper(Str::random(5)),
            'name' => 'Demo warehouse for '.$name,
        ]);

        DB::transaction(function () use ($product, $warehouse, $onHand): void {
            $inventory = $this->ledger->lockPair((int) $product->getKey(), (int) $warehouse->getKey());
            $this->ledger->receipt($inventory, $onHand, $product, ['source' => 'demo_fixture']);
        });

        $order = Order::query()->create([
            'order_number' => 'SO-DEMO-'.Str::upper(Str::random(8)),
            'customer_ref' => 'Demo scenario: '.$name,
        ]);

        $item = OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'qty_ordered' => 50,
        ]);

        return compact('product', 'warehouse', 'order', 'item');
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function reserve(array $fixture, int $qty): Reservation
    {
        $result = $this->reservations->reserve($fixture['order'], [
            new ReservationLine(
                $fixture['item']->getKey(),
                $fixture['product']->getKey(),
                $fixture['warehouse']->getKey(),
                $qty,
            ),
        ]);

        return Reservation::query()->findOrFail($result->reservationIds[0]);
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function dispatchShipment(array $fixture, Reservation $reservation, int $qty): Shipment
    {
        $shipment = $this->shipments->createShipment(
            $fixture['order'],
            $fixture['warehouse'],
            [$reservation->getKey() => $qty],
        );

        // Move it to `dispatched` without invoking the randomised provider, so
        // the scenario under demonstration is the only variable.
        $shipment->forceFill([
            'status' => ShipmentStatus::Dispatched,
            'provider_ref' => 'MOCK-DEMO-'.Str::upper(Str::random(10)),
            'dispatched_at' => now(),
            'attempts' => 1,
        ])->save();

        return $shipment;
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function stock(array $fixture, string $caption): void
    {
        $inventory = Inventory::query()
            ->forPair((int) $fixture['product']->getKey(), (int) $fixture['warehouse']->getKey())
            ->first();

        if ($inventory === null) {
            return;
        }

        $this->line('');
        $this->line('  <fg=gray>'.$caption.'</>');
        $this->line(sprintf(
            '  <options=bold>on hand %d   reserved %d   available %d</>',
            $inventory->on_hand_qty,
            $inventory->reserved_qty,
            $inventory->availableQty(),
        ));
        $this->line('');
    }

    /**
     * Re-derive the position from the ledger and assert the cache matches.
     *
     * Printed after every scenario, because a scenario that "passes" while
     * quietly corrupting the ledger has not passed.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function reconcile(array $fixture): void
    {
        $productId = (int) $fixture['product']->getKey();
        $warehouseId = (int) $fixture['warehouse']->getKey();

        $sums = DB::table('inventory_movements')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->selectRaw('COALESCE(SUM(on_hand_delta), 0) as on_hand, COALESCE(SUM(reserved_delta), 0) as reserved')
            ->first();

        $cached = Inventory::query()->forPair($productId, $warehouseId)->first();

        $onHandOk = (int) $sums->on_hand === (int) $cached?->on_hand_qty;
        $reservedOk = (int) $sums->reserved === (int) $cached?->reserved_qty;

        if ($onHandOk && $reservedOk) {
            $this->line('  <fg=green>✓ Ledger reconciles with cache: on hand '.(int) $sums->on_hand.', reserved '.(int) $sums->reserved.'</>');

            return;
        }

        $this->line('  <fg=red>✗ DRIFT — ledger says on hand '.(int) $sums->on_hand.'/reserved '.(int) $sums->reserved
            .' but cache says '.$cached?->on_hand_qty.'/'.$cached?->reserved_qty.'</>');
    }

    /**
     * How many times stock has actually left this position.
     *
     * This is the number that settles the duplicate-handling argument. A replayed
     * response repeats the original figure, so quoting `shipped` twice looks like
     * a double deduction even when nothing happened. Counting `ship` movements
     * cannot be misread: the ledger is append-only, so one movement means stock
     * moved exactly once, no matter how many times the carrier called us.
     *
     * @param  array<string, mixed>  $fixture
     */
    private function shipMovements(array $fixture): int
    {
        return InventoryMovement::query()
            ->forPair((int) $fixture['product']->getKey(), (int) $fixture['warehouse']->getKey())
            ->where('type', MovementType::Ship)
            ->count();
    }

    private function step(string $message): void
    {
        $this->line('  <fg=yellow>▸</> '.$message);
    }

    private function ok(string $message): void
    {
        $this->line('    <fg=green>✓</> '.$message);
    }

    /**
     * @param  callable(): mixed  $operation
     */
    private function expectFailure(callable $operation): void
    {
        try {
            $operation();
            $this->line('    <fg=red>✗ Expected a failure but the operation succeeded — this is a bug.</>');
        } catch (InventoryException $exception) {
            $this->line(sprintf('    <fg=green>✓</> Refused: <fg=gray>%s</>', $exception->getMessage()));
        }
    }
}
