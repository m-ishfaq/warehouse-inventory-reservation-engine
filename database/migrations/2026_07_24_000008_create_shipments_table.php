<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle: pending -> dispatched -> shipped | partially_shipped | failed
 *
 * `dispatched` is the dangerous state: we have handed the shipment to the
 * provider but do not yet know the outcome. A timeout leaves us here, and a
 * late confirmation is matched back by `provider_ref`. Inventory is NOT deducted
 * on dispatch — only on confirmation — so a shipment stuck in `dispatched`
 * costs us a held reservation, never lost stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            $table->string('status', 32)->default('pending');

            // Provider-side identifier. Unique so a retried dispatch cannot
            // create a second shipment against the same provider consignment.
            $table->string('provider_ref', 128)->nullable()->unique();

            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
