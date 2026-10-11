<?php

use App\Enums\BuilderSlot;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductImage;
use Dedoc\Scramble\Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\ApiDocs\ReferenceCheck;

/*
| The API reference is generated from the code, which keeps it complete. These tests keep it TRUE: they call real
| endpoints with realistic data and check every answer against the reference (see ReferenceCheck). A field that is
| renamed, removed, added or changes type fails here until the reference says the same thing as the API.
*/

/** Collects what is wrong with each answer, so one run shows every difference instead of only the first. */
function againstReference(): object
{
    return new class
    {
        /** @var list<string> */
        public array $problems = [];

        public function check(TestResponse $response, string $method, string $path, ?int $status = null): TestResponse
        {
            if ($status !== null && $response->getStatusCode() !== $status) {
                $this->problems[] = "{$method} {$path}: expected {$status} but got {$response->getStatusCode()}: ".substr((string) $response->getContent(), 0, 300);

                return $response;
            }

            array_push($this->problems, ...ReferenceCheck::problems($response, $method, $path));

            return $response;
        }
    };
}

it('notices an answer that does not match the reference (so a passing run means something)', function () {
    $product = Product::factory()->priced(119900)->stock(5)->create();
    $genuine = $this->getJson('/api/products');
    expect(ReferenceCheck::problems($genuine, 'GET', '/products'))->toBe([]);

    $tamper = function (callable $change) use ($genuine): TestResponse {
        $body = $genuine->json();
        $change($body);

        return new TestResponse(response()->json($body));
    };

    $missing = ReferenceCheck::problems($tamper(function (array &$body) {
        unset($body['data'][0]['sku']);
    }), 'GET', '/products');
    $wrongType = ReferenceCheck::problems($tamper(function (array &$body) {
        $body['data'][0]['price']['amount'] = '1199.00';
    }), 'GET', '/products');
    $extra = ReferenceCheck::problems($tamper(function (array &$body) {
        $body['data'][0]['cost_price'] = 1;
    }), 'GET', '/products');
    $unlisted = ReferenceCheck::problems(new TestResponse(response()->json(['message' => 'x'], 418)), 'GET', '/products');

    expect($missing)->not->toBe([])
        ->and($wrongType)->not->toBe([])
        ->and($extra)->toBe(['GET /products 200: the answer has "data[].cost_price", which the reference does not mention.'])
        ->and($unlisted[0])->toContain('answered 418, which the reference does not list');
});

it('describes the public catalog as it really is', function () {
    Storage::fake('public');
    $ref = againstReference();
    $pc = compatiblePc();
    $product = $pc['cpu'];
    ProductImage::factory()->for($product)->create(['is_primary' => true]);
    Product::factory()->priced(50000, 40000)->stock(2)->create(); // on sale
    Product::factory()->outOfStock()->create();
    $category = $product->category;

    $ref->check($this->getJson('/api/products'), 'GET', '/products', 200);
    $ref->check($this->getJson('/api/products?category='.$category->slug.'&sort=price_asc&per_page=2'), 'GET', '/products', 200);
    $ref->check($this->getJson("/api/products/{$product->slug}"), 'GET', '/products/{product}', 200);
    $ref->check($this->getJson('/api/products/no-such-product'), 'GET', '/products/{product}', 404);
    $ref->check($this->getJson('/api/products?per_page=999'), 'GET', '/products', 422);
    $ref->check($this->getJson('/api/categories'), 'GET', '/categories', 200);
    $ref->check($this->getJson("/api/categories/{$category->slug}"), 'GET', '/categories/{category}', 200);
    $ref->check($this->getJson("/api/categories/{$category->slug}/filters"), 'GET', '/categories/{category}/filters', 200);
    $ref->check($this->getJson('/api/brands'), 'GET', '/brands', 200);

    expect($ref->problems)->toBe([]);
});

