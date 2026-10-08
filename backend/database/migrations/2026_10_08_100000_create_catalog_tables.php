<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trigram indexes make "ILIKE '%ryzen%'" searches fast.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // Which PC-builder slot this category fills (cpu, gpu, ...). NULL = not a PC component.
            // Stored in the database so new categories need no code change.
            $table->string('builder_slot')->nullable()->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->timestamps();
        });

        // Describes WHICH specifications a category has (label, type, unit, options, filterable).
        Schema::create('spec_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('label');
            $table->string('type', 16); // string | enum | number | list
            $table->string('unit', 16)->nullable();
            $table->jsonb('options')->nullable(); // allowed values for enum / list
            $table->boolean('is_required')->default(true);
            $table->boolean('is_filterable')->default(false);
            $table->boolean('show_on_card')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'key']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->constrained()->restrictOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // Money is stored as integer sen (RM 1.00 = 100). See App\Support\Money.
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('discount_price')->nullable();
            $table->string('status', 16)->default('draft'); // draft | active | archived
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->string('warranty_info')->nullable();
            $table->unsignedInteger('sold_count')->default(0); // drives the "popular" sort
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'status']);
            $table->index(['brand_id', 'status']);
            $table->index('price');
            $table->index('sold_count');
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_discount_below_price CHECK (discount_price IS NULL OR discount_price < price)');
        DB::statement('CREATE INDEX products_name_trgm ON products USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX products_sku_trgm ON products USING gin (sku gin_trgm_ops)');

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        // One row per (product, spec key). The value lives in the column matching the definition's type.
        Schema::create('product_specifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('value_string')->nullable();
            $table->decimal('value_number', 12, 3)->nullable();
            $table->jsonb('value_json')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'key']);
            $table->index(['key', 'value_string']);
            $table->index(['key', 'value_number']);
        });

        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->timestamps();
        });

        // available = stock - reserved must never go negative, even if application code has a bug.
        DB::statement('ALTER TABLE inventories ADD CONSTRAINT inventories_reserved_within_stock CHECK (reserved <= stock)');

        // Append-only ledger of every stock change, so the numbers can always be explained.
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('change');
            $table->unsignedInteger('stock_after');
            $table->string('reason', 32); // adjustment | restock | sale | return | correction
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('product_specifications');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('spec_definitions');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
