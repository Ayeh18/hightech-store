<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One counter row per year. Updated with a single atomic UPSERT, so order numbers never repeat or skip under load.
        Schema::create('order_number_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('order_number', 20)->unique(); // ORD-2026-00001
            // Orders are financial records: they must outlive anything else, so a user with orders cannot be deleted.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // The two lifecycles are separate on purpose: what is happening to the goods vs. what is happening to the money.
            $table->string('status', 24);          // PENDING_PAYMENT, PAID, PROCESSING, ... (App\Enums\OrderStatus)
            $table->string('payment_status', 16);  // PENDING, PAID, FAILED, ... (App\Enums\PaymentStatus)

            // Money, in sen. total = subtotal - discount_total + shipping_total.
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('shipping_total')->default(0);
            $table->unsignedBigInteger('total');
            $table->string('currency', 3)->default('MYR');

            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code', 40)->nullable(); // kept even if the coupon is later deleted

            // Where it ships: a copy, not a link, so later address edits cannot change a placed order.
            $table->string('shipping_name', 100);
            $table->string('shipping_phone', 32);
            $table->string('shipping_line1');
            $table->string('shipping_line2')->nullable();
            $table->string('shipping_city', 100);
            $table->string('shipping_state', 32);
            $table->string('shipping_postcode', 5);
            $table->string('shipping_country', 2);

            $table->string('notes', 500)->nullable();
            // Sent by the browser with checkout so a double click or a retry cannot create two orders.
            $table->string('idempotency_key', 64)->nullable();

            $table->timestamp('expires_at')->nullable();  // unpaid orders release their stock after this
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement('CREATE UNIQUE INDEX orders_idempotency_per_user ON orders (user_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_adds_up CHECK (total = subtotal - discount_total + shipping_total AND discount_total <= subtotal)');

        // One row per product bought, with the name, SKU and price AS THEY WERE. Parts of a PC share a build_group.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price'); // sen, at the time of purchase
            $table->unsignedBigInteger('line_total'); // unit_price x quantity
            $table->string('slot', 16)->nullable();        // builder slot, for parts of a PC
            $table->string('build_group', 40)->nullable(); // same value = parts of the same PC
            $table->string('build_name')->nullable();
            $table->unsignedSmallInteger('build_copies')->nullable(); // how many PCs of this build were ordered
            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0)');

        // Which coupon redemptions count. A released usage (order cancelled or expired) frees the coupon again.
        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('discount_amount');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['coupon_id', 'released_at']);
            $table->index(['coupon_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_number_sequences');
    }
};
