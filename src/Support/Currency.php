<?php

namespace OtimOtim\BookKeeper\Support;

use Brick\Math\RoundingMode;
use OtimOtim\BookKeeper\Exceptions\InvalidCurrencyException;
use OtimOtim\BookKeeper\Services\SettingsService;

/**
 * Currency metadata: validation, minor-unit precision, cash rounding
 * increments, and the books' base currency.
 *
 * Data comes from ISO 4217 (see Iso4217), overridable per currency in
 * config('bookkeeper.currencies'):
 *
 *   'currencies' => [
 *       'UGX' => 0,                                          // precision only
 *       'KES' => ['decimals' => 2, 'cash_increment' => '1'], // full form
 *       'XBT' => ['decimals' => 8, 'name' => 'Bitcoin'],     // a custom unit
 *   ],
 */
final class Currency
{
    /**
     * The base currency of the current books: stored in the database once
     * set, falling back to config('bookkeeper.currency').
     */
    public static function base(): string
    {
        return function_exists('app') && app()->bound(SettingsService::class)
            ? app(SettingsService::class)->baseCurrency()
            : self::configuredBase();
    }

    public static function configuredBase(): string
    {
        return strtoupper((string) config('bookkeeper.currency', 'USD'));
    }

    public static function exists(string $currency): bool
    {
        $currency = strtoupper($currency);

        return isset(Iso4217::CURRENCIES[$currency]) || self::override($currency) !== null;
    }

    /**
     * @throws InvalidCurrencyException
     */
    public static function assertValid(string $currency): string
    {
        $currency = strtoupper($currency);

        if (! self::exists($currency)) {
            throw new InvalidCurrencyException("Unknown currency code [{$currency}]. Use an ISO 4217 code or define it in bookkeeper.currencies.");
        }

        return $currency;
    }

    /**
     * Number of minor-unit decimals, e.g. 2 for USD, 0 for UGX, 3 for KWD.
     */
    public static function precision(?string $currency = null): int
    {
        $currency = strtoupper($currency ?? self::base());
        $override = self::override($currency);

        if ($override !== null && isset($override['decimals'])) {
            return (int) $override['decimals'];
        }

        return Iso4217::CURRENCIES[$currency][0]
            ?? throw new InvalidCurrencyException("Unknown currency code [{$currency}]. Use an ISO 4217 code or define it in bookkeeper.currencies.");
    }

    public static function name(string $currency): string
    {
        $currency = strtoupper($currency);

        return self::override($currency)['name'] ?? Iso4217::CURRENCIES[$currency][1] ?? $currency;
    }

    /**
     * Smallest cash denomination in major units (e.g. "0.05" for CHF), or
     * null when cash is paid to the minor unit.
     */
    public static function cashIncrement(string $currency): ?string
    {
        $currency = strtoupper($currency);
        $override = self::override($currency);

        if ($override !== null && array_key_exists('cash_increment', $override)) {
            return $override['cash_increment'] === null ? null : (string) $override['cash_increment'];
        }

        return Iso4217::CASH_INCREMENTS[$currency] ?? null;
    }

    /**
     * Every known currency: code => name.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $all = array_map(fn (array $c) => $c[1], Iso4217::CURRENCIES);

        foreach ((array) config('bookkeeper.currencies', []) as $code => $value) {
            $all[strtoupper($code)] = self::name($code);
        }

        ksort($all);

        return $all;
    }

    /**
     * The configured rounding mode. Accepts both brick/math 1.x case names
     * ("HalfUp") and the older constant style ("HALF_UP").
     */
    public static function rounding(): RoundingMode
    {
        return self::roundingMode((string) config('bookkeeper.rounding', 'HalfUp'));
    }

    public static function roundingMode(string $name): RoundingMode
    {
        $candidates = [
            $name,
            str_replace(' ', '', ucwords(strtolower(str_replace('_', ' ', $name)))),
            strtoupper(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)),
        ];

        foreach ($candidates as $candidate) {
            if (defined(RoundingMode::class.'::'.$candidate)) {
                return constant(RoundingMode::class.'::'.$candidate);
            }
        }

        throw new \InvalidArgumentException("Unknown rounding mode [{$name}].");
    }

    /**
     * @return array{decimals?: int, cash_increment?: ?string, name?: string}|null
     */
    private static function override(string $currency): ?array
    {
        $configured = config('bookkeeper.currencies', []);

        foreach ([$currency, strtolower($currency)] as $key) {
            if (array_key_exists($key, $configured)) {
                $value = $configured[$key];

                return is_array($value) ? $value : ['decimals' => (int) $value];
            }
        }

        return null;
    }
}
