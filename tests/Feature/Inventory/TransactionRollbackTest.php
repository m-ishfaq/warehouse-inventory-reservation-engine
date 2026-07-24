<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Exceptions\InventoryInvariantViolation;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Models\InventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\Concerns\BuildsInventoryFixtures;
use Tests\TestCase;

/**
 * Scenario: "SQL transaction rollback after failure".
 *
 * The property being protected: the ledger movement and the cache update are
 * written in the same transaction, so they can never disagree — not even for
 * the instant between two statements.
 */
class TransactionRollbackTest extends TestCase
{
    use BuildsInventoryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory(onHand: 10);
    }

    public function test_a_failure_after_posting_rolls_back_both_the_movement_and_the_cache(): void
    {
        $movementsBefore = InventoryMovement::query()->count();

        try {
            DB::transaction(function (): void {
                $ledger = app(InventoryLedger::class);
                $inventory = $ledger->lockPair((int) $this->product->id, (int) $this->warehouse->id);

                $ledger->reserve($inventory, 4, $this->order);

                throw new RuntimeException('Simulated crash after the ledger posting.');
            });

            $this->fail('Expected the exception to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertStock(onHand: 10, reserved: 0);
        $this->assertSame($movementsBefore, InventoryMovement::query()->count(), 'An orphaned movement survived the rollback.');
    }

    // NOTE: the "ledger refuses to post outside a transaction" guard cannot be
    // tested here. RefreshDatabase wraps every test in an uncommitted
    // transaction, so DB::transactionLevel() is never zero inside this class.
    // That assertion lives in LedgerTransactionGuardTest, which truncates
    // instead of wrapping. Testing it here would have quietly asserted nothing.

    public function test_the_ledger_refuses_a_posting_that_would_go_negative(): void
    {
        $this->expectException(InventoryInvariantViolation::class);

        DB::transaction(function (): void {
            $ledger = app(InventoryLedger::class);
            $inventory = $ledger->lockPair((int) $this->product->id, (int) $this->warehouse->id);

            $ledger->ship($inventory, 999, $this->order);
        });
    }

    public function test_the_ledger_refuses_reserved_exceeding_on_hand(): void
    {
        $this->expectException(InventoryInvariantViolation::class);

        DB::transaction(function (): void {
            $ledger = app(InventoryLedger::class);
            $inventory = $ledger->lockPair((int) $this->product->id, (int) $this->warehouse->id);

            $ledger->reserve($inventory, 11, $this->order);
        });
    }

    public function test_movements_are_immutable(): void
    {
        $movement = InventoryMovement::query()->firstOrFail();

        $this->expectException(RuntimeException::class);

        // History is not editable. A correction is a new adjustment movement,
        // never a rewrite of what was recorded at the time.
        $movement->update(['qty' => 999]);
    }

    public function test_movements_cannot_be_deleted(): void
    {
        $movement = InventoryMovement::query()->firstOrFail();

        $this->expectException(RuntimeException::class);

        $movement->delete();
    }

    public function test_a_partially_successful_batch_rolls_back_entirely(): void
    {
        try {
            DB::transaction(function (): void {
                $ledger = app(InventoryLedger::class);
                $inventory = $ledger->lockPair((int) $this->product->id, (int) $this->warehouse->id);

                $ledger->reserve($inventory, 3, $this->order);
                $ledger->reserve($inventory, 3, $this->order);
                $ledger->reserve($inventory, 3, $this->order);

                // Fourth would exceed availability.
                $ledger->reserve($inventory, 3, $this->order);
            });
        } catch (InventoryInvariantViolation) {
            // expected
        }

        // None of the three successful postings survive.
        $this->assertStock(onHand: 10, reserved: 0);
    }
}
