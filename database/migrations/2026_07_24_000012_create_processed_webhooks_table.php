<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replay protection for inbound provider callbacks.
 *
 * The quest states outright that the shipping provider may send duplicate
 * delivery confirmations. `provider_event_id` is unique, so the second delivery
 * of the same event is recognized before any inventory is touched and is
 * answered 200 (never 4xx — a retrying provider must be told "already handled",
 * not "bad request", or it will keep retrying).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('provider_event_id', 191);
            $table->string('event_type', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->useCurrent();

            $table->unique(['provider', 'provider_event_id']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_webhooks');
    }
};
