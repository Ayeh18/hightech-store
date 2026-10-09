<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Enums\WebhookOutcome;
use App\Models\Payment;
use Throwable;

/**
 * The safety net for lost webhooks. Webhooks can fail to arrive (our server was down, the gateway gave up retrying), and
 * then a customer could have paid while our records still say "unpaid". Every few minutes we ask the gateway directly
 * about payments that are still undecided and feed its answer through the same PaymentProcessor a webhook would use.
 */
class PaymentReconciler
{
    public function __construct(private readonly GatewayManager $gateways, private readonly PaymentProcessor $processor) {}

    /** Returns how many payments the gateway had news about. */
    public function run(): int
    {
        $payments = Payment::query()
            ->whereNotNull('provider_reference')
            ->where(function ($query) {
                $query->where(fn ($open) => $open
                    ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
                    ->where('created_at', '<', now()->subMinutes((int) config('payments.reconcile_after_minutes'))))
                    // Also payments we closed ourselves recently (e.g. the order expired): the customer may have paid anyway.
                    ->orWhere(fn ($closed) => $closed
                        ->whereIn('status', [PaymentStatus::Cancelled->value, PaymentStatus::Failed->value])
                        ->where('updated_at', '>', now()->subHours(2)));
            })
            ->get();

        $changed = 0;

        foreach ($payments as $payment) {
            try {
                $event = $this->gateways->driver($payment->provider)->fetchStatus($payment->provider_reference);

                if ($event !== null && $this->processor->handle($payment->provider, $event, 'reconcile') === WebhookOutcome::Processed) {
                    $changed++;
                }
            } catch (Throwable $e) {
                report($e); // one broken gateway lookup must not stop the others
            }
        }

        return $changed;
    }
}
