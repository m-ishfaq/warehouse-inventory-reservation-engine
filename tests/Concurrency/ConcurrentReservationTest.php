<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Domain\Inventory\Services\InventoryLedger;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The test that justifies the whole locking design.
 *
 * Everything else in the suite runs sequentially and proves the *logic* is
 * right. This one spawns real OS processes that hit the same inventory row at
 * the same instant and proves the logic still holds when it is raced.
 *
 * Two deliberate departures from the rest of the suite:
 *
 *   1. DatabaseTruncation, not RefreshDatabase. RefreshDatabase wraps each test
 *      in a transaction that is never committed — the child processes, on their
 *      own connections, would not see the fixture at all.
 *
 *   2. It shells out. There is no honest way to simulate two connections racing
 *      inside one single-threaded PHP process: the loser's `SELECT ... FOR
 *      UPDATE` blocks, and the test would deadlock waiting for a commit it
 *      cannot reach because it is the thing that is blocked.
 *
 * Run with:  php artisan test --testsuite=Concurrency
 */
class ConcurrentReservationTest extends TestCase
{
    use DatabaseTruncation;

    private const PARALLEL_ATTEMPTS = 8;

    public function test_only_one_of_many_concurrent_attempts_can_claim_the_last_unit(): void
    {
        [$product, $warehouse, $order, $item] = $this->fixture(onHand: 1);

        $results = $this->raceReservations($order, $item, $product, $warehouse, qty: 1);

        $succeeded = array_filter($results, static fn (array $r): bool => $r['ok'] === true);
        $failed = array_filter($results, static fn (array $r): bool => $r['ok'] === false);

        $this->assertCount(1, $succeeded, 'Exactly one attempt should have won the last unit.');
        $this->assertCount(self::PARALLEL_ATTEMPTS - 1, $failed);

        // Every loser must have failed for the right reason. A deadlock or a
        // lock-wait timeout would also show up as "failed" — and would mean the
        // ordered-lock guarantee is not actually holding.
        foreach ($failed as $result) {
            $this->assertSame(
                'insufficient_stock',
                $result['error'],
                'A losing attempt failed for an unexpected reason: '.($result['message'] ?? ''),
            );
        }

        $this->assertPosition($product, $warehouse, onHand: 1, reserved: 1);
        $this->assertSame(1, Reservation::query()->count());
    }

    public function test_concurrent_attempts_never_oversell_a_small_pool(): void
    {
        // 3 units, 8 racers each wanting 1. Exactly 3 must win.
        [$product, $warehouse, $order, $item] = $this->fixture(onHand: 3);

        $results = $this->raceReservations($order, $item, $product, $warehouse, qty: 1);

        $succeeded = array_filter($results, static fn (array $r): bool => $r['ok'] === true);

        $this->assertCount(3, $succeeded);
        $this->assertPosition($product, $warehouse, onHand: 3, reserved: 3);
    }

    public function test_the_same_idempotency_key_raced_in_parallel_reserves_once(): void
    {
        // The hard case for the idempotency table: not a sequential replay, but
        // eight processes claiming the same key simultaneously. The unique index
        // must serialise them, and seven must come back as replays of the first.
        [$product, $warehouse, $order, $item] = $this->fixture(onHand: 10);

        $results = $this->raceReservations(
            $order, $item, $product, $warehouse, qty: 2, key: 'raced-key-001',
        );

        $succeeded = array_filter($results, static fn (array $r): bool => $r['ok'] === true);

        $this->assertNotEmpty($succeeded, 'At least one attempt should have succeeded.');

        // Whatever the interleaving, only one reservation may exist and only two
        // units may be held.
        $this->assertSame(1, Reservation::query()->count());
        $this->assertPosition($product, $warehouse, onHand: 10, reserved: 2);
    }

    public function test_the_ledger_still_reconciles_after_a_race(): void
    {
        [$product, $warehouse, $order, $item] = $this->fixture(onHand: 5);

        $this->raceReservations($order, $item, $product, $warehouse, qty: 1);

        // The real assertion: no matter how the processes interleaved, the sum
        // of the movements equals the cached position. If locking were wrong,
        // this is where a lost update would show up.
        $this->assertLedgerReconciles($product, $warehouse);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{0: Product, 1: Warehouse, 2: Order, 3: OrderItem}
     */
    private function fixture(int $onHand): array
    {
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $ledger = app(InventoryLedger::class);

        DB::transaction(function () use ($ledger, $product, $warehouse, $onHand): void {
            $inventory = $ledger->lockPair((int) $product->id, (int) $warehouse->id);
            $ledger->receipt($inventory, $onHand, $product, ['source' => 'concurrency_fixture']);
        });

        $order = Order::factory()->create();
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty_ordered' => 1000,
        ]);

        return [$product, $warehouse, $order, $item];
    }

    /**
     * Launch N artisan processes that all fire at the same wall-clock instant.
     *
     * @return list<array{ok: bool, error?: string, message?: string}>
     */
    private function raceReservations(
        Order $order,
        OrderItem $item,
        Product $product,
        Warehouse $warehouse,
        int $qty,
        ?string $key = null,
    ): array {
        // A shared start time so the processes collide rather than staggering by
        // however long each one takes to boot the framework. Booting Laravel
        // eight times on Windows is not fast; the window has to be wide enough
        // that the slowest child is still waiting at the barrier when it opens,
        // or the "race" quietly becomes a sequence.
        $startAt = microtime(true) + 4.0;

        $handles = [];

        for ($i = 0; $i < self::PARALLEL_ATTEMPTS; $i++) {
            $handles[] = $this->spawn($order, $item, $product, $warehouse, $qty, $key, $startAt);
        }

        $results = [];

        foreach ($handles as [$process, $pipes]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $results[] = $this->parse($stdout, $stderr, $exitCode);
        }

        // A child that could not boot at all is an infrastructure failure, not a
        // domain outcome. Surfacing it explicitly beats letting it masquerade as
        // "the reservation was refused" — which would make a broken harness look
        // like a passing concurrency guarantee.
        $broken = array_filter($results, static fn (array $r): bool => ($r['error'] ?? null) === 'no_output');

        if ($broken !== []) {
            $first = reset($broken);

            $this->fail(sprintf(
                "%d of %d child processes produced no parseable result.\nexit code: %s\nstderr: %s\nstdout: %s",
                count($broken),
                count($results),
                $first['exit_code'] ?? '?',
                trim((string) ($first['stderr'] ?? '')) ?: '(empty)',
                trim((string) ($first['stdout'] ?? '')) ?: '(empty)',
            ));
        }

        return $results;
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function spawn(
        Order $order,
        OrderItem $item,
        Product $product,
        Warehouse $warehouse,
        int $qty,
        ?string $key,
        float $startAt,
    ): array {
        $command = [
            PHP_BINARY,
            base_path('artisan'),
            'inventory:try-reserve',
            (string) $order->id,
            (string) $item->id,
            (string) $product->id,
            (string) $warehouse->id,
            '--qty='.$qty,
            // %.6F, not string interpolation: PHP's default float precision can
            // round a microsecond timestamp badly enough to desynchronise the
            // barrier the whole race depends on.
            '--start-at='.sprintf('%.6F', $startAt),
        ];

        if ($key !== null) {
            $command[] = '--key='.$key;
        }

        $connection = (string) config('database.default');

        // getenv(), NOT $_ENV. On Windows `variables_order` is usually "GPCS" —
        // no "E" — so $_ENV is empty, and passing it as the base would hand the
        // child an environment with no PATH and no SystemRoot. PHP's MySQL
        // driver needs both, and the child dies before it reaches any of our
        // code, which looks indistinguishable from "the reservation failed".
        $env = array_merge(getenv(), [
            // The child boots from .env, which points at the development
            // database. These overrides move it onto the test database. Laravel
            // loads .env with Dotenv's immutable mode, so real environment
            // variables win over file values — which is exactly what we need.
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => $connection,
            'DB_DATABASE' => (string) config("database.connections.{$connection}.database"),
            'DB_HOST' => (string) config("database.connections.{$connection}.host"),
            'DB_PORT' => (string) config("database.connections.{$connection}.port"),
            'DB_USERNAME' => (string) config("database.connections.{$connection}.username"),
            'DB_PASSWORD' => (string) config("database.connections.{$connection}.password"),
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, base_path(), $env);

        $this->assertIsResource($process, 'Failed to spawn a concurrent reservation process.');

        fclose($pipes[0]);

        return [$process, $pipes];
    }

    /**
     * Scan the child's output for its JSON verdict.
     *
     * Read from the last line backwards: Laravel may emit deprecation notices or
     * other chatter before our line, and the verdict is always last.
     *
     * @return array{ok: bool, error?: string, message?: string, stdout?: string, stderr?: string, exit_code?: int}
     */
    private function parse(string $stdout, string $stderr = '', int $exitCode = 0): array
    {
        foreach (array_reverse(preg_split('/\R/', trim($stdout)) ?: []) as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded) && array_key_exists('ok', $decoded)) {
                return $decoded;
            }
        }

        return [
            'ok' => false,
            'error' => 'no_output',
            'message' => 'Child process produced no parseable verdict.',
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
        ];
    }

    private function assertPosition(Product $product, Warehouse $warehouse, int $onHand, int $reserved): void
    {
        $inventory = Inventory::query()->forPair((int) $product->id, (int) $warehouse->id)->firstOrFail();

        $this->assertSame($onHand, $inventory->on_hand_qty);
        $this->assertSame($reserved, $inventory->reserved_qty);
        $this->assertGreaterThanOrEqual(0, $inventory->availableQty(), 'Stock was oversold.');
    }

    private function assertLedgerReconciles(Product $product, Warehouse $warehouse): void
    {
        $onHandSum = (int) InventoryMovement::query()
            ->forPair((int) $product->id, (int) $warehouse->id)
            ->sum('on_hand_delta');

        $reservedSum = (int) InventoryMovement::query()
            ->forPair((int) $product->id, (int) $warehouse->id)
            ->sum('reserved_delta');

        $inventory = Inventory::query()->forPair((int) $product->id, (int) $warehouse->id)->firstOrFail();

        $this->assertSame($onHandSum, $inventory->on_hand_qty, 'On-hand drifted from the ledger under contention.');
        $this->assertSame($reservedSum, $inventory->reserved_qty, 'Reserved drifted from the ledger under contention.');
    }
}
