<?php

use App\Support\Money;

it('adds without floating point error', function () {
    // 0.10 + 0.20 is 0.30000000000000004 with floats.
    $total = Money::fromRinggit('0.10')->add(Money::fromRinggit('0.20'));

    expect($total->sen)->toBe(30)->and($total->equals(Money::fromRinggit('0.30')))->toBeTrue();
});

it('parses ringgit strings into sen', function (string $input, int $sen) {
    expect(Money::fromRinggit($input)->sen)->toBe($sen);
})->with([
    ['4602', 460200],
    ['4602.5', 460250],
    ['4602.05', 460205],
    ['-1.99', -199],
]);

it('rejects invalid amounts', function (string $input) {
    Money::fromRinggit($input);
})->with(['abc', '1.234', '', '1,000.00'])->throws(InvalidArgumentException::class);

it('formats as ringgit with thousands separators', function () {
    expect(Money::fromSen(460200)->format())->toBe('RM 4,602.00')
        ->and(Money::fromSen(5)->format())->toBe('RM 0.05')
        ->and(Money::fromSen(-199)->format())->toBe('-RM 1.99');
});

it('multiplies by a quantity and subtracts', function () {
    $line = Money::fromRinggit('699.00')->multiply(2);

    expect($line->sen)->toBe(139800)
        ->and($line->subtract(Money::fromRinggit('0.01'))->sen)->toBe(139799);
});

it('calculates percentages in basis points, rounding half up', function () {
    expect(Money::fromRinggit('1000')->percent(1000)->sen)->toBe(10000) // 10% of RM1000
        ->and(Money::fromSen(1)->percent(5000)->sen)->toBe(1)           // 0.5 sen -> 1
        ->and(Money::fromSen(333)->percent(1000)->sen)->toBe(33);       // 33.3 -> 33
});

it('serializes to json with amount, currency and formatted text', function () {
    expect(json_decode(json_encode(Money::fromSen(460200)), true))->toBe([
        'amount' => 460200,
        'currency' => 'MYR',
        'formatted' => 'RM 4,602.00',
    ]);
});