it('describes the PC builder as it really is', function () {
    $ref = againstReference();
    $pc = compatiblePc();
    $user = shopper();

    $ref->check($this->getJson('/api/pc-builder/components'), 'GET', '/pc-builder/components', 200);
    $ref->check($this->getJson('/api/pc-builder/parts?slot=cpu'), 'GET', '/pc-builder/parts', 200);
    $ref->check($this->postJson('/api/pc-builder/validate', pcPayload($pc)), 'POST', '/pc-builder/validate', 200);
    // An incompatible build: an Intel-socket CPU on the AM5 board.
    $wrong = [...$pc, 'cpu' => specPart(BuilderSlot::Cpu, ['socket' => 'LGA1700', 'tdp_watts' => 125, 'integrated_graphics' => 'No', 'cooler_included' => 'No'], 90000)];
    $ref->check($this->postJson('/api/pc-builder/validate', pcPayload($wrong)), 'POST', '/pc-builder/validate', 200);
    $ref->check($this->postJson('/api/pc-builder/validate', ['items' => [['slot' => 'nonsense']]]), 'POST', '/pc-builder/validate', 422);

    $ref->check($this->getJson('/api/pc-builder/recommendations/options'), 'GET', '/pc-builder/recommendations/options', 200);
    // A suggested build: enough budget for the six parts above (RM 2,700), then too little.
    $suggested = $ref->check($this->postJson('/api/pc-builder/recommendations', ['budget' => 400000, 'purpose' => 'work', 'games' => ['Valorant']]), 'POST', '/pc-builder/recommendations', 200);
    $ref->check($this->postJson('/api/pc-builder/recommendations', ['budget' => 100000, 'purpose' => 'gaming']), 'POST', '/pc-builder/recommendations', 422);
    $ref->check($this->postJson('/api/pc-builder/recommendations/'.$suggested->json('data.id').'/accept'), 'POST', '/pc-builder/recommendations/{recommendation}/accept', 204);

    $saved = $ref->check($this->actingAs($user)->postJson('/api/builds', pcPayload($pc, ['name' => 'My first PC'])), 'POST', '/builds', 201);
    $ref->check($this->actingAs($user)->getJson('/api/builds'), 'GET', '/builds', 200);
    $ref->check($this->actingAs($user)->getJson('/api/builds/'.$saved->json('data.id')), 'GET', '/builds/{build}', 200);
    $ref->check($this->actingAs($user)->postJson('/api/builds/'.$saved->json('data.id').'/duplicate'), 'POST', '/builds/{build}/duplicate', 201);
    $ref->check($this->actingAs(shopper())->getJson('/api/builds/'.$saved->json('data.id')), 'GET', '/builds/{build}', 404); // not theirs

    expect($ref->problems)->toBe([]);
});

