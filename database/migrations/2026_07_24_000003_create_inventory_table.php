<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The `inventory` table is a MATERIALISED CACHE, not the source of truth.
 *
 * Truth lives in `inventory_movements` (append-only). Every write to this table
 * happens inside the same transaction as the movement row that justifies it, so
 * the two can never diverge. `inventory:verify` proves it by replaying the ledger.
 *
 * Why cache at all? Replaying millions of movements to answer "what is available
 * right now" is not viable, and the reservation hot path needs a single row to
 * lock. This table gives us both: one lockable row per (product, warehouse), and
 * O(1) availability reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            // Physically present in the warehouse. Only shipments and transfers move this.
            $table->unsignedInteger('on_hand_qty')->default(0);

            // Promised to open reservations. available = on_hand_qty - reserved_qty.
            $table->unsignedInteger('reserved_qty')->default(0);

            // Fulfilment sub-stages. Both are subsets of reserved_qty.
            //
            // RESERVED FOR FUTURE USE — nothing raises these today. The engine
            // implements reserve → ship, so both stay 0 and are not reported by
            // the API. They are kept because a pick/pack workflow is the obvious
            // next increment and adding columns to a hot, locked table later is
            // far more disruptive than carrying two unused ones now.
            //
            // Note this also means the CHECK constraints below are currently
            // trivially satisfied (0 <= anything). They are not doing work yet.
            $table->unsignedInteger('picked_qty')->default(0);
            $table->unsignedInteger('packed_qty')->default(0);

            // Optimistic-concurrency counter, bumped on every mutation. We lock
            // pessimistically, so this is an audit/debug aid rather than the
            // primary safety mechanism — see docs/ARCHITECTURE.md.
            $table->unsignedBigInteger('version')->default(0);

            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);
            $table->index(['warehouse_id', 'product_id']);
        });

        // ------------------------------------------------------------------
        // Layer 3 of the anti-overselling defence.
        //
        // Layer 1 is the row lock, layer 2 is the in-transaction availability
        // re-check. These constraints are what makes the invariant structural:
        // even a future bug, a bad migration, or somebody poking the DB by hand
        // cannot leave inventory in an impossible state.
        // ------------------------------------------------------------------
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE inventory ADD CONSTRAINT chk_inventory_reserved_within_on_hand CHECK (reserved_qty <= on_hand_qty)');
            DB::statement('ALTER TABLE inventory ADD CONSTRAINT chk_inventory_picked_within_reserved CHECK (picked_qty <= reserved_qty)');
            DB::statement('ALTER TABLE inventory ADD CONSTRAINT chk_inventory_packed_within_picked CHECK (packed_qty <= picked_qty)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
