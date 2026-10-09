<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The customer's address book. Orders copy the address they ship to, so editing this later never rewrites history.
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50)->nullable(); // "Home", "Office"
            $table->string('recipient_name', 100);
            $table->string('phone', 32);
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city', 100);
            $table->string('state', 32);
            $table->string('postcode', 5);
            $table->string('country', 2)->default('MY');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('user_id');
        });

        // At most one default address per customer, enforced by the database itself.
        DB::statement('CREATE UNIQUE INDEX addresses_one_default_per_user ON addresses (user_id) WHERE is_default');

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique(); // stored in UPPERCASE
            $table->string('description')->nullable();
            $table->string('type', 8); // percent | fixed
            // percent: basis points (1000 = 10%). fixed: sen (5000 = RM 50.00).
            $table->unsignedBigInteger('value');
            $table->unsignedBigInteger('min_order_amount')->default(0); // sen
            $table->unsignedBigInteger('max_discount_amount')->nullable(); // sen: caps a percentage coupon
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable(); // total redemptions across everyone
            $table->unsignedSmallInteger('per_user_limit')->nullable()->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('addresses');
    }
};