it('describes accounts, the cart and checkout as they really are', function () {
    $ref = againstReference();
    $product = Product::factory()->priced(119900)->stock(5)->create();
    Coupon::factory()->create(['code' => 'SAVE10']);

    $ref->check($this->getJson('/api/auth/me'), 'GET', '/auth/me', 401);
    // spa(): called the way the shop's own website calls, so that there is a session to log in to.
    $ref->check($this->spa()->postJson('/api/auth/register', ['name' => 'Aisyah', 'email' => 'aisyah@example.com', 'password' => 'Secret123', 'password_confirmation' => 'Secret123']), 'POST', '/auth/register', 201);
    $ref->check($this->spa()->postJson('/api/auth/register', ['email' => 'not-an-address']), 'POST', '/auth/register', 422);
    $ref->check($this->spa()->postJson('/api/auth/login', ['email' => 'aisyah@example.com', 'password' => 'Secret123']), 'POST', '/auth/login', 200);
    $ref->check($this->spa()->postJson('/api/auth/login', ['email' => 'aisyah@example.com', 'password' => 'wrong-password']), 'POST', '/auth/login', 422);
    $ref->check($this->spa()->postJson('/api/auth/logout'), 'POST', '/auth/logout', 204);
    $this->flushHeaders();

    $user = shopper();
    $ref->check($this->actingAs($user)->getJson('/api/auth/me'), 'GET', '/auth/me', 200);
    $ref->check($this->actingAs($user)->getJson('/api/cart'), 'GET', '/cart', 200); // empty
    $ref->check(addToCart($user, $product, 2), 'POST', '/cart/products', 201);
    $ref->check(addPc($user, compatiblePc(), ['name' => 'Work PC']), 'POST', '/cart/builds', 201);
    $cart = $ref->check($this->actingAs($user)->getJson('/api/cart'), 'GET', '/cart', 200); // a product and a PC
    $ref->check($this->actingAs($user)->putJson('/api/cart/items/'.$cart->json('data.items.0.id'), ['quantity' => 1]), 'PUT', '/cart/items/{item}', 200);
    $ref->check(addToCart($user, $product, 99), 'POST', '/cart/products', 422);

    $address = $ref->check($this->actingAs($user)->postJson('/api/addresses', ['recipient_name' => 'Aisyah', 'phone' => '0123456789', 'line1' => '1 Jalan Contoh', 'city' => 'Petaling Jaya', 'state' => 'Selangor', 'postcode' => '47810']), 'POST', '/addresses', 201);
    $ref->check($this->actingAs($user)->getJson('/api/addresses'), 'GET', '/addresses', 200);

    $ref->check($this->actingAs($user)->postJson('/api/checkout/preview', ['address_id' => $address->json('data.id'), 'coupon_code' => 'SAVE10']), 'POST', '/checkout/preview', 200);
    $key = ['Idempotency-Key' => 'reference-check-01'];
    $placed = $ref->check($this->actingAs($user)->postJson('/api/checkout', ['address_id' => $address->json('data.id'), 'coupon_code' => 'SAVE10'], $key), 'POST', '/checkout', 201);
    $ref->check($this->actingAs($user)->postJson('/api/checkout', ['address_id' => $address->json('data.id')], $key), 'POST', '/checkout', 200);
    $ref->check($this->actingAs($user)->postJson('/api/checkout', ['address_id' => $address->json('data.id')]), 'POST', '/checkout', 422); // the cart is empty now

    $ref->check($this->actingAs($user)->getJson('/api/orders'), 'GET', '/orders', 200);
    $ref->check($this->actingAs($user)->getJson('/api/orders/'.$placed->json('data.id')), 'GET', '/orders/{order}', 200);
    $ref->check($this->actingAs(shopper())->getJson('/api/orders/'.$placed->json('data.id')), 'GET', '/orders/{order}', 404); // not theirs: as far as they can tell, there is no such order

    expect($ref->problems)->toBe([]);
});

it('describes paying, cancelling and asking for a refund as they really are', function () {
    $ref = againstReference();
    $order = unpaidOrder();
    $user = $order->user;

    $payment = $ref->check(payFor($order), 'POST', '/orders/{order}/payments', 201);
    $ref->check(payFor($order), 'POST', '/orders/{order}/payments', 200); // the payment already waiting is handed out again
    $reference = paymentOf($order)->provider_reference;
    auth()->forgetGuards();
    Queue::fake(); // FakePay would otherwise deliver its webhook over real HTTP; the webhook is sent by hand just below
    $ref->check($this->getJson("/api/fake-gateway/payments/{$reference}"), 'GET', '/fake-gateway/payments/{reference}', 200);
    $ref->check($this->postJson("/api/fake-gateway/payments/{$reference}/complete", ['outcome' => 'succeeded']), 'POST', '/fake-gateway/payments/{reference}/complete', 200);
    $ref->check(gatewayPays(paymentOf($order), 'evt_reference_check'), 'POST', '/webhooks/{provider}', 200);
    $ref->check(gatewayPays(paymentOf($order), 'evt_reference_check'), 'POST', '/webhooks/{provider}', 200); // a duplicate
    $ref->check(sendWebhook(webhookBody(paymentOf($order)), signature: 't=1,v1='.str_repeat('0', 64)), 'POST', '/webhooks/{provider}', 401);

    $ref->check($this->actingAs($user)->getJson("/api/orders/{$order->id}"), 'GET', '/orders/{order}', 200); // now paid
    $delivered = fulfil(paidOrder(), 'delivered');
    $ref->check($this->actingAs($delivered->user)->postJson("/api/orders/{$delivered->id}/refund-request", ['note' => 'It arrived damaged.']), 'POST', '/orders/{order}/refund-request', 201);
    $ref->check($this->actingAs($delivered->user)->getJson("/api/orders/{$delivered->id}"), 'GET', '/orders/{order}', 200); // shipped, with a refund request

    $cancellable = unpaidOrder();
    $ref->check($this->actingAs($cancellable->user)->postJson("/api/orders/{$cancellable->id}/cancel"), 'POST', '/orders/{order}/cancel', 200);

    expect($ref->problems)->toBe([]);
});

