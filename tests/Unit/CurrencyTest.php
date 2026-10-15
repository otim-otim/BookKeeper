<?php

use OtimOtim\BookKeeper\Exceptions\InvalidCurrencyException;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Money;

test('every ISO 4217 currency has its minor units', function () {
    expect(Currency::precision('USD'))->toBe(2)
        ->and(Currency::precision('UGX'))->toBe(0)
        ->and(Currency::precision('JPY'))->toBe(0)
        ->and(Currency::precision('KWD'))->toBe(3)
        ->and(Currency::precision('CLF'))->toBe(4)
        ->and(Currency::precision('KES'))->toBe(2)
        ->and(count(Currency::all()))->toBeGreaterThan(150)
        ->and(Currency::name('TZS'))->toBe('Tanzanian Shilling');
});

test('unknown currency codes are rejected', function () {
    Money::of(10, 'UGS');
})->throws(InvalidCurrencyException::class, 'Unknown currency code [UGS]');

test('custom units and overrides come from config', function () {
    config()->set('bookkeeper.currencies', [
        'XBT' => ['decimals' => 8, 'name' => 'Bitcoin'],
        'UGX' => ['cash_increment' => '100'],
    ]);

    expect(Money::of('0.00012345', 'XBT')->minor())->toBe(12345)
        ->and(Currency::name('XBT'))->toBe('Bitcoin')
        ->and(Currency::precision('UGX'))->toBe(0)
        ->and(Currency::cashIncrement('UGX'))->toBe('100');
});

test('amounts round to the smallest cash denomination', function () {
    expect(Money::of('1.03', 'CHF')->roundToCash()->amount())->toBe('1.05')
        ->and(Money::of('1.02', 'CHF')->roundToCash()->amount())->toBe('1.00')
        ->and(Money::of('1020', 'UGX')->roundToCash()->amount())->toBe('1000')
        ->and(Money::of('1030', 'UGX')->roundToCash()->amount())->toBe('1050')
        ->and(Money::of('9.99', 'USD')->roundToCash()->amount())->toBe('9.99');
});

test('money is formatted for a locale', function () {
    $plain = fn (string $s) => str_replace(["\u{a0}", "\u{202f}"], ' ', $s);

    expect($plain(Money::of('1234.5', 'USD')->format('en_US')))->toBe('$1,234.50')
        ->and($plain(Money::of('1234.5', 'EUR')->format('de_DE')))->toBe('1.234,50 €')
        ->and(Money::of('50000', 'UGX')->format('en'))->toContain('50,000')
        ->and(Money::of('50000', 'UGX')->format('en'))->not->toContain('.00');
})->skip(! class_exists(NumberFormatter::class), 'intl extension not installed');
