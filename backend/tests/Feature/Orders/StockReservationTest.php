<?php

use App\Models\Product;
use App\Services\Orders\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('reserves units and leaves stock alone', function () {
    $product = Product::factory()->stock(10)->create();

    app(StockReservation::class)->reserve([$product->id => 4]);

    expect(inventoryOf($product)->reserved)->toBe(4)
        ->and(inventoryOf($product)->stock)->toBe(10)
        ->and(inventoryOf($product)->available)->toBe(6);
});

it('can reserve exactly what is available, and not one more', function () {
    $product = Product::factory()->stock(5, reserved: 2)->create(['name' => 'Edge Case']); // 3 available

    app(StockReservation::class)->reserve([$product->id => 3]);
    expect(inventoryOf($product)->available)->toBe(0);

    expect(fn () => app(StockReservation::class)->reserve([$product->id => 1]))->toThrow(ValidationException::class);
    expect(inventoryOf($product)->reserved)->toBe(5);
});

it('reserves nothing at all when any one product is short (all or nothing)', function () {
    [$plenty, $scarce] = [Product::factory()->stock(10)->create(), Product::factory()->stock(2)->create(['name' => 'Scarce'])];

    try {
        app(StockReservation::class)->reserve([$plenty->id => 5, $scarce->id => 3]);
        $this->fail('Expected the reservation to be refused.');
    } catch (ValidationException $e) {
        expect($e->errors()['cart'])->toBe(['Scarce: only 2 left, but your order needs 3.']);
    }

    expect(inventoryOf($plenty)->reserved)->toBe(0)->and(inventoryOf($scarce)->reserved)->toBe(0);
});

it('names every short product, and distinguishes "out of stock" from "only a few left"', function () {
    $out = Product::factory()->outOfStock()->create(['name' => 'Gone']);
    $few = Product::factory()->stock(2)->create(['name' => 'Almost Gone']);

    try {
        app(StockReservation::class)->reserve([$out->id => 1, $few->id => 5]);
        $this->fail('Expected the reservation to be refused.');
    } catch (ValidationException $e) {
        expect($e->errors()['cart'])->toBe(['Gone is out of stock.', 'Almost Gone: only 2 left, but your order needs 5.']);
    }
});

it('releases units, and never drops below zero if released twice', function () {
    $product = Product::factory()->stock(10, reserved: 3)->create();

    app(StockReservation::class)->release([$product->id => 3]);
    expect(inventoryOf($product)->reserved)->toBe(0);

    app(StockReservation::class)->release([$product->id => 3]); // a repeated release must be harmless
    expect(inventoryOf($product)->reserved)->toBe(0);
});

it('takes its row locks in product-id order whatever order the demand arrives in (so checkouts cannot deadlock)', function () {
    [$a, $b, $c] = Product::factory()->count(3)->stock(10)->create()->all();

    DB::enableQueryLog();
    app(StockReservation::class)->reserve([$c->id => 1, $a->id => 1, $b->id => 1]);
    $lockQuery = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'for update'));

    expect($lockQuery)->toContain('order by "product_id" asc');
});

it('does not partly reserve when the surrounding transaction is rolled back', function () {
    $product = Product::factory()->stock(10)->create();

    try {
        DB::transaction(function () use ($product) {
            app(StockReservation::class)->reserve([$product->id => 4]);
            throw new RuntimeException('something later in checkout failed');
        });
    } catch (RuntimeException) {
    }

    expect(inventoryOf($product)->reserved)->toBe(0);
});
