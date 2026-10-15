<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\ExchangeRate;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Exchange rates expressed as base-currency units per 1 foreign unit.
 */
class ExchangeRateService
{
    public function set(string $currency, string|int|float $rate, \DateTimeInterface|string|null $date = null): ExchangeRate
    {
        return ExchangeRate::query()->updateOrCreate(
            ['currency' => strtoupper($currency), 'date' => Carbon::parse($date ?? now())->toDateString()],
            ['rate' => (string) $rate],
        );
    }

    /**
     * The most recent rate on or before $date.
     */
    public function rate(string $currency, \DateTimeInterface|string|null $date = null): string
    {
        $currency = strtoupper($currency);

        if ($currency === Currency::base()) {
            return '1';
        }

        $rate = ExchangeRate::query()
            ->where('currency', $currency)
            ->where('date', '<=', Carbon::parse($date ?? now())->toDateString())
            ->orderByDesc('date')
            ->value('rate');

        return $rate !== null
            ? (string) $rate
            : throw new InvalidOperationException("No exchange rate for {$currency} on or before ".Carbon::parse($date ?? now())->toDateString().'.');
    }

    public function toBase(Money $money, \DateTimeInterface|string|null $date = null, ?string $rate = null): Money
    {
        if ($money->currency() === Currency::base()) {
            return $money;
        }

        return $money->convertTo(Currency::base(), $rate ?? $this->rate($money->currency(), $date));
    }
}
