<?php

use App\Enums\BuilderSlot;
use App\Enums\OrderStatus;
use App\Enums\RecommenderFailure;
use App\Models\BuildRecommendation;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Reports\Insights;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
});

/** The insights for 1 to 15 October 2026 (or the days given), as staff. */
function insights(string $from = '2026-10-01', string $to = '2026-10-15'): array
{
    return test()->actingAs(withRole('staff'))->getJson("/api/admin/reports/insights?from={$from}&to={$to}")->assertOk()->json('data');
}

/** Moves an order, and its payment attempts, to the given UTC moment. */
function placedAt(Order $order, string $utc): Order
{
    $when = CarbonImmutable::parse($utc, 'UTC');

    DB::table('orders')->where('id', $order->id)->update(['created_at' => $when, 'paid_at' => $order->paid_at ? $when : null]);
    DB::table('payments')->where('order_id', $order->id)->update(['created_at' => $when]);
    DB::table('payments')->where('order_id', $order->id)->whereNotNull('paid_at')->update(['paid_at' => $when]);

    return $order->fresh();
}

/** What happens to an order after it was paid, without going through the whole refund process. */
function nowIs(Order $order, OrderStatus $status): Order
{
    DB::table('orders')->where('id', $order->id)->update(['status' => $status->value]);

    return $order->fresh();
}

/** A build suggestion as RecommendationService would have recorded it. */
function suggested(string $utc, array $attributes = []): BuildRecommendation
{
    return BuildRecommendation::forceCreate([
        'budget' => Money::fromSen(400000),
        'purpose' => 'gaming',
        'priority' => 'balanced',
        'resolution' => '1080p',
        'source' => 'rules',
        'total' => Money::fromSen(390000),
        'selection' => ['cpu' => 1],
        'created_at' => CarbonImmutable::parse($utc, 'UTC'),
        ...$attributes,
    ]);
}

it('is for staff and admins only', function () {
    $query = '?from=2026-10-01&to=2026-10-15';

    $this->getJson('/api/admin/reports/insights'.$query)->assertUnauthorized();
    $this->actingAs(shopper())->getJson('/api/admin/reports/insights'.$query)->assertForbidden();
    $this->actingAs(withRole('staff'))->getJson('/api/admin/reports/insights'.$query)->assertOk();
    $this->actingAs(withRole('admin'))->getJson('/api/admin/reports/insights'.$query)->assertOk();
});

it('checks the dates', function (string $query) {
    $this->actingAs(withRole('staff'))->getJson('/api/admin/reports/insights'.$query)->assertUnprocessable();
})->with([
    'no dates' => [''],
    'wrong format' => ['?from=1/10/2026&to=10/10/2026'],
    'the end before the start' => ['?from=2026-10-10&to=2026-10-01'],
    'longer than a year' => ['?from=2025-01-01&to=2026-10-20'],
]);

it('is all zeros, with no percentages, when nothing happened', function () {
    $data = insights();

    expect($data['period'])->toBe(['from' => '2026-10-01', 'to' => '2026-10-15'])
        ->and($data['customers'])->toBe(['buyers' => 0, 'new' => 0, 'returning' => 0, 'returning_rate' => null, 'orders' => 0, 'orders_per_buyer' => null])
        ->and($data['checkout'])->toMatchArray(['placed' => 0, 'paid' => 0, 'awaiting_payment' => 0, 'cancelled_unpaid' => 0, 'paid_rate' => null, 'with_coupon' => 0])
        ->and($data['checkout']['discount_given']['amount'])->toBe(0)
        ->and($data['payments'])->toBe(['attempts' => 0, 'paid' => 0, 'failed' => 0, 'cancelled' => 0, 'open' => 0, 'success_rate' => null, 'failure_reasons' => [], 'methods' => []])
        ->and($data['pcs'])->toMatchArray(['sold' => 0, 'revenue_share' => null, 'popular_parts' => [], 'pairings' => []])
        ->and($data['pcs']['average_price']['amount'])->toBe(0)
        ->and($data['suggestions'])->toMatchArray(['asked' => 0, 'accepted' => 0, 'acceptance_rate' => null])
        ->and($data['suggestions']['average_budget']['amount'])->toBe(0)
        ->and(array_column($data['suggestions']['by_purpose'], 'asked', 'purpose'))->toBe(['gaming' => 0, 'creation' => 0, 'work' => 0])
        ->and($data['suggestions']['ai'])->toBe(['asked' => 0, 'used' => 0, 'fell_back' => 0, 'reasons' => []]);
});

