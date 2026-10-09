<?php

use App\Models\Product;

it('counts each rate-limited route separately, so cart edits cannot use up the allowance for placing an order', function () {
    $user = shopper();
    $product = Product::factory()->stock(50)->create();

    $first = $this->actingAs($user)->postJson('/api/cart/products', ['product_id' => $product->id, 'quantity' => 1]);
    $itemId = $first->json('data.items.0.id');
    foreach (range(1, 5) as $_) {
        $this->actingAs($user)->putJson("/api/cart/items/{$itemId}", ['quantity' => 2])->assertOk();
        $this->actingAs($user)->postJson('/api/checkout/preview')->assertOk();
    }

    $checkout = $this->actingAs($user)->postJson('/api/checkout', []);

    expect($checkout->headers->get('X-RateLimit-Limit'))->toBe('20')
        ->and($checkout->headers->get('X-RateLimit-Remaining'))->toBe('19'); // its own counter: only this one request so far
});

it('still stops a customer who really hammers one route', function () {
    $user = shopper();

    foreach (range(1, 20) as $_) {
        $this->actingAs($user)->postJson('/api/checkout', [])->assertUnprocessable();
    }

    $this->actingAs($user)->postJson('/api/checkout', [])->assertStatus(429);
    // ...but other things still work for them.
    $this->actingAs($user)->getJson('/api/cart')->assertOk();
});
