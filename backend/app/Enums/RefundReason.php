<?php

namespace App\Enums;

enum RefundReason: string
{
    case CustomerRequest = 'customer_request';
    case OrderCancelled = 'order_cancelled';   // staff cancelled a paid order
    case LatePayment = 'late_payment';          // the customer paid after the order was cancelled
    case Goodwill = 'goodwill';                 // any other reason staff decide on

    public function label(): string
    {
        return match ($this) {
            self::CustomerRequest => 'Customer request',
            self::OrderCancelled => 'Order cancelled',
            self::LatePayment => 'Payment received after cancellation',
            self::Goodwill => 'Goodwill',
        };
    }
}
