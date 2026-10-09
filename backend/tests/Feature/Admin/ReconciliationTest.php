<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\DeliverFakeWebhook;
use App\Models\AuditLog;
use App\Models\FakeGatewayPayment;
use App\Models\FakeGatewayRefund;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\Gateways\FakeGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** A short period around today (the API refuses more than 31 days). */
function period(): string
{
    return 'from='.now()->subDays(3)->toDateString().'&to='.now()->addDay()->toDateString();
}

function reconcile(?string $query = null)
{
    return test()->actingAs(withRole('admin'))->getJson('/api/admin/reconciliation?'.($query ?? 'from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString()));
}

/** The one row for this order's payment. */
function rowFor(Order $order, ?string $query = null): array
{
    return collect(reconcile($query)->assertOk()->json('data.rows'))->firstWhere('order.id', $order->id);
}

/** The customer pays on the payment company's page, but its webhook never reaches the shop. */
function paidAtGatewayWithoutWebhook(Order $order, string $outcome = 'succeeded', ?int $amount = null): void
{
    Queue::fake();
    test()->postJson('/api/fake-gateway/payments/'.paymentOf($order)->provider_reference.'/complete', array_filter(['outcome' => $outcome, 'amount' => $amount]))->assertOk();
    Queue::assertPushed(DeliverFakeWebhook::class);
}

function gatewayRow(string $reference): FakeGatewayPayment
{
    return FakeGatewayPayment::findOrFail($reference);
}

describe('who can reconcile', function () {
    it('is for admins only (payments.view)', function () {
        $order = paidOrder();
        $payment = paymentOf($order);

        $this->getJson('/api/admin/reconciliation?'.period())->assertUnauthorized();
        foreach ([$order->user, withRole('staff')] as $user) {
            $this->actingAs($user)->getJson('/api/admin/reconciliation?'.period())->assertForbidden();
            $this->actingAs($user)->postJson("/api/admin/payments/{$payment->id}/sync")->assertForbidden();
        }
        $this->actingAs(withRole('admin'))->getJson('/api/admin/reconciliation?'.period())->assertOk();
    });

    it('needs a sensible period', function (string $query) {
        $this->actingAs(withRole('admin'))->getJson('/api/admin/reconciliation'.$query)->assertUnprocessable();
    })->with([
        'no dates' => [''],
        'bad format' => ['?from=1/1/2026&to=2/1/2026'],
        'the end before the start' => ['?from=2026-10-10&to=2026-10-01'],
        'longer than a month' => ['?from=2026-01-01&to=2026-03-01'],
    ]);
});

describe('when everything agrees', function () {
    it('says MATCHED, with both sides of the books side by side', function () {
        $order = paidOrder();

        $row = rowFor($order);

        expect($row['result'])->toBe('MATCHED')
            ->and($row['issues'])->toBe([])
            ->and($row['can_sync'])->toBeFalse()
            ->and($row['internal']['amount']['amount'])->toBe($order->total->sen)
            ->and($row['internal']['status']['value'])->toBe('PAID')
            ->and($row['gateway']['amount']['amount'])->toBe($order->total->sen)
            ->and($row['gateway']['state'])->toBe('succeeded')
            ->and($row['order']['order_number'])->toBe($order->order_number);
    });

    it('adds up what the payment company received against what we recorded', function () {
        paidOrder();
        paidOrder();
        $unpaid = unpaidOrder();
        payFor($unpaid)->assertCreated(); // waiting: neither side counts it as received

        $summary = reconcile()->assertOk()->json('data.summary');

        expect($summary['checked'])->toBe(3)->and($summary['matched'])->toBe(3)->and($summary['errors'])->toBe(0)
            ->and($summary['gateway_received']['amount'])->toBe($summary['internal_recorded']['amount'])
            ->and($summary['difference']['amount'])->toBe(0);
    });

    it('treats a still-waiting payment as matched, and a refund recorded on both sides as matched', function () {
        $waiting = unpaidOrder();
        payFor($waiting)->assertCreated();
        $paid = paidOrder();
        $this->actingAs(withRole('admin'))->postJson('/api/admin/payments/'.paymentOf($paid)->id.'/refunds', ['amount' => 5000, 'reason' => 'goodwill'])->assertOk();

        expect(rowFor($waiting)['result'])->toBe('MATCHED')
            ->and(rowFor($paid)['result'])->toBe('MATCHED')
            ->and(rowFor($paid)['gateway']['refunded']['amount'])->toBe(5000)
            ->and(rowFor($paid)['internal']['refunded']['amount'])->toBe(5000);
    });
});

describe('the example in the brief: RM 4,602 against RM 4,502', function () {
    it('reports a RECONCILIATION ERROR that says exactly what differs', function () {
        $order = paidOrder();
        // The gateway received RM 4,602.00 while this payment (and order) is RM 4,502.00.
        DB::table('orders')->where('id', $order->id)->update(['subtotal' => 450200 - 1500, 'total' => 450200]);
        DB::table('payments')->where('order_id', $order->id)->update(['amount' => 450200]);
        DB::table('fake_gateway_payments')->where('reference', paymentOf($order)->provider_reference)->update(['amount' => 460200, 'paid_amount' => 460200]);

        $row = rowFor($order);

        expect($row['result'])->toBe('ERROR')
            ->and($row['gateway']['amount']['formatted'])->toBe('RM 4,602.00')
            ->and($row['internal']['amount']['formatted'])->toBe('RM 4,502.00')
            ->and(collect($row['issues'])->pluck('code')->all())->toBe(['amount_differs'])
            ->and($row['issues'][0]['message'])->toBe('The payment company received RM 4,602.00, but this payment is for RM 4,502.00.')
            ->and($row['can_sync'])->toBeFalse(); // a wrong amount is never "fixed" automatically

        $summary = reconcile()->json('data.summary');
        expect($summary['errors'])->toBe(1)->and($summary['difference']['amount'])->toBe(10000);
    });

    it('catches a customer who really pays the wrong amount, end to end, and keeps the order unpaid', function () {
        // The payment company delivers its webhook straight into the shop, signature and all.
        Http::fake(function ($request) {
            $response = $this->call('POST', '/api/webhooks/fake', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_FAKEPAY_SIGNATURE' => $request->header('X-FakePay-Signature')[0],
            ], $request->body());

            return Http::response($response->json(), $response->status());
        });
        $order = unpaidOrder();
        payFor($order)->assertCreated();
        $short = $order->total->sen - 10000;

        $this->postJson('/api/fake-gateway/payments/'.paymentOf($order)->provider_reference.'/complete', ['outcome' => 'succeeded', 'amount' => $short])->assertOk();

        // The shop refused to treat it as paid...
        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)->and(paymentOf($order)->status)->toBe(PaymentStatus::Pending);
        // ...and reconciliation shows the money sitting at the gateway.
        $row = rowFor($order);
        expect($row['result'])->toBe('ERROR')
            ->and(collect($row['issues'])->pluck('code')->all())->toBe(['amount_differs', 'gateway_says_paid'])
            ->and($row['gateway']['amount']['amount'])->toBe($short)
            ->and($row['can_sync'])->toBeFalse();

        // Asking again cannot help: the amount is still wrong. It stays for a person.
        $this->actingAs(withRole('admin'))->postJson('/api/admin/payments/'.paymentOf($order)->id.'/sync')->assertUnprocessable();
        expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('only lets the payment page pay a different amount in a successful payment', function () {
        $order = unpaidOrder();
        payFor($order)->assertCreated();
        $url = '/api/fake-gateway/payments/'.paymentOf($order)->provider_reference.'/complete';

        $this->postJson($url, ['outcome' => 'failed', 'amount' => 100])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson($url, ['outcome' => 'succeeded', 'amount' => 0])->assertUnprocessable();
        $this->postJson($url, ['outcome' => 'succeeded', 'amount' => 'ten'])->assertUnprocessable();
    });
});

describe('a missed webhook', function () {
    it('shows the gateway saying paid while we say waiting, and one click puts it right', function () {
        $order = unpaidOrder();
        payFor($order)->assertCreated();
        paidAtGatewayWithoutWebhook($order);

        $row = rowFor($order);
        expect($row['result'])->toBe('ERROR')
            ->and($row['issues'][0]['code'])->toBe('gateway_says_paid')
            ->and($row['issues'][0]['message'])->toContain('A payment notice was probably missed')
            ->and($row['can_sync'])->toBeTrue();

        $admin = withRole('admin');
        $synced = $this->actingAs($admin)->postJson('/api/admin/payments/'.paymentOf($order)->id.'/sync')->assertOk()->json('data');

        expect($synced['result'])->toBe('MATCHED')
            ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
            ->and(paymentOf($order)->status)->toBe(PaymentStatus::Paid);
        expect(AuditLog::where('action', 'payment.synced')->where('subject_id', $order->id)->sole()->user_id)->toBe($admin->id);
        // Paid exactly once: stock was sold once.
        expect(inventoryOf($order->items->first()->product_id)->stock)->toBe(9);
    });

    it('is safe to sync twice: the second time there is nothing left to fix', function () {
        $order = unpaidOrder();
        payFor($order)->assertCreated();
        paidAtGatewayWithoutWebhook($order);
        $admin = withRole('admin');
        $url = '/api/admin/payments/'.paymentOf($order)->id.'/sync';

        $this->actingAs($admin)->postJson($url)->assertOk();
        $this->actingAs($admin)->postJson($url)->assertUnprocessable();

        expect(inventoryOf($order->items->first()->product_id)->stock)->toBe(9);
    });

    it('also catches a failure or cancellation we never heard about', function (string $outcome, string $expected) {
        $order = unpaidOrder();
        payFor($order)->assertCreated();
        paidAtGatewayWithoutWebhook($order, $outcome);

        $row = rowFor($order);
        expect($row['issues'][0]['code'])->toBe('gateway_says_closed')->and($row['issues'][0]['message'])->toContain($expected)->and($row['can_sync'])->toBeTrue();

        $this->actingAs(withRole('admin'))->postJson('/api/admin/payments/'.paymentOf($order)->id.'/sync')->assertOk()->assertJsonPath('data.result', 'MATCHED');
    })->with([['failed', 'failed'], ['cancelled', 'was cancelled']]);

    it('finds a payment made after we had cancelled the order, and sends it down the late-payment path', function () {
        $order = unpaidOrder();
        payFor($order)->assertCreated();
        $this->actingAs($order->user)->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        paidAtGatewayWithoutWebhook($order);

        $row = rowFor($order);
        expect($row['internal']['status']['value'])->toBe('CANCELLED')->and($row['issues'][0]['code'])->toBe('gateway_says_paid')->and($row['can_sync'])->toBeTrue();

        $this->actingAs(withRole('admin'))->postJson('/api/admin/payments/'.paymentOf($order)->id.'/sync')->assertOk();

        // The order is not revived; the money is flagged for a refund, as for any late payment.
        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)->and(paymentOf($order)->requires_refund)->toBeTrue();
    });
});

describe('real disagreements that need a person', function () {
    it('we say paid, the gateway says it was not', function () {
        $order = paidOrder();
        DB::table('fake_gateway_payments')->where('reference', paymentOf($order)->provider_reference)->update(['status' => 'pending']);

        $row = rowFor($order);

        expect($row['result'])->toBe('ERROR')
            ->and($row['issues'][0]['code'])->toBe('we_say_paid')
            ->and($row['issues'][0]['message'])->toBe('We recorded this payment as paid, but the payment company says it is not paid yet.')
            ->and($row['can_sync'])->toBeFalse();
        $this->actingAs(withRole('admin'))->postJson('/api/admin/payments/'.paymentOf($order)->id.'/sync')->assertUnprocessable();
        expect($order->fresh()->status)->toBe(OrderStatus::Paid); // nothing "fixed" behind anyone's back
    });

    it('a refund that exists on one side only', function () {
        $order = paidOrder();
        FakeGatewayRefund::create(['reference' => 'fakerefund_x', 'payment_reference' => paymentOf($order)->provider_reference, 'idempotency_key' => 'k', 'amount' => 7000, 'status' => 'succeeded']);

        $row = rowFor($order);

        expect($row['issues'][0]['code'])->toBe('refund_differs')
            ->and($row['issues'][0]['message'])->toBe('The payment company shows RM 70.00 refunded, but we show RM 0.00.');
    });

    it('a payment the gateway has never heard of', function () {
        $order = paidOrder();
        FakeGatewayPayment::where('reference', paymentOf($order)->provider_reference)->delete();

        $row = rowFor($order);

        expect($row['result'])->toBe('ERROR')->and($row['gateway'])->toBeNull()->and($row['issues'][0]['code'])->toBe('no_gateway_record');
    });

    it('a payment whose amount no longer matches its own order', function () {
        $order = paidOrder();
        DB::table('payments')->where('order_id', $order->id)->update(['amount' => $order->total->sen - 100]);

        $codes = collect(rowFor($order)['issues'])->pluck('code')->all();

        expect($codes)->toContain('amount_differs', 'order_total_differs');
    });

    it('reports a gateway that cannot be reached instead of failing', function () {
        $order = paidOrder();
        $this->mock(FakeGateway::class, fn ($mock) => $mock->shouldReceive('fetchTransaction')->andThrow(new RuntimeException('timeout')));

        $row = rowFor($order);

        expect($row['result'])->toBe('ERROR')->and($row['issues'][0]['code'])->toBe('gateway_unreachable')->and($row['gateway'])->toBeNull();
    });
});

describe('which payments are checked', function () {
    it('lists the disagreements first', function () {
        $good = paidOrder();
        $bad = paidOrder();
        DB::table('fake_gateway_payments')->where('reference', paymentOf($bad)->provider_reference)->update(['status' => 'failed']);

        $rows = reconcile()->json('data.rows');

        expect(collect($rows)->pluck('order.id')->all())->toBe([$bad->id, $good->id]);
    });

    it('leaves out payments that never reached a gateway', function () {
        $order = unpaidOrder();
        Payment::create(['order_id' => $order->id, 'provider' => 'fake', 'status' => PaymentStatus::Failed, 'amount' => $order->total, 'currency' => 'MYR']); // never got a payment page

        expect(reconcile()->json('data.summary.checked'))->toBe(0);
    });

    it('uses the shop\'s own days: a payment at 01:00 Malaysian time is in that day, though it is the day before in UTC', function () {
        $order = paidOrder();
        DB::table('payments')->where('order_id', $order->id)->update(['created_at' => '2026-06-14 17:00:00']); // 15 June, 01:00 in Kuala Lumpur

        expect(reconcile('from=2026-06-15&to=2026-06-15')->json('data.summary.checked'))->toBe(1)
            ->and(reconcile('from=2026-06-14&to=2026-06-14')->json('data.summary.checked'))->toBe(0);
    });

    it('refuses a period with too many payments for one check', function () {
        $order = paidOrder();
        $rows = collect(range(1, 501))->map(fn ($n) => [
            'id' => 'bulk'.str_pad((string) $n, 22, '0', STR_PAD_LEFT), 'order_id' => $order->id, 'provider' => 'fake', 'provider_reference' => "bulk_{$n}",
            'status' => 'FAILED', 'amount' => 100, 'currency' => 'MYR', 'created_at' => now(), 'updated_at' => now(),
        ])->all();
        DB::table('payments')->insert($rows);

        reconcile()->assertUnprocessable()->assertJsonValidationErrors('to');
    });

    it('writes the run into the audit log, with what it found', function () {
        paidOrder();
        $bad = paidOrder();
        DB::table('fake_gateway_payments')->where('reference', paymentOf($bad)->provider_reference)->update(['status' => 'failed']);
        $admin = withRole('admin');

        $this->actingAs($admin)->getJson('/api/admin/reconciliation?'.period())->assertOk();

        $log = AuditLog::where('action', 'reconciliation.run')->sole();
        expect($log->user_id)->toBe($admin->id)->and($log->new_values['checked'])->toBe(2)->and($log->new_values['errors'])->toBe(1);
    });

    it('does not run more queries for more payments than one per check (no hidden N+1)', function () {
        paidOrder();
        $this->actingAs(withRole('admin'))->getJson('/api/admin/reconciliation?'.period())->assertOk();

        DB::enableQueryLog();
        reconcile(period())->assertOk();
        $one = count(DB::getQueryLog());
        foreach (range(1, 3) as $_) {
            paidOrder();
        }
        DB::flushQueryLog();
        reconcile(period())->assertOk();
        $four = count(DB::getQueryLog());

        // Each payment costs the gateway lookups (a fixed number of queries), nothing about its order or customer.
        $perPayment = ($four - $one) / 3;
        expect($perPayment)->toBeLessThanOrEqual(3);
    });
});
