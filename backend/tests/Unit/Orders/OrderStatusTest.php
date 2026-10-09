<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;

it('follows the normal lifecycle forwards', function () {
    $path = [OrderStatus::PendingPayment, OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Packed, OrderStatus::Shipped, OrderStatus::Delivered];

    foreach (array_slice($path, 0, -1) as $i => $from) {
        expect($from->canMoveTo($path[$i + 1]))->toBeTrue("{$from->value} should lead to {$path[$i + 1]->value}");
    }
});

it('refuses impossible moves', function (OrderStatus $from, OrderStatus $to) {
    expect($from->canMoveTo($to))->toBeFalse();
})->with([
    'cannot ship before it is paid' => [OrderStatus::PendingPayment, OrderStatus::Shipped],
    'cannot deliver before shipping' => [OrderStatus::Paid, OrderStatus::Delivered],
    'cannot go backwards after shipping' => [OrderStatus::Shipped, OrderStatus::Paid],
    'cannot cancel after delivery' => [OrderStatus::Delivered, OrderStatus::Cancelled],
    'cancelled is final' => [OrderStatus::Cancelled, OrderStatus::Paid],
    'refunded is final' => [OrderStatus::Refunded, OrderStatus::Delivered],
    'cannot refund what was never paid' => [OrderStatus::PendingPayment, OrderStatus::Refunded],
]);

it('treats cancelled and refunded as final', function () {
    expect(OrderStatus::Cancelled->nextStatuses())->toBe([])->and(OrderStatus::Refunded->nextStatuses())->toBe([]);
});

it('only lets the customer cancel while nothing has been paid', function () {
    expect(OrderStatus::PendingPayment->isCancellableByCustomer())->toBeTrue();

    foreach (array_filter(OrderStatus::cases(), fn ($s) => $s !== OrderStatus::PendingPayment) as $status) {
        expect($status->isCancellableByCustomer())->toBeFalse("{$status->value} must not be customer-cancellable");
    }
});

it('moves an order only along allowed paths and throws otherwise', function () {
    $order = new Order(['status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending]);
    $order->status = OrderStatus::PendingPayment;

    $order->moveTo(OrderStatus::Paid);
    expect($order->status)->toBe(OrderStatus::Paid);

    expect(fn () => $order->moveTo(OrderStatus::PendingPayment))->toThrow(LogicException::class, 'An order cannot go from PAID to PENDING_PAYMENT.');
    expect($order->status)->toBe(OrderStatus::Paid);
});

it('has a readable label for every status and every payment status', function () {
    foreach (OrderStatus::cases() as $status) {
        expect($status->label())->not->toBe('')->and($status->label())->not->toContain('_');
    }
    foreach (PaymentStatus::cases() as $status) {
        expect($status->label())->not->toBe('');
    }
});
