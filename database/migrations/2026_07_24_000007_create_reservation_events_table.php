<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of every reservation state change.
 *
 * The quest asks for a "reservation history" table. This is deliberately an
 * event log rather than a mutable audit column set: it answers "why is this
 * reservation in this state" without any interpretation, which is the question
 * warehouse operators actually ask when stock looks wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->integer('qty_delta')->default(0);
            $table->string('reason', 128)->nullable();
            $table->string('actor', 128)->nullable();
            $table->json('context')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['reservation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_events');
    }
};
