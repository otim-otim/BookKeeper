<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Facades\BookKeeper;
use OtimOtim\BookKeeper\Support\Money;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');

/**
 * Freeze time so dates in tests are predictable.
 */
function travelToDate(string $date): void
{
    Carbon::setTestNow(Carbon::parse($date.' 12:00:00'));
}

/**
 * Import a bundled chart of accounts.
 */
function useChart(string $template = 'standard', string $currency = 'USD'): void
{
    config()->set('bookkeeper.currency', $currency);
    BookKeeper::chart()->import($template);
}

/**
 * Shorthand for a major-unit amount in the base currency.
 */
function money(int|string $amount, ?string $currency = null): Money
{
    return Money::of($amount, $currency);
}

expect()->extend('toEqualMoney', function (int|string|Money $expected) {
    $actual = $this->value;
    $expected = $expected instanceof Money ? $expected : Money::of($expected, $actual->currency());

    expect($actual)->toBeInstanceOf(Money::class);
    expect($actual->amount())->toBe($expected->amount());

    return $this;
});
