<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One cart per customer. (Guests must log in to use a cart.)
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // A line in the cart: either one product ("product") or a whole PC ("build").
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // product | build
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            // The saved build this PC was added from (if any). Deleting the saved build must not empty the cart.
            $table->foreignUlid('pc_build_id')->nullable()->constrained('pc_builds')->nullOnDelete();
            $table->string('name')->nullable(); // build lines: the build's name when it was added
            $table->unsignedSmallInteger('quantity')->default(1);
            // The price the customer saw when adding (sen): per product, or the total of ONE build.
            // Used only to warn "the price changed". What they pay is always the CURRENT price.
            $table->unsignedBigInteger('price_when_added');
            $table->timestamps();

            $table->index('cart_id');
        });

        DB::statement("ALTER TABLE cart_items ADD CONSTRAINT cart_items_shape CHECK ((kind = 'product' AND product_id IS NOT NULL) OR (kind = 'build' AND product_id IS NULL))");
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_range CHECK (quantity BETWEEN 1 AND 99)');
        // The same product is one line with a quantity, never two lines (also under concurrent requests).
        DB::statement("CREATE UNIQUE INDEX cart_items_one_line_per_product ON cart_items (cart_id, product_id) WHERE kind = 'product'");

        // What a build line was made of when it was added: which products, how many, at what price.
        Schema::create('cart_item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('slot', 16);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price'); // sen, when added
            $table->timestamps();

            $table->unique(['cart_item_id', 'slot']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_item_components');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
