<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency as a table, not a try/catch.
 *
 * Every mutating operation claims (scope, key) by INSERT before doing any work.
 * The unique index is the whole mechanism:
 *
 *   - Two concurrent duplicates: the second INSERT blocks on the index until the
 *     first transaction commits, then fails as a duplicate, re-reads the stored
 *     response and returns it. No double execution, no application-level lock.
 *
 *   - A replay minutes later: the row is already `completed`, so the stored
 *     response is returned without touching inventory.
 *
 *   - A crash mid-operation: the transaction rolls back and takes the claim row
 *     with it, so the retry is free to run for real.
 *
 * This single table covers four of the quest's required failure scenarios:
 * duplicate command, duplicate job execution, duplicate webhook, worker retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();

            // Scope keeps key namespaces apart, so a caller reusing "order-99"
            // for both a reservation and a shipment is not a collision.
            $table->string('scope', 64);
            $table->string('key', 191);

            $table->string('status', 32)->default('in_progress');
            $table->json('response')->nullable();

            $table->timestamp('locked_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();

            $table->unique(['scope', 'key']);
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
