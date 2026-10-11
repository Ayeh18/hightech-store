<?php

it('sends the protective headers with every API answer, errors included', function (string $method, string $uri, int $status) {
    $this->json($method, $uri)
        ->assertStatus($status)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=()')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
})->with([
    'a public list' => ['GET', '/api/products', 200],
    'a refused guest' => ['GET', '/api/orders', 401],
    'an unknown address' => ['GET', '/api/nope', 404],
    'a failed validation' => ['GET', '/api/products?per_page=999', 422],
    'the wrong method' => ['DELETE', '/api/auth/login', 405],
]);

it('demands https from then on, but only when the request itself arrived over https', function () {
    $this->getJson('/api/products')->assertHeaderMissing('Strict-Transport-Security');

    $this->getJson('https://localhost/api/products')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    // Behind a host's proxy the request reaches the app over http; the proxy's header says the visitor used https.
    $this->withHeaders(['X-Forwarded-Proto' => 'https'])->getJson('/api/products')->assertHeader('Strict-Transport-Security');
});

/*
| How CORS refuses: the answer always names the ONE website that may read it. A browser showing any other site sees a name
| that is not its own and withholds the answer from that site's JavaScript. So the dangerous outcomes are the answer
| naming the other site, or naming everyone ("*").
*/
it('lets only the shop\'s own website read API answers from a browser', function () {
    config(['cors.allowed_origins' => ['https://shop.example.com']]);

    $this->withHeaders(['Origin' => 'https://shop.example.com'])->getJson('/api/products')
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://shop.example.com')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');

    $other = $this->withHeaders(['Origin' => 'https://evil.example.net'])->getJson('/api/products')->assertOk();

    expect($other->headers->get('Access-Control-Allow-Origin'))->toBe('https://shop.example.com');
});

it('does not grant a browser\'s permission check (preflight) to another website', function () {
    config(['cors.allowed_origins' => ['https://shop.example.com']]);
    $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/cart/products', server: [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ]);

    expect($preflight('https://shop.example.com')->headers->get('Access-Control-Allow-Origin'))->toBe('https://shop.example.com')
        ->and($preflight('https://evil.example.net')->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example.net')->not->toBe('*');
});

it('with several allowed websites, names the caller only when it is one of them', function () {
    config(['cors.allowed_origins' => ['https://shop.example.com', 'https://admin.example.com']]);

    $this->withHeaders(['Origin' => 'https://admin.example.com'])->getJson('/api/products')->assertHeader('Access-Control-Allow-Origin', 'https://admin.example.com');
    $this->withHeaders(['Origin' => 'https://evil.example.net'])->getJson('/api/products')->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('by default allows the address in FRONTEND_URL and never "*"', function () {
    expect(config('cors.allowed_origins'))->toBe([config('shop.frontend_url')])
        ->and(config('cors.allowed_origins'))->not->toContain('*')
        ->and(config('cors.allowed_origins_patterns'))->toBe([]);
});
