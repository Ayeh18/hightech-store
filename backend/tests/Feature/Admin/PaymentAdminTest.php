<?php

describe('the payments list', function () {
    it('is for admins only', function () {
        $order = paidOrder();

        $this->getJson('/api/admin/payments')->assertUnauthorized();
        $this->actingAs($order->user)->getJson('/api/admin/payments')->assertForbidden();
        $this->actingAs(withRole('staff'))->getJson('/api/admin/payments')->assertForbidden();
        $this->actingAs(withRole('staff'))->getJson('/api/admin/payments/'.paymentOf($order)->id)->assertForbidden();
        $this->actingAs(withRole('admin'))->getJson('/api/admin/payments')->assertOk();
    });

    it('shows each payment with its order and customer, newest first', function () {
        $first = paidOrder();
        $second = unpaidOrder();
        payFor($second)->assertCreated();

        $rows = $this->actingAs(withRole('admin'))->getJson('/api/admin/payments')->assertOk()->assertJsonCount(2, 'data')->json('data');

        expect($rows[0]['order']['order_number'])->toBe($second->order_number)
            ->and($rows[0]['status']['value'])->toBe('PENDING')
            ->and($rows[1]['order']['customer']['email'])->toBe($first->user->email)
            ->and($rows[1]['amount']['amount'])->toBe($first->total->sen)
            ->and($rows[1]['provider_reference'])->toStartWith('fakepay_');
    });

    it('filters by status and by "needs a refund", and searches by order or customer', function () {
        $paid = paidOrder();
        $pending = unpaidOrder();
        payFor($pending)->assertCreated();
        $admin = withRole('admin');

        $this->actingAs($admin)->getJson('/api/admin/payments?status=PAID')->assertJsonCount(1, 'data')->assertJsonPath('data.0.order.order_number', $paid->order_number);
        $this->actingAs($admin)->getJson('/api/admin/payments?status=NONSENSE')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/admin/payments?requires_refund=1')->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/admin/payments?q='.urlencode($pending->order_number))->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/admin/payments?q='.urlencode($paid->user->email))->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/admin/payments?q=%25')->assertJsonCount(0, 'data');
    });

    it('does not run more queries for more payments (no N+1)', function () {
        paidOrder();
        $admin = withRole('admin');
        $this->actingAs($admin)->getJson('/api/admin/payments')->assertOk(); // warm-up

        DB::enableQueryLog();
        $this->actingAs($admin)->getJson('/api/admin/payments')->assertOk();
        $withOne = count(DB::getQueryLog());
        foreach (range(1, 3) as $_) {
            paidOrder();
        }
        DB::flushQueryLog();
        $this->actingAs($admin)->getJson('/api/admin/payments')->assertOk()->assertJsonCount(4, 'data');

        expect(count(DB::getQueryLog()))->toBe($withOne);
    });
});

describe('one payment', function () {
    it('shows its whole diary and its refunds, but not the raw webhook data', function () {
        $order = paidOrder();
        $payment = paymentOf($order);
        $admin = withRole('admin');
        $this->actingAs($admin)->postJson("/api/admin/payments/{$payment->id}/refunds", ['amount' => 5000, 'reason' => 'goodwill', 'note' => 'Sorry'])->assertOk();

        $response = $this->actingAs($admin)->getJson("/api/admin/payments/{$payment->id}")->assertOk();
        $data = $response->json('data');

        expect(collect($data['transactions'])->pluck('type')->all())->toBe(['INITIATED', 'SUCCEEDED'])
            ->and($data['transactions'][1]['status_before'])->toBe('PENDING')
            ->and($data['transactions'][1]['status_after'])->toBe('PAID')
            ->and($data['transactions'][1]['amount']['amount'])->toBe($order->total->sen)
            ->and($data['refunds'])->toHaveCount(1)
            ->and($data['refunds'][0]['amount']['amount'])->toBe(5000)
            ->and($data['refunded_amount']['amount'])->toBe(5000)
            ->and($data['refundable']['amount'])->toBe($order->total->sen - 5000);
        expect(json_encode($data))->not->toContain('payload');
    });

    it('is not mistaken for the "needs a refund" list', function () {
        paidOrder();
        $admin = withRole('admin');

        $this->actingAs($admin)->getJson('/api/admin/payments/needing-refund')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/admin/payments/does-not-exist')->assertNotFound();
    });
});