describe('customers', function () {
    it('tells new buyers from returning ones', function () {
        // Bought last month and again this month: returning.
        $regular = shopper();
        placedAt(paidOrder($regular), '2026-09-10 03:00:00');
        placedAt(paidOrder($regular), '2026-10-05 03:00:00');

        // Bought twice, both this month: new.
        $keen = shopper();
        placedAt(paidOrder($keen), '2026-10-06 03:00:00');
        placedAt(paidOrder($keen), '2026-10-07 03:00:00');

        // The only earlier order was refunded, so this month's is their first real purchase: new.
        $secondTry = shopper();
        nowIs(placedAt(paidOrder($secondTry), '2026-09-12 03:00:00'), OrderStatus::Refunded);
        placedAt(paidOrder($secondTry), '2026-10-08 03:00:00');

        // Paid, then cancelled: not a buyer.
        nowIs(placedAt(paidOrder(), '2026-10-09 03:00:00'), OrderStatus::Cancelled);
        // After the period.
        placedAt(paidOrder(), '2026-10-18 03:00:00');

        expect(insights()['customers'])->toBe([
            'buyers' => 3,
            'new' => 2,
            'returning' => 1,
            'returning_rate' => 33.3,
            'orders' => 4,
            'orders_per_buyer' => 1.33,
        ]);
    });

    it('counts days the way the shop does, in Malaysian time', function () {
        // 4 October, 4:30 pm in UTC is already half past midnight on the 5th in Kuala Lumpur.
        placedAt(paidOrder(), '2026-10-04 16:30:00');

        expect(insights('2026-10-05', '2026-10-05')['customers']['buyers'])->toBe(1)
            ->and(insights('2026-10-04', '2026-10-04')['customers']['buyers'])->toBe(0);
    });
});

describe('checkout', function () {
    it('shows what became of the orders placed in the period', function () {
        $coupon = Coupon::factory()->percent(1000)->create();

        placedAt(paidOrder(), '2026-10-03 03:00:00');
        placedAt(unpaidOrder(), '2026-10-04 03:00:00');

        $givenUp = unpaidOrder();
        $this->actingAs($givenUp->user)->postJson("/api/orders/{$givenUp->id}/cancel")->assertOk();
        placedAt($givenUp, '2026-10-05 03:00:00');

        $withCoupon = unpaidOrder(null, null, ['coupon_code' => $coupon->code]);
        payFor($withCoupon)->assertCreated();
        auth()->forgetGuards();
        gatewayPays(paymentOf($withCoupon))->assertOk();
        placedAt($withCoupon->fresh(), '2026-10-06 03:00:00');

        // Paid, then refunded: the customer did pay, so it still counts as paid here.
        nowIs(placedAt(paidOrder(), '2026-10-07 03:00:00'), OrderStatus::Refunded);
        // After the period.
        placedAt(paidOrder(), '2026-10-18 03:00:00');

        $checkout = insights()['checkout'];

        expect($withCoupon->fresh()->paid_at)->not->toBeNull()
            ->and($checkout)->toMatchArray([
                'placed' => 5,
                'paid' => 3,
                'awaiting_payment' => 1,
                'cancelled_unpaid' => 1,
                'paid_rate' => 60.0,
                'with_coupon' => 1,
            ])
            // 10% off a RM 1,000.00 product.
            ->and($checkout['discount_given']['amount'])->toBe(10000);
    });
});