it('describes the admin area as it really is', function () {
    $ref = againstReference();
    $admin = withRole('admin');
    $order = fulfil(paidOrder(), 'delivered');
    $this->actingAs($order->user)->postJson("/api/orders/{$order->id}/refund-request", ['note' => 'Changed my mind.'])->assertCreated();
    $paid = paidOrder();
    unpaidOrder();
    Coupon::factory()->create();
    $today = now(config('shop.timezone'))->toDateString();
    $as = fn () => $this->actingAs($admin);

    $ref->check($as()->getJson('/api/admin/dashboard?days=7'), 'GET', '/admin/dashboard', 200);
    $ref->check($as()->getJson('/api/admin/orders'), 'GET', '/admin/orders', 200);
    $ref->check($as()->getJson("/api/admin/orders/{$order->id}"), 'GET', '/admin/orders/{order}', 200);
    $ref->check($as()->getJson('/api/admin/payments'), 'GET', '/admin/payments', 200);
    $ref->check($as()->getJson('/api/admin/payments/'.paymentOf($order)->id), 'GET', '/admin/payments/{payment}', 200);
    $ref->check($as()->getJson('/api/admin/payments/needing-refund'), 'GET', '/admin/payments/needing-refund', 200);
    $refunds = $ref->check($as()->getJson('/api/admin/refunds'), 'GET', '/admin/refunds', 200);
    $ref->check($as()->postJson('/api/admin/refunds/'.$refunds->json('data.0.id').'/approve', []), 'POST', '/admin/refunds/{refund}/approve', 200);
    $ref->check($as()->postJson('/api/admin/payments/'.paymentOf($paid)->id.'/refunds', ['amount' => 1000, 'reason' => 'goodwill', 'note' => 'A small apology.']), 'POST', '/admin/payments/{payment}/refunds', 200);
    $ref->check($as()->getJson("/api/admin/reconciliation?from={$today}&to={$today}"), 'GET', '/admin/reconciliation', 200);
    $ref->check($as()->getJson("/api/admin/reports/sales?from={$today}&to={$today}"), 'GET', '/admin/reports/sales', 200);
    $ref->check($as()->getJson('/api/admin/users'), 'GET', '/admin/users', 200);
    $ref->check($as()->getJson("/api/admin/users/{$order->user_id}"), 'GET', '/admin/users/{user}', 200);
    $ref->check($as()->getJson('/api/admin/coupons'), 'GET', '/admin/coupons', 200);
    $ref->check($as()->getJson('/api/admin/audit-logs'), 'GET', '/admin/audit-logs', 200);
    $ref->check($as()->getJson('/api/admin/audit-logs/actions'), 'GET', '/admin/audit-logs/actions', 200);
    $ref->check($as()->getJson('/api/admin/products'), 'GET', '/admin/products', 200);
    $ref->check($as()->getJson('/api/compatibility-rules'), 'GET', '/compatibility-rules', 200);
    $ref->check($this->actingAs(shopper())->getJson('/api/admin/orders'), 'GET', '/admin/orders', 403);

    expect($ref->problems)->toBe([]);
});

it('describes managing the catalog as it really is', function () {
    Storage::fake('public');
    $ref = againstReference();
    $admin = withRole('admin');
    $product = compatiblePc()['cpu'];
    $as = fn () => $this->actingAs($admin);

    $brand = $ref->check($as()->postJson('/api/brands', ['name' => 'Contoh Tech']), 'POST', '/brands', 201);
    $ref->check($as()->putJson('/api/brands/'.Brand::findOrFail($brand->json('data.id'))->slug, ['name' => 'Contoh Technology']), 'PUT', '/brands/{brand}', 200);
    $ref->check($as()->postJson('/api/brands', []), 'POST', '/brands', 422);
    $ref->check($as()->putJson("/api/products/{$product->slug}", ['price' => 85000]), 'PUT', '/products/{product}', 200);
    $ref->check($as()->postJson("/api/products/{$product->slug}/inventory/adjustments", ['change' => 3, 'reason' => 'restock']), 'POST', '/products/{product}/inventory/adjustments', 200);
    $image = $ref->check($as()->post("/api/products/{$product->slug}/images", ['image' => UploadedFile::fake()->image('a.jpg', 400, 400)], ['Accept' => 'application/json']), 'POST', '/products/{product}/images', 201);
    $ref->check($as()->deleteJson("/api/products/{$product->slug}/images/".$image->json('data.id')), 'DELETE', '/products/{product}/images/{image}', 204);

    expect($ref->problems)->toBe([]);
});

