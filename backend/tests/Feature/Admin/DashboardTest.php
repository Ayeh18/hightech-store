<?php

use App\Enums\BuilderSlot;
use App\Models\FakeGatewayPayment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // 20 October 2026, 20:00 in Kuala Lumpur (12:00 UTC)
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
});

function dashboard(User $as, string $query = '')
{
    return test()->actingAs($as)->getJson('/api/admin/dashboard'.$query);
}

describe('who can see it', function () {
    it('lets staff and admins in, and nobody else', function () {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
        $this->actingAs(shopper())->getJson('/api/admin/dashboard')->assertForbidden();
        dashboard(withRole('staff'))->assertOk();
        dashboard(withRole('admin'))->assertOk();
    });

    it('keeps the payment tables for people who may view payments', function () {
        paidOrder();

        expect(dashboard(withRole('staff'))->json('data.recent_payments'))->toBeNull()
            ->and(dashboard(withRole('staff'))->json('data.failed_payments'))->toBeNull()
            ->and(dashboard(withRole('admin'))->json('data.recent_payments'))->toHaveCount(1);
    });

    it('only accepts 7, 30 or 90 days', function () {
        $admin = withRole('admin');

        dashboard($admin, '?days=7')->assertOk()->assertJsonPath('data.period.days', 7);
        dashboard($admin, '?days=90')->assertOk()->assertJsonCount(90, 'data.sales_by_day');
        dashboard($admin, '?days=5')->assertUnprocessable();
        dashboard($admin)->assertOk()->assertJsonPath('data.period.days', 30)->assertJsonPath('data.period.to', '2026-10-20');
    });
});