describe('payments', function () {
    it('counts attempts, not orders, and says why they failed', function () {
        $fail = function (Order $order, array $data = []) {
            payFor($order)->assertCreated();
            auth()->forgetGuards();
            sendWebhook(webhookBody(paymentOf($order), 'payment.failed', data: $data))->assertOk();
        };
        $pay = function (Order $order) {
            payFor($order)->assertCreated();
            auth()->forgetGuards();
            gatewayPays(paymentOf($order))->assertOk();
        };

        // Declined, then paid on the second try: two attempts.
        $retried = unpaidOrder();
        $fail($retried, ['failure_reason' => 'Card declined']);
        $pay($retried);

        $declined = unpaidOrder();
        $fail($declined, ['failure_reason' => 'Card declined']);

        $unexplained = unpaidOrder();
        $fail($unexplained);

        $stillOpen = unpaidOrder();
        payFor($stillOpen)->assertCreated();

        $paid = paidOrder();

        foreach ([$retried, $declined, $unexplained, $stillOpen, $paid] as $order) {
            placedAt($order->fresh(), '2026-10-05 03:00:00');
        }

        // An attempt after the period.
        paidOrder();

        $payments = insights()['payments'];

        expect($payments)->toMatchArray([
            'attempts' => 6,
            'paid' => 2,
            'failed' => 3,
            'cancelled' => 0,
            'open' => 1,
            'success_rate' => 40.0,
            'failure_reasons' => [
                ['reason' => 'Card declined', 'attempts' => 2],
                ['reason' => 'No reason given', 'attempts' => 1],
            ],
        ])
            ->and($payments['methods'])->toHaveCount(1)
            ->and($payments['methods'][0])->toMatchArray(['method' => 'fakepay', 'payments' => 2])
            ->and($payments['methods'][0]['amount']['amount'])->toBe($retried->total->sen + $paid->total->sen);
    });
});

