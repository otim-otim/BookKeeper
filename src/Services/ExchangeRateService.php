<?php

namespace OtimOtim\BookKeeper\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Contracts\ExchangeRateProvider;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\ExchangeRate;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Exchange rates expressed as base-currency units per 1 foreign unit.
 */
class ExchangeRateService
{
    public function set(string $currency, string|int|float $rate, \DateTimeInterface|string|null $date = null): ExchangeRate
    {
        $currency = Currency::assertValid($currency);

        return ExchangeRate::query()->updateOrCreate(
            ['currency' => $currency, 'date' => Carbon::parse($date ?? now())->toDateString()],
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

    /**
     * Units of $to per 1 unit of $from on a date, crossing through the base
     * currency when neither side is the base (e.g. KES per USD in UGX books).
     */
    public function crossRate(string $from, string $to, \DateTimeInterface|string|null $date = null): string
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return '1';
        }

        return (string) BigDecimal::of($this->rate($from, $date))
            ->dividedBy($this->rate($to, $date), 12, Currency::rounding())
            ->toScale(12);
    }

    /**
     * Convert money into any currency: foreign to base, base to foreign
     * (inverse rate) or foreign to foreign (through base), rounding once.
     */
    public function convert(Money $money, string $to, \DateTimeInterface|string|null $date = null): Money
    {
        $to = Currency::assertValid($to);

        if ($money->currency() === $to) {
            return $money;
        }

        $value = $money->toDecimal()
            ->multipliedBy($this->rate($money->currency(), $date))
            ->dividedBy($this->rate($to, $date), Currency::precision($to) + 6, Currency::rounding());

        return Money::ofRounded($value, $to);
    }

    /**
     * Fetch rates from the configured provider and store them for $date.
     *
     * @return array<string, string> the rates stored
     */
    public function fetch(\DateTimeInterface|string|null $date = null, ?array $currencies = null, ?ExchangeRateProvider $provider = null): array
    {
        $provider ??= $this->provider();
        $date = Carbon::parse($date ?? now())->startOfDay();
        $currencies = array_values(array_diff(
            array_map('strtoupper', $currencies ?? $this->currenciesInUse()),
            [Currency::base()],
        ));

        if ($currencies === []) {
            return [];
        }

        $rates = $provider->rates(Currency::base(), $currencies, $date);

        foreach ($rates as $currency => $rate) {
            $this->set($currency, $rate, $date);
        }

        return $rates;
    }

    /**
     * Currencies to keep rates for: configured, or every foreign currency
     * used by accounts, documents, payments and existing rates.
     *
     * @return list<string>
     */
    public function currenciesInUse(): array
    {
        $configured = (array) config('bookkeeper.exchange_rates.currencies', []);

        if ($configured !== []) {
            return array_values(array_unique(array_map('strtoupper', $configured)));
        }

        $db = Database::connection();
        $found = collect()
            ->merge($db->table('bookkeeper_accounts')->whereNotNull('currency')->distinct()->pluck('currency'))
            ->merge($db->table('bookkeeper_documents')->distinct()->pluck('currency'))
            ->merge($db->table('bookkeeper_payments')->distinct()->pluck('currency'))
            ->merge($db->table('bookkeeper_exchange_rates')->distinct()->pluck('currency'))
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->filter()
            ->unique()
            ->reject(fn ($c) => $c === Currency::base())
            ->sort()
            ->values();

        return $found->all();
    }

    protected function provider(): ExchangeRateProvider
    {
        $class = config('bookkeeper.exchange_rates.provider');

        if (! $class) {
            throw new InvalidOperationException('No exchange rate provider configured (bookkeeper.exchange_rates.provider).');
        }

        $provider = app($class);

        return $provider instanceof ExchangeRateProvider
            ? $provider
            : throw new InvalidOperationException("{$class} must implement ".ExchangeRateProvider::class.'.');
    }
}
