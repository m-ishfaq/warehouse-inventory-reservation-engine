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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('qty_ordered');
            $table->unsignedInteger('qty_reserved')->default(0);
            $table->unsignedInteger('qty_shipped')->default(0);
            $table->unsignedInteger('qty_cancelled')->default(0);

            $table->timestamps();

            $table->index(['order_id', 'product_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            // A line can never ship more than was ordered, and reserved +
            // cancelled can never exceed what was ordered either.
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_shipped_within_ordered CHECK (qty_shipped <= qty_ordered)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_allocation_within_ordered CHECK (qty_reserved + qty_shipped + qty_cancelled <= qty_ordered)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
