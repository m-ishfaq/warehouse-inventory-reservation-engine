<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Services\InventoryLedger;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * The ledger refuses to post outside a transaction. Proving that requires there
 * to genuinely be no transaction open — which rules out RefreshDatabase, whose
 * uncommitted wrapping transaction means DB::transactionLevel() is never zero.
 *
 * This is a real trap rather than a technicality: written the obvious way, the
 * assertion sits inside RefreshDatabase, never sees level 0, and fails. Had it
 * been written to expect no exception instead, it would have "passed" forever
 * while asserting nothing about the guard at all.
 *
 * CAUTION for anyone adding tests here: DatabaseTruncation truncates at setUp,
 * NOT at tearDown, and this class commits everything it writes. Whatever the
 * last test in this class creates therefore survives into later test classes,
 * which use RefreshDatabase and never truncate. Assertions elsewhere must be
 * scoped to their own fixtures — a global COUNT(*) will pass in isolation and
 * fail in a full run, purely depending on execution order.
 */
class LedgerTransactionGuardTest extends TestCase
{
    use DatabaseTruncation;

    public function test_transaction_level_is_zero_in_this_test_class(): void
    {
        // Guards the guard: if a future change reintroduces a wrapping
        // transaction here, the test below would silently stop testing anything.
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_the_ledger_refuses_to_post_outside_a_transaction(): void
    {
        $inventory = $this->position();

        $this->expectException(LogicException::class);

        // Without this guard a caller who forgot the transaction would appear to
        // work in development and corrupt stock the first time an operation
        // failed halfway through — the movement committed, the cache update not,
        // or vice versa.
        app(InventoryLedger::class)->reserve($inventory, 1);
    }

    public function test_locking_also_refuses_to_run_outside_a_transaction(): void
    {
        $this->expectException(LogicException::class);

        // A lock taken outside a transaction is released immediately, so this
        // failing loudly matters as much as the posting guard.
        app(InventoryLedger::class)->lockPair(1, 1);
    }

    public function test_the_same_posting_succeeds_inside_a_transaction(): void
    {
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();

        DB::transaction(function () use ($product, $warehouse): void {
            $ledger = app(InventoryLedger::class);
            $inventory = $ledger->lockPair((int) $product->id, (int) $warehouse->id);

            $ledger->receipt($inventory, 5, $product);
            $ledger->reserve($inventory, 2, $product);
        });

        $inventory = Inventory::query()
            ->forPair((int) $product->id, (int) $warehouse->id)
            ->firstOrFail();

        $this->assertSame(5, $inventory->on_hand_qty);
        $this->assertSame(2, $inventory->reserved_qty);
        $this->assertSame(3, $inventory->availableQty());
    }

    private function position(): Inventory
    {
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();

        // Created directly rather than through the ledger, precisely because the
        // ledger is what we are about to call without a transaction.
        return Inventory::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'on_hand_qty' => 10,
            'reserved_qty' => 0,
        ]);
    }
}