describe('PCs', function () {
    it('separates whole PCs from single items, and shows what goes into them', function () {
        $pc = compatiblePc();
        $card = specPart(BuilderSlot::Gpu, ['length_mm' => 300, 'tdp_watts' => 200, 'recommended_psu_watts' => 650], 150000, ['name' => 'The graphics card']);
        $otherCpu = specPart(BuilderSlot::Cpu, ['socket' => 'AM5', 'tdp_watts' => 105, 'integrated_graphics' => 'Yes', 'cooler_included' => 'Yes'], 120000, ['name' => 'The other processor']);
        $mouse = Product::factory()->priced(10000)->stock(10)->create();

        // Two of the same PC (no graphics card) and three mice, in one order.
        $first = shopper();
        addPc($first, $pc, ['quantity' => 2])->assertCreated();
        placedAt(paidOrder($first, [[$mouse, 3]]), '2026-10-05 03:00:00');

        // The same PC with a graphics card.
        $second = shopper();
        addPc($second, [...$pc, 'gpu' => $card])->assertCreated();
        placedAt(paidOrder($second, []), '2026-10-06 03:00:00');

        // Another processor, with the graphics card.
        $third = shopper();
        addPc($third, [...$pc, 'cpu' => $otherCpu, 'gpu' => $card])->assertCreated();
        placedAt(paidOrder($third, []), '2026-10-07 03:00:00');

        // Not sales: one after the period, one cancelled after it was paid.
        $late = shopper();
        addPc($late, $pc)->assertCreated();
        placedAt(paidOrder($late, []), '2026-10-18 03:00:00');
        $undone = shopper();
        addPc($undone, $pc)->assertCreated();
        nowIs(placedAt(paidOrder($undone, []), '2026-10-08 03:00:00'), OrderStatus::Cancelled);

        $pcs = insights()['pcs'];
        // 800 + 600 + 300 + 250 + 400 + 350 ringgit of parts.
        $base = 270000;

        expect($pcs['sold'])->toBe(4)
            ->and($pcs['revenue']['amount'])->toBe(2 * $base + ($base + 150000) + ($base + 40000 + 150000))
            ->and($pcs['average_price']['amount'])->toBe(intdiv(4 * $base + 340000, 4))
            ->and($pcs['single_items']['units'])->toBe(3)
            ->and($pcs['single_items']['revenue']['amount'])->toBe(30000)
            ->and($pcs['revenue_share'])->toBe(round((4 * $base + 340000) / (4 * $base + 370000) * 100, 1));

        $parts = collect($pcs['popular_parts'])->groupBy('slot');

        expect($parts->keys()->all())->toBe(['cpu', 'motherboard', 'ram', 'gpu', 'storage', 'psu', 'case'])
            // (A whole percentage travels as 75, not 75.0: JSON has one kind of number.)
            ->and($parts['cpu']->map(fn ($p) => [$p['id'], $p['pcs'], $p['share']])->all())->toEqual([[$pc['cpu']->id, 3, 75.0], [$otherCpu->id, 1, 25.0]])
            ->and($parts['gpu']->all())->toEqual([['slot' => 'gpu', 'slot_label' => 'GPU', 'id' => $card->id, 'name' => 'The graphics card', 'pcs' => 2, 'share' => 50.0]])
            ->and($parts['motherboard'][0])->toMatchArray(['id' => $pc['motherboard']->id, 'pcs' => 4, 'share' => 100.0]);

        expect($pcs['pairings'])->toBe([
            ['cpu' => $pc['cpu']->name, 'gpu' => null, 'pcs' => 2],
            ['cpu' => $pc['cpu']->name, 'gpu' => 'The graphics card', 'pcs' => 1],
            ['cpu' => 'The other processor', 'gpu' => 'The graphics card', 'pcs' => 1],
        ]);
    });

    it('lists only the most chosen products of a slot', function () {
        $order = Order::factory()->paid()->create(['paid_at' => CarbonImmutable::parse('2026-10-05 03:00:00', 'UTC')]);

        // Five different processors in five PCs of one order: the one in three PCs first, then the lowest ids.
        $cpus = collect(range(1, 5))->map(fn (int $n) => partFor(BuilderSlot::Cpu, 50000, ['name' => "Processor {$n}"]));

        foreach ($cpus as $index => $cpu) {
            OrderItem::factory()->create([
                'order_id' => $order->id, 'product_id' => $cpu->id, 'slot' => 'cpu',
                'build_group' => "build-{$index}", 'build_name' => 'A PC', 'build_copies' => $index === 4 ? 3 : 1,
                'quantity' => $index === 4 ? 3 : 1,
            ]);
        }

        $parts = insights()['pcs']['popular_parts'];

        expect($parts)->toHaveCount(Insights::PARTS_PER_SLOT)
            ->and(array_column($parts, 'name'))->toBe(['Processor 5', 'Processor 1', 'Processor 2'])
            ->and(array_column($parts, 'pcs'))->toBe([3, 1, 1])
            ->and(insights()['pcs']['sold'])->toBe(7);
    });
});

