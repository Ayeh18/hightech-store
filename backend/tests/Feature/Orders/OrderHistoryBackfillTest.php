<?php

use App\Models\Order;
use App\Models\OrderStatusHistory;

it('gives orders that predate the status history a baseline timeline', function () {
    $paid = paidOrder();
    $unpaid = unpaidOrder();
    OrderStatusHistory::query()->delete(); // as if they had been placed before the history existed

    (require database_path('migrations/2026_10_14_100200_backfill_order_status_history.php'))->up();

    $timeline = fn (Order $o) => OrderStatusHistory::where('order_id', $o->id)->orderBy('id')->get()->map(fn ($h) => [$h->from_status?->value, $h->to_status->value])->all();
    expect($timeline($paid))->toBe([[null, 'PENDING_PAYMENT'], ['PENDING_PAYMENT', 'PAID']])
        ->and($timeline($unpaid))->toBe([[null, 'PENDING_PAYMENT']]);
});

it('does not add anything twice, or touch orders that already have a history', function () {
    $order = paidOrder();
    $before = OrderStatusHistory::where('order_id', $order->id)->count();

    (require database_path('migrations/2026_10_14_100200_backfill_order_status_history.php'))->up();
    (require database_path('migrations/2026_10_14_100200_backfill_order_status_history.php'))->up();

    expect(OrderStatusHistory::where('order_id', $order->id)->count())->toBe($before);
});
