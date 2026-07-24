<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE SOURCE OF TRUTH. Append-only: no UPDATE, no DELETE, ever.
 *
 * Two signed deltas rather than one, because several movement types touch both
 * buckets at once (a shipment lowers on_hand AND releases the reservation that
 * authorized it). Keeping them separate means reconciliation is a plain SUM per
 * column with no interpretation of the movement type:
 *
 *     SUM(on_hand_delta)  == inventory.on_hand_qty
 *     SUM(reserved_delta) == inventory.reserved_qty
 *
 * That is exactly what `php artisan inventory:verify` asserts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            $table->string('type', 32);

            // Absolute magnitude, for human-readable history.
            $table->unsignedInteger('qty');

            // Signed effect on each bucket. Either may be zero.
            $table->integer('on_hand_delta')->default(0);
            $table->integer('reserved_delta')->default(0);

            // Bucket balances immediately after this movement was applied, so a
            // history view can be rendered without re-summing the whole ledger.
            $table->unsignedInteger('on_hand_after');
            $table->unsignedInteger('reserved_after');

            // Polymorphic pointer at whatever authorized the movement
            // (reservation, shipment, transfer, manual adjustment).
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->string('actor', 128)->nullable();
            $table->json('context')->nullable();

            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['product_id', 'warehouse_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
