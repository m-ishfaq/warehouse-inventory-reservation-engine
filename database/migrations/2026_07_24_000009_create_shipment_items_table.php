<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();

            // A shipment line consumes a specific reservation, which is what ties
            // the physical movement back to the claim that authorised it.
            $table->foreignId('reservation_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('qty_requested');
            $table->unsignedInteger('qty_shipped')->default(0);

            $table->timestamps();

            $table->unique(['shipment_id', 'reservation_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE shipment_items ADD CONSTRAINT chk_shipment_items_shipped_within_requested CHECK (qty_shipped <= qty_requested)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_items');
    }
};
