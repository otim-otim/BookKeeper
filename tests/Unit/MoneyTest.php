<?php

use OtimOtim\BookKeeper\Exceptions\InvalidAmountException;
use OtimOtim\BookKeeper\Support\Money;

test('money is stored as integer minor units', function () {
    $money = Money::of('1500.50', 'USD');

    expect($money->minor())->toBe(150050)
        ->and($money->amount())->toBe('1500.50')
        ->and((string) $money)->toBe('1500.50');
});

test('currencies without minor units use whole numbers', function () {
    expect(Money::of('250000', 'UGX')->minor())->toBe(250000)
        ->and(Money::of(250000, 'UGX')->amount())->toBe('250000');
});

test('amounts with more decimals than the currency allows are rejected', function () {
    Money::of('10.005', 'USD');
})->throws(InvalidAmountException::class);

test('floats are converted without binary rounding drift', function () {
    expect(Money::of(0.1, 'USD')->plus(Money::of(0.2, 'USD'))->amount())->toBe('0.30');
});

test('percentages and multiplication round with the configured mode', function () {
    expect(Money::of('100.00', 'USD')->percent('18')->amount())->toBe('18.00')
        ->and(Money::of('0.05', 'USD')->percent('50')->amount())->toBe('0.03')
        ->and(Money::of('10.00', 'USD')->multipliedBy('0.333')->amount())->toBe('3.33');
});

test('allocation never loses a minor unit', function () {
    $parts = Money::of('100.00', 'USD')->allocate([1, 1, 1]);

    expect(array_map(fn (Money $m) => $m->amount(), $parts))->toBe(['33.34', '33.33', '33.33'])
        ->and(Money::sum($parts, 'USD')->amount())->toBe('100.00');
});

test('currency mismatches are rejected', function () {
    Money::of(1, 'USD')->plus(Money::of(1, 'UGX'));
})->throws(InvalidAmountException::class);

test('conversion between currencies', function () {
    expect(Money::of('100.00', 'USD')->convertTo('UGX', '3700.5')->amount())->toBe('370050');
});

test('money serialises to a decimal string', function () {
    expect(json_encode(['total' => Money::of('12.30', 'USD')]))->toBe('{"total":"12.30"}');
});