/*
| The reference is also published as a file, backend/openapi.json, so that it can be read on GitHub or loaded into Postman
| without running the shop. A file can go stale; this fails the moment the API and the file disagree.
*/
it('has an up-to-date published copy (run `composer docs` after changing the API)', function () {
    $published = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($published)->toEqual(ReferenceCheck::document());
});

it('documents every endpoint with a summary, a group and its answers', function () {
    $document = ReferenceCheck::document();
    $groups = array_column($document['tags'], 'name');
    $undocumented = [];

    foreach ($document['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            $name = strtoupper($method).' '.$path;

            if (blank($operation['summary'] ?? null)) {
                $undocumented[] = "{$name} has no summary";
            }
            if (count($operation['tags'] ?? []) !== 1 || ! in_array($operation['tags'][0], $groups, true)) {
                $undocumented[] = "{$name} is not in a known group (add its controller to ApiDocsServiceProvider::GROUPS)";
            }
            if (! collect(array_keys($operation['responses']))->contains(fn ($status) => str_starts_with((string) $status, '2'))) {
                $undocumented[] = "{$name} documents no successful answer";
            }
        }
    }

    expect($undocumented)->toBe([])->and(count($document['paths']))->toBeGreaterThan(60);
});

it('says which endpoints need a login, and marks the public ones as public', function () {
    $operation = fn (string $method, string $path) => ReferenceCheck::document()['paths'][$path][$method];

    expect(ReferenceCheck::document()['security'])->toBe([['session' => []]]) // locked unless stated otherwise
        ->and(ReferenceCheck::document()['components']['securitySchemes']['session'])->toMatchArray(['type' => 'apiKey', 'in' => 'cookie', 'name' => config('session.cookie')])
        ->and($operation('get', '/products')['security'])->toBe([])
        ->and($operation('post', '/auth/login')['security'])->toBe([])
        ->and($operation('post', '/webhooks/{provider}')['security'])->toBe([])
        ->and($operation('get', '/orders'))->not->toHaveKey('security')
        ->and($operation('post', '/checkout'))->not->toHaveKey('security');
});

it('takes each endpoint\'s permission and rate limit from the route itself', function () {
    $operation = fn (string $method, string $path) => ReferenceCheck::document()['paths'][$path][$method];

    expect($operation('post', '/admin/payments/{payment}/refunds')['description'])->toContain('**Permission:** `refunds.manage`')->toContain('**Rate limit:** 30 requests per minute')
        ->and($operation('post', '/admin/payments/{payment}/refunds')['responses'])->toHaveKey('429')
        ->and($operation('post', '/checkout')['description'])->toContain('**Rate limit:** 20 requests per minute')->not->toContain('Permission')
        ->and($operation('get', '/products')['description'] ?? '')->not->toContain('Permission')->not->toContain('Rate limit');
});

it('lets anyone read the reference on a developer\'s machine or a demo, and nobody on a real shop', function () {
    $this->get('/docs/api')->assertOk()->assertSee('HighTech Store API');
    $this->getJson('/docs/api.json')->assertOk()->assertJsonPath('info.title', 'HighTech Store API');

    $this->app->detectEnvironment(fn () => 'production');

    $this->get('/docs/api')->assertForbidden();
    $this->getJson('/docs/api.json')->assertForbidden();
});

it('serves the published copy on a server instead of working the reference out again', function () {
    // If the code were analysed here, this stand-in would be called and the test would fail.
    $this->mock(Generator::class)->shouldNotReceive('generate');
    $this->app->detectEnvironment(fn () => 'staging');

    $published = json_decode((string) file_get_contents(base_path('openapi.json')), true);

    expect($this->getJson('/docs/api.json')->assertOk()->json())->toEqual($published);
    $this->get('/docs/api')->assertOk()->assertSee('HighTech Store API');
});