describe('the numbers', function () {
    it('add up sales, refunds and orders, by the shop\'s own days', function () {
        // 20 Oct 10:00 MYT, 20 Oct 01:00 MYT (still the 19th in UTC!), and 19 Oct 23:00 MYT.
        $a = paidOrderAt('2026-10-20 02:00:00');
        $b = paidOrderAt('2026-10-19 17:00:00');
        $c = paidOrderAt('2026-10-19 15:00:00');
        unpaidOrder();
        $admin = withRole('admin');
        $this->actingAs($admin)->postJson('/api/admin/payments/'.paymentOf($a)->id.'/refunds', ['amount' => 10000, 'reason' => 'goodwill'])->assertOk();

        $data = dashboard($admin, '?days=7')->assertOk()->json('data');
        $gross = $a->total->sen + $b->total->sen + $c->total->sen;

        expect($data['cards']['gross_sales']['amount'])->toBe($gross)
            ->and($data['cards']['refunded']['amount'])->toBe(10000)
            ->and($data['cards']['total_sales']['amount'])->toBe($gross - 10000)
            ->and($data['cards']['orders'])->toBe(4)
            ->and($data['cards']['orders_paid'])->toBe(3)
            ->and($data['cards']['average_order']['amount'])->toBe(intdiv($gross, 3));

        $byDay = collect($data['sales_by_day'])->keyBy('date');
        expect($byDay['2026-10-19']['gross']['amount'])->toBe($c->total->sen)
            ->and($byDay['2026-10-19']['orders_paid'])->toBe(1)
            ->and($byDay['2026-10-20']['gross']['amount'])->toBe($a->total->sen + $b->total->sen) // $b belongs to the 20th in Malaysia
            ->and($byDay['2026-10-20']['refunded']['amount'])->toBe(10000)
            ->and($byDay['2026-10-20']['net']['amount'])->toBe($a->total->sen + $b->total->sen - 10000)
            ->and($byDay['2026-10-18']['gross']['amount'])->toBe(0)
            ->and(array_keys($byDay->all()))->toHaveCount(7);
    });

    it('does not count money held for a refund as a sale, and counts it once refunded', function () {
        $late = unpaidOrder();
        payFor($late)->assertCreated();
        $this->actingAs($late->user)->postJson("/api/orders/{$late->id}/cancel")->assertOk();
        FakeGatewayPayment::where('reference', paymentOf($late)->provider_reference)->update(['status' => 'succeeded']);
        auth()->forgetGuards();
        gatewayPays(paymentOf($late))->assertJsonPath('status', 'late_payment');
        $admin = withRole('admin');

        expect(dashboard($admin)->json('data.cards.gross_sales.amount'))->toBe(0);

        $this->actingAs($admin)->postJson('/api/admin/payments/'.paymentOf($late)->id.'/refunds', ['amount' => $late->total->sen, 'reason' => 'late_payment'])->assertOk();

        // Now it is a payment that came in and went straight back: gross and refunds cancel out.
        $cards = dashboard($admin)->json('data.cards');
        expect($cards['gross_sales']['amount'])->toBe($late->total->sen)->and($cards['total_sales']['amount'])->toBe(0);
    });

    it('counts what is waiting for payment right now', function () {
        $one = unpaidOrder();
        $two = unpaidOrder();
        paidOrder();

        $cards = dashboard(withRole('staff'))->json('data.cards');

        expect($cards['pending_payments']['count'])->toBe(2)
            ->and($cards['pending_payments']['amount']['amount'])->toBe($one->total->sen + $two->total->sen);
    });

    it('counts products, low stock and customers', function () {
        Product::factory()->stock(50)->create();           // plenty
        Product::factory()->stock(2)->create();            // at its threshold (default 5): low
        Product::factory()->stock(0)->create();            // sold out: counts as low too
        Product::factory()->stock(1)->create(['status' => 'draft']); // not on sale: ignored
        shopper();
        shopper();
        $card = dashboard(withRole('admin'))->json('data.cards');

        expect($card['products'])->toBe(['active' => 3, 'total' => 4])
            ->and($card['low_stock'])->toBe(2)
            ->and($card['customers']['total'])->toBe(2)
            ->and($card['customers']['new'])->toBe(2);
    })->skip(fn () => ! method_exists(Product::factory(), 'stock'), 'needs the stock() factory state');

    it('lists the products closest to running out, lowest first', function () {
        $low = Product::factory()->stock(1)->create(['name' => 'Almost gone']);
        $out = Product::factory()->stock(0)->create(['name' => 'Sold out']);
        Product::factory()->stock(100)->create();

        $rows = dashboard(withRole('staff'))->json('data.low_stock_products');

        expect(collect($rows)->pluck('name')->all())->toBe(['Sold out', 'Almost gone'])
            ->and($rows[1]['available'])->toBe(1);
    });

    it('lists what sold best, leaving out orders that were cancelled or refunded', function () {
        $big = Product::factory()->priced(300000)->stock(10)->create(['name' => 'Big seller']);
        $small = Product::factory()->priced(100000)->stock(10)->create(['name' => 'Small seller']);
        $gone = Product::factory()->priced(900000)->stock(10)->create(['name' => 'Refunded product']);
        paidOrderAt('2026-10-19 05:00:00', [[$big, 2]]);
        paidOrderAt('2026-10-19 06:00:00', [[$small, 1]]);
        $refunded = paidOrderAt('2026-10-19 07:00:00', [[$gone, 1]]);
        $admin = withRole('admin');
        $this->actingAs($admin)->postJson('/api/admin/orders/'.$refunded->id.'/cancel-and-refund')->assertOk();

        $top = dashboard($admin)->json('data.top_products');

        expect(collect($top)->pluck('name')->all())->toBe(['Big seller', 'Small seller'])
            ->and($top[0]['units'])->toBe(2)
            ->and($top[0]['revenue']['amount'])->toBe(600000)
            ->and($top[1]['revenue']['amount'])->toBe(100000);
    });

    it('splits item sales by category', function () {
        $cpu = partFor(BuilderSlot::Cpu, 200000);
        $gpu = partFor(BuilderSlot::Gpu, 500000);
        paidOrderAt('2026-10-19 05:00:00', [[$cpu, 1], [$gpu, 1]]);

        $rows = dashboard(withRole('staff'))->json('data.revenue_by_category');

        expect(collect($rows)->mapWithKeys(fn ($r) => [$r['name'] => $r['revenue']['amount']])->all())->toBe(['Gpu' => 500000, 'Cpu' => 200000]);
    });

    it('shows recent orders and failed payments', function () {
        $order = unpaidOrder();
        payFor($order)->assertCreated();
        auth()->forgetGuards();
        sendWebhook(webhookBody(paymentOf($order), 'payment.failed', data: ['failure_reason' => 'Declined']))->assertOk();
        $admin = withRole('admin');

        $data = dashboard($admin)->json('data');

        expect($data['recent_orders'][0]['order_number'])->toBe($order->order_number)
            ->and($data['recent_orders'][0]['customer']['email'])->toBe($order->user->email)
            ->and($data['failed_payments'])->toHaveCount(1)
            ->and($data['failed_payments'][0]['failure_reason'])->toBe('Declined')
            ->and($data['failed_payments'][0]['order']['order_number'])->toBe($order->order_number);
    });

    it('does not run more queries for more data (no N+1)', function () {
        paidOrder();
        $admin = withRole('admin');
        dashboard($admin)->assertOk(); // warm-up

        DB::enableQueryLog();
        dashboard($admin)->assertOk();
        $withOne = count(DB::getQueryLog());

        foreach (range(1, 4) as $_) {
            paidOrder();
        }
        DB::flushQueryLog();
        dashboard($admin)->assertOk();

        expect(count(DB::getQueryLog()))->toBe($withOne);
    });
});
