<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ULID primary key: sortable by creation time, but unlike 1, 2, 3 it cannot be guessed from a URL.
        Schema::create('pc_builds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        // A build stores WHICH products and how many, never prices: the price is always read live.
        Schema::create('pc_build_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('pc_build_id')->constrained('pc_builds')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('slot', 16);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['pc_build_id', 'slot']);
            $table->index('product_id');
        });

        DB::statement('ALTER TABLE pc_build_items ADD CONSTRAINT pc_build_items_quantity_range CHECK (quantity BETWEEN 1 AND 8)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pc_build_items');
        Schema::dropIfExists('pc_builds');
    }
};