describe('build suggestions', function () {
    it('shows how many were asked for, what for, and how many were taken', function () {
        // The same kind of reason in different words (another status code, another amount) is counted together.
        $error = ['fallback_reason' => 'The AI service answered with an error (429).', 'fallback_kind' => RecommenderFailure::ServiceError];

        suggested('2026-10-02 03:00:00', ['budget' => Money::fromSen(250000), 'accepted_at' => now()]);
        suggested('2026-10-03 03:00:00', ['source' => 'ai']);
        suggested('2026-10-04 03:00:00', [...$error, 'accepted_at' => now()]);
        suggested('2026-10-05 03:00:00', ['purpose' => 'creation', 'budget' => Money::fromSen(800000), ...$error, 'fallback_reason' => 'The AI service answered with an error (503).']);
        // Exactly RM 3,000 belongs to the band that starts there.
        suggested('2026-10-06 03:00:00', ['purpose' => 'work', 'budget' => Money::fromSen(300000), 'fallback_reason' => 'It costs RM 3,200.00, which is over the budget of RM 3,000.00.', 'fallback_kind' => RecommenderFailure::OverBudget, 'user_id' => User::factory()->create()->id]);
        // After the period.
        suggested('2026-10-18 03:00:00', ['budget' => Money::fromSen(999900), 'source' => 'ai', 'accepted_at' => now()]);

        $suggestions = insights()['suggestions'];

        expect($suggestions)->toMatchArray([
            'asked' => 5,
            'accepted' => 2,
            'acceptance_rate' => 40.0,
            'by_purpose' => [
                ['purpose' => 'gaming', 'label' => 'Gaming', 'asked' => 3, 'accepted' => 2],
                ['purpose' => 'creation', 'label' => 'Video editing and 3D', 'asked' => 1, 'accepted' => 0],
                ['purpose' => 'work', 'label' => 'Office, study and browsing', 'asked' => 1, 'accepted' => 0],
            ],
            'by_budget' => [
                ['label' => 'Under RM 3,000', 'asked' => 1],
                ['label' => 'RM 3,000 to under RM 5,000', 'asked' => 3],
                ['label' => 'RM 5,000 to under RM 8,000', 'asked' => 0],
                ['label' => 'RM 8,000 and above', 'asked' => 1],
            ],
            'ai' => [
                'asked' => 4,
                'used' => 1,
                'fell_back' => 3,
                'reasons' => [
                    ['kind' => 'service_error', 'label' => 'The AI service answered with an error', 'times' => 2],
                    ['kind' => 'over_budget', 'label' => 'Its build cost more than the budget', 'times' => 1],
                ],
            ],
        ])
            // (2,500 + 4,000 + 4,000 + 8,000 + 3,000) / 5
            ->and($suggestions['average_budget']['amount'])->toBe(430000);
    });

    it('counts a reason recorded without a kind as "another reason"', function () {
        suggested('2026-10-04 03:00:00', ['fallback_reason' => 'Something from before kinds were recorded.']);

        expect(insights()['suggestions']['ai'])->toBe([
            'asked' => 1,
            'used' => 0,
            'fell_back' => 1,
            'reasons' => [['kind' => 'other', 'label' => 'Another reason', 'times' => 1]],
        ]);
    });

    it('reads what the AI fallback really records', function () {
        miniCatalog();
        config(['recommender.driver' => 'ai', 'recommender.ai.api_key' => 'sk-test', 'recommender.ai.model' => 'test-model', 'recommender.ai.base_url' => 'https://ai.test/v1']);
        Http::fake(['ai.test/*' => Http::response(['error' => 'rate limit'], 429)]);

        $this->postJson('/api/pc-builder/recommendations', ['budget' => 400000, 'purpose' => 'gaming', 'priority' => 'balanced', 'resolution' => '1080p'])
            ->assertOk()->assertJsonPath('data.source', 'rules');

        expect(insights('2026-10-20', '2026-10-20')['suggestions']['ai'])->toBe([
            'asked' => 1,
            'used' => 0,
            'fell_back' => 1,
            'reasons' => [['kind' => 'service_error', 'label' => 'The AI service answered with an error', 'times' => 1]],
        ]);
    });

    it('reads what the suggestion feature really records', function () {
        miniCatalog();
        $id = $this->postJson('/api/pc-builder/recommendations', ['budget' => 400000, 'purpose' => 'gaming', 'priority' => 'balanced', 'resolution' => '1080p'])->assertOk()->json('data.id');
        $this->postJson("/api/pc-builder/recommendations/{$id}/accept")->assertSuccessful();

        $suggestions = insights('2026-10-20', '2026-10-20')['suggestions'];

        expect($suggestions)->toMatchArray(['asked' => 1, 'accepted' => 1, 'acceptance_rate' => 100.0])
            ->and($suggestions['average_budget']['amount'])->toBe(400000)
            ->and($suggestions['ai']['asked'])->toBe(0);
    });
});
