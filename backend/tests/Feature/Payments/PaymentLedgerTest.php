<?php

use App\Enums\PaymentTransactionType;
use App\Models\PaymentTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Runs a statement the database should refuse. The savepoint keeps the test's own transaction usable afterwards. */
function attempt(Closure $statement): Closure
{
    return fn () => DB::transaction($statement);
}

function aLedgerEntry(): PaymentTransaction
{
    $order = unpaidOrder();
    payFor($order)->assertCreated();

    return PaymentTransaction::where('payment_id', paymentOf($order)->id)->firstOrFail();
}

it('lets new entries be added', function () {
    $entry = aLedgerEntry();

    $second = PaymentTransaction::create(['payment_id' => $entry->payment_id, 'type' => PaymentTransactionType::Ignored, 'note' => 'test']);

    expect($second->exists)->toBeTrue()->and(PaymentTransaction::where('payment_id', $entry->payment_id)->count())->toBe(2);
});

it('refuses to change an entry, even with raw SQL', function () {
    $entry = aLedgerEntry();

    expect(attempt(fn () => DB::update('update payment_transactions set amount = 1 where id = ?', [$entry->id])))
        ->toThrow(QueryException::class, 'append-only');
    expect(attempt(fn () => $entry->update(['note' => 'edited'])))->toThrow(QueryException::class, 'append-only');
});

it('refuses to delete an entry, even with raw SQL', function () {
    $entry = aLedgerEntry();

    expect(attempt(fn () => DB::delete('delete from payment_transactions where id = ?', [$entry->id])))
        ->toThrow(QueryException::class, 'append-only');
    expect(attempt(fn () => $entry->delete()))->toThrow(QueryException::class, 'append-only');
    expect(PaymentTransaction::count())->toBe(1);
});
