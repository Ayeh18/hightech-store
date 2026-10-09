<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests hit a real PostgreSQL test database (pc_store_testing), rebuilt inside a
| transaction per test, and always start with the roles/permissions seeded.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(RolePermissionSeeder::class))
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
| Shared test helpers for the PC builder.
*/

use App\Enums\BuilderSlot;
use App\Models\Address;
use App\Models\Category;
use App\Models\FakeGatewayPayment;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PcBuild;
use App\Models\PcBuildItem;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\User;
use App\Services\Payments\WebhookSigner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** A product that belongs in a builder slot (the category for the slot is created once and reused). */
function partFor(BuilderSlot $slot, int $sen = 10000, array $attributes = []): Product
{
    $category = Category::firstOrCreate(
        ['builder_slot' => $slot->value],
        ['slug' => "cat-{$slot->value}", 'name' => ucfirst($slot->value), 'is_active' => true],
    );

    return Product::factory()->for($category)->priced($sen)->create($attributes);
}

/** @return array{slot: string, product_id: int, quantity: int} */
function buildItem(BuilderSlot $slot, Product $product, int $quantity = 1): array
{
    return ['slot' => $slot->value, 'product_id' => $product->id, 'quantity' => $quantity];
}

/** One in-stock part for every required slot: [items payload, products keyed by slot value]. */
function completeBuild(int $sen = 10000): array
{
    $items = [];
    $products = [];

    foreach (array_filter(BuilderSlot::cases(), fn (BuilderSlot $s) => $s->isRequired()) as $slot) {
        $products[$slot->value] = partFor($slot, $sen);
        $items[] = buildItem($slot, $products[$slot->value]);
    }

    return [$items, $products];
}

/**
 * A builder part with specs, e.g. specPart(BuilderSlot::Cpu, ['socket' => 'AM5', 'tdp_watts' => 65]).
 * Strings become text specs, numbers numeric specs and arrays list specs.
 */
function specPart(BuilderSlot $slot, array $specs, int $sen = 10000, array $attributes = []): Product
{
    $product = partFor($slot, $sen, $attributes);

    foreach ($specs as $key => $value) {
        ProductSpecification::create([
            'product_id' => $product->id,
            'key' => $key,
            ...match (true) {
                is_array($value) => ['value_json' => $value],
                is_int($value) || is_float($value) => ['value_number' => $value],
                default => ['value_string' => $value],
            },
        ]);
    }

    return $product->load('specifications');
}

/*
| Shared helpers for cart and checkout tests.
*/

function shopper(): User
{
    $user = User::factory()->create();
    $user->assignRole('customer');

    return $user;
}

function addToCart(User $user, Product $product, int $quantity = 1)
{
    return test()->actingAs($user)->postJson('/api/cart/products', ['product_id' => $product->id, 'quantity' => $quantity]);
}

function cartOf(User $user)
{
    return test()->actingAs($user)->getJson('/api/cart');
}

/*
| A complete, compatible PC for cart and checkout tests.
*/

/**
 * A complete, compatible PC (AM5 + DDR5, a roomy case, a strong PSU), 20 of each part in stock.
 *
 * @return array<string, Product> keyed by slot value
 */
function compatiblePc(): array
{
    return [
        'cpu' => specPart(BuilderSlot::Cpu, ['socket' => 'AM5', 'tdp_watts' => 65, 'integrated_graphics' => 'Yes', 'cooler_included' => 'Yes'], 80000),
        'motherboard' => specPart(BuilderSlot::Motherboard, ['socket' => 'AM5', 'ram_type' => 'DDR5', 'form_factor' => 'ATX', 'ram_slots' => 4, 'max_ram_gb' => 192, 'm2_slots' => 3], 60000),
        'ram' => specPart(BuilderSlot::Ram, ['ram_type' => 'DDR5', 'modules' => 2, 'capacity_gb' => 32], 30000),
        'storage' => specPart(BuilderSlot::Storage, ['form_factor' => 'M.2 2280'], 25000),
        'psu' => specPart(BuilderSlot::Psu, ['wattage' => 750, 'form_factor' => 'ATX'], 40000),
        'case' => specPart(BuilderSlot::Case_, ['supported_form_factors' => ['ATX'], 'gpu_max_length_mm' => 350, 'cpu_cooler_max_height_mm' => 170, 'psu_form_factor' => 'ATX'], 35000),
    ];
}

function pcPayload(array $pc, array $extra = []): array
{
    return [
        'items' => array_map(fn ($product, $slot) => buildItem(BuilderSlot::from($slot), $product), $pc, array_keys($pc)),
        ...$extra,
    ];
}

function addPc(User $user, array $pc, array $extra = [])
{
    return test()->actingAs($user)->postJson('/api/cart/builds', pcPayload($pc, $extra));
}

function savedPc(User $user, array $pc, string $name = 'Gaming PC'): PcBuild
{
    $build = PcBuild::factory()->for($user)->create(['name' => $name]);

    foreach ($pc as $slot => $product) {
        PcBuildItem::factory()->create(['pc_build_id' => $build->id, 'product_id' => $product->id, 'slot' => $slot]);
    }

    return $build;
}

