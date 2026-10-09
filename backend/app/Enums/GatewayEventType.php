<?php

namespace App\Enums;

/** What a payment gateway can tell us about a payment. Each real gateway's own event names are translated into these. */
enum GatewayEventType: string
{
    case PaymentProcessing = 'payment.processing';
    case PaymentSucceeded = 'payment.succeeded';
    case PaymentFailed = 'payment.failed';
    case PaymentCancelled = 'payment.cancelled';
}
