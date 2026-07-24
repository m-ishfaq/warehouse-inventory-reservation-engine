<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A reservation is a claim on stock in one specific warehouse.
 *
 * Lifecycle: active -> partially_consumed -> consumed
 *                   \-> released
 *                   \-> expired
 *
 * `qty` is the size of the original claim and never changes. `qty_consumed`
 * grows as shipments confirm, and `qty_released` grows as the claim is given
 * back. The still-held amount is qty - qty_consumed - qty_released, which is
 * exactly what a release or expiry needs to return to available stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('qty');
            $table->unsignedInteger('qty_consumed')->default(0);
            $table->unsignedInteger('qty_released')->default(0);

            $table->string('status', 32)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            // Expiry sweep: find active reservations past their deadline.
            $table->index(['status', 'expires_at']);
            // Stock queries: which open claims exist on this product/warehouse.
            $table->index(['product_id', 'warehouse_id', 'status']);
            $table->index(['order_item_id', 'status']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE reservations ADD CONSTRAINT chk_reservations_settled_within_qty CHECK (qty_consumed + qty_released <= qty)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
