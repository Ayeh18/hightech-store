<?php

namespace App\Services\Payments;

use App\Enums\GatewayPaymentState;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Do our books and the payment company's books agree?
 *
 * For each payment we ask the company for ITS record (what it received, what state the payment is in, what it refunded) and
 * compare it with ours: the payment, and the order it belongs to. Every disagreement is listed in words. This is the safety
 * net behind everything else: a missed webhook, a customer who paid a different amount, a refund recorded on one side only.
 *
 * Checking changes nothing. Where the cause is a missed message, `sync` applies the gateway's answer through the same
 * idempotent PaymentProcessor a webhook uses. Everything else needs a person, and is never "fixed" automatically.
 */
class ReconciliationService
{
    /** More than this in one period is refused: each payment is one question to the payment company. */
    public const MAX_PAYMENTS = 500;

    public const MAX_DAYS = 31;

    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly PaymentReconciler $reconciler,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Checks every payment that was sent to a gateway in the period (the shop's days).
     *
     * @return array{results: array<int, ReconciliationResult>, summary: array<string, mixed>}
     *
     * @throws ValidationException when there are too many payments to check at once
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to, User $actor): array
    {
        $timezone = (string) config('shop.timezone');
        $query = Payment::query()
            ->with('order.user')
            ->whereNotNull('provider_reference')
            ->whereBetween('created_at', [$from->setTimezone($timezone)->startOfDay()->utc(), $to->setTimezone($timezone)->endOfDay()->utc()])
            ->orderBy('created_at')->orderBy('id');

        $count = (clone $query)->count();

        if ($count > self::MAX_PAYMENTS) {
            throw ValidationException::withMessages(['to' => "There are {$count} payments in this period; at most ".self::MAX_PAYMENTS.' can be checked at once. Choose a shorter period.']);
        }

        $results = $query->get()->map(fn (Payment $payment) => $this->check($payment))->all();

        $received = collect($results)->filter(fn (ReconciliationResult $r) => $r->gateway?->state === GatewayPaymentState::Succeeded)
            ->reduce(fn (Money $sum, ReconciliationResult $r) => $sum->add($r->gateway->amount), Money::zero());
        $recorded = collect($results)->filter(fn (ReconciliationResult $r) => in_array($r->payment->status, [PaymentStatus::Paid, PaymentStatus::Refunded], true))
            ->reduce(fn (Money $sum, ReconciliationResult $r) => $sum->add($r->payment->amount), Money::zero());
        $errors = count(array_filter($results, fn (ReconciliationResult $r) => ! $r->matched()));

        $summary = [
            'checked' => count($results),
            'matched' => count($results) - $errors,
            'errors' => $errors,
            // Like a bank reconciliation: what the company says it received, against what we recorded as received.
            'gateway_received' => $received,
            'internal_recorded' => $recorded,
            'difference' => $received->subtract($recorded),
        ];

        $this->audit->log('reconciliation.run', $actor, null, null, ['from' => $from->toDateString(), 'to' => $to->toDateString()] + array_intersect_key($summary, array_flip(['checked', 'matched', 'errors'])));

        return ['results' => $results, 'summary' => $summary];
    }

    /** Compares one payment with the gateway's record of it. Changes nothing. */
    public function check(Payment $payment): ReconciliationResult
    {
        $payment->loadMissing('order');

        try {
            $transaction = $this->gateways->driver($payment->provider)->fetchTransaction((string) $payment->provider_reference);
        } catch (Throwable $e) {
            report($e);

            return new ReconciliationResult($payment, null, [new ReconciliationIssue('gateway_unreachable', 'The payment company could not be reached, so this payment could not be checked.')]);
        }

        $issues = [];

        if ($transaction === null) {
            $issues[] = new ReconciliationIssue('no_gateway_record', 'The payment company has no record of this payment.');
        } else {
            array_push($issues, ...$this->compare($payment, $transaction));
        }

        // Our own books must agree with each other too: the payment is for the order's total.
        if (! $payment->amount->equals($payment->order->total)) {
            $issues[] = new ReconciliationIssue('order_total_differs', "This payment is for {$payment->amount->format()}, but order {$payment->order->order_number} totals {$payment->order->total->format()}.");
        }

        return new ReconciliationResult($payment, $transaction, $issues);
    }

    /**
     * Asks the gateway again and applies its answer the safe way (see PaymentReconciler), then checks again.
     *
     * @throws ValidationException when the disagreement is not one a fresh look can fix
     */
    public function sync(Payment $payment, User $actor): ReconciliationResult
    {
        if (! $this->check($payment)->canSync()) {
            throw ValidationException::withMessages(['payment' => 'Asking the payment company again cannot fix this one. It needs a person to look into it.']);
        }

        $outcome = $this->reconciler->reconcileOne($payment);
        $this->audit->log('payment.synced', $actor, $payment->order, null, ['payment_id' => $payment->id, 'outcome' => $outcome?->value]);

        return $this->check($payment->fresh());
    }

    /** @return array<int, ReconciliationIssue> */
    private function compare(Payment $payment, GatewayTransaction $transaction): array
    {
        $issues = [];
        $theirs = $transaction->state;
        $ours = $payment->status;
        $paidHere = in_array($ours, [PaymentStatus::Paid, PaymentStatus::Refunded], true);

        if (! $transaction->amount->equals($payment->amount)) {
            $issues[] = new ReconciliationIssue('amount_differs', $theirs === GatewayPaymentState::Succeeded
                ? "The payment company received {$transaction->amount->format()}, but this payment is for {$payment->amount->format()}."
                : "The payment company has this payment as {$transaction->amount->format()}, but we asked for {$payment->amount->format()}.");
        }

        if ($paidHere && $theirs !== GatewayPaymentState::Succeeded) {
            $issues[] = new ReconciliationIssue('we_say_paid', "We recorded this payment as paid, but the payment company says it is {$theirs->label()}.");
        } elseif (! $paidHere && $theirs === GatewayPaymentState::Succeeded) {
            // A missed message is fixable by asking again. (If the amounts also differ, that issue above is NOT fixable, and a row
            // can only be synced when every one of its issues can: the shop never accepts a wrong amount.)
            $issues[] = new ReconciliationIssue('gateway_says_paid', "The payment company says this was paid, but we have it as ‘{$ours->label()}’. A payment notice was probably missed.", canSync: true);
        } elseif ($payment->isOpen() && in_array($theirs, [GatewayPaymentState::Failed, GatewayPaymentState::Cancelled], true)) {
            $issues[] = new ReconciliationIssue('gateway_says_closed', "The payment company says this payment {$this->closedWords($theirs)}, but we still show it as waiting.", canSync: true);
        }

        if (! $transaction->refunded->equals($payment->refunded_amount)) {
            $issues[] = new ReconciliationIssue('refund_differs', "The payment company shows {$transaction->refunded->format()} refunded, but we show {$payment->refunded_amount->format()}.");
        }

        return $issues;
    }

    private function closedWords(GatewayPaymentState $state): string
    {
        return $state === GatewayPaymentState::Failed ? 'failed' : 'was cancelled';
    }
}