/*
| Shared helpers for payment tests. An order is placed through the real checkout API (so stock is reserved exactly as in
| production), and webhooks are posted signed with the test secret from phpunit.xml.
*/

/**
 * A real unpaid order. Returns the order; its product is `$order->items->first()->product`.
 *
 * @param  array<int, array{0: Product, 1: int}>|null  $lines  product => quantity pairs (default: one new product)
 */
function unpaidOrder(?User $user = null, ?array $lines = null, array $extra = []): Order
{
    $user ??= shopper();
    $lines ??= [[Product::factory()->priced(100000)->stock(10)->create(), 1]];

    foreach ($lines as [$product, $quantity]) {
        addToCart($user, $product, $quantity)->assertCreated();
    }

    $address = Address::factory()->for($user)->create();
    $id = test()->actingAs($user)->postJson('/api/checkout', ['address_id' => $address->id, ...$extra])->assertCreated()->json('data.id');

    return Order::findOrFail($id);
}

/** "Pay now": the customer asks for a payment page for the order. */
function payFor(Order $order, ?User $as = null)
{
    return test()->actingAs($as ?? $order->user)->postJson("/api/orders/{$order->id}/payments");
}

/** The order's open payment, created by payFor(). */
function paymentOf(Order $order): Payment
{
    return Payment::where('order_id', $order->id)->latest('created_at')->latest('id')->firstOrFail();
}

/** The JSON a gateway would send. `$amount` defaults to what the payment was created for. */
function webhookBody(Payment $payment, string $type = 'payment.succeeded', ?int $amount = null, ?string $eventId = null, array $data = []): string
{
    return json_encode([
        'id' => $eventId ?? 'evt_'.Str::lower((string) Str::ulid()),
        'type' => $type,
        'created' => time(),
        'data' => [
            'reference' => $payment->provider_reference,
            'amount' => $amount ?? $payment->amount->sen,
            'currency' => 'MYR',
            'method' => 'fakepay',
            ...$data,
        ],
    ], JSON_THROW_ON_ERROR);
}

/** Posts a webhook to the shop signed the way the gateway signs it. */
function sendWebhook(string $body, ?int $timestamp = null, string $secret = 'test-webhook-secret', ?string $signature = null, string $provider = 'fake')
{
    $signature ??= (new WebhookSigner)->sign($body, $secret, $timestamp ?? time());

    return test()->call('POST', "/api/webhooks/{$provider}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FAKEPAY_SIGNATURE' => $signature,
    ], $body);
}

/** The gateway tells the shop the payment succeeded. */
function gatewayPays(Payment $payment, ?string $eventId = null)
{
    return sendWebhook(webhookBody($payment, 'payment.succeeded', null, $eventId));
}

function inventoryOf(Product|int $product): Inventory
{
    return Inventory::where('product_id', $product instanceof Product ? $product->id : $product)->firstOrFail();
}

/*
| Shared helpers for fulfilment and refund tests.
*/

/** A user with one role: 'customer', 'staff' (fulfilment) or 'admin' (fulfilment and refunds). */
function withRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** An order that has really been paid: placed through checkout, paid on the gateway, confirmed by its webhook. */
function paidOrder(?User $user = null, ?array $lines = null): Order
{
    $order = unpaidOrder($user, $lines);
    payFor($order)->assertCreated();
    // The customer pays on the payment company's page: that is the company's own record of it...
    FakeGatewayPayment::where('reference', paymentOf($order)->provider_reference)->update(['status' => 'succeeded']);
    // ...and then the company calls the shop (nobody is logged in on a real webhook).
    auth()->forgetGuards();
    gatewayPays(paymentOf($order))->assertOk();

    return $order->fresh();
}

/** Moves a paid order along the staff steps: 'processing', 'packed', 'shipped' or 'delivered'. */
function fulfil(Order $order, string $upTo): Order
{
    $staff = withRole('staff');
    $steps = ['processing', 'packed', 'shipped', 'delivered'];

    foreach (array_slice($steps, 0, array_search($upTo, $steps, true) + 1) as $step) {
        match ($step) {
            'processing' => test()->actingAs($staff)->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'PROCESSING'])->assertOk(),
            'packed' => test()->actingAs($staff)->postJson("/api/admin/orders/{$order->id}/status", ['status' => 'PACKED'])->assertOk(),
            'shipped' => test()->actingAs($staff)->postJson("/api/admin/orders/{$order->id}/shipment", ['carrier' => 'J&T Express', 'tracking_number' => 'JT123456789MY'])->assertOk(),
            'delivered' => test()->actingAs($staff)->postJson("/api/admin/orders/{$order->id}/shipment/delivered")->assertOk(),
        };
    }

    return $order->fresh();
}

/*
| Shared helpers for admin tests (dashboard, reports).
*/

/** A real paid order, then moved to the given UTC moment so reports can be tested on exact dates. */
function paidOrderAt(string $utc, ?array $lines = null): Order
{
    $order = paidOrder(null, $lines);
    $when = CarbonImmutable::parse($utc, 'UTC');

    DB::table('orders')->where('id', $order->id)->update(['created_at' => $when, 'paid_at' => $when]);
    DB::table('payments')->where('order_id', $order->id)->update(['paid_at' => $when]);

    return $order->fresh();
}
