<?php

namespace OtimOtim\BookKeeper\Support;

use Brick\Math\RoundingMode;

/**
 * Currency metadata: minor-unit precision and the configured rounding mode.
 */
final class Currency
{
    /**
     * ISO 4217 minor units for currencies that do not use 2 decimals,
     * plus the East African currencies BookKeeper is commonly used with.
     */
    private const ISO_MINOR_UNITS = [
        'BHD' => 3, 'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'IQD' => 3,
        'ISK' => 0, 'JOD' => 3, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'KWD' => 3,
        'LYD' => 3, 'OMR' => 3, 'PYG' => 0, 'RWF' => 0, 'TND' => 3, 'UGX' => 0,
        'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'KES' => 2, 'TZS' => 2, 'SSP' => 2, 'ETB' => 2, 'CDF' => 2, 'USD' => 2,
        'EUR' => 2, 'GBP' => 2, 'ZAR' => 2,
    ];

    public static function base(): string
    {
        return strtoupper((string) config('bookkeeper.currency', 'USD'));
    }

    public static function precision(?string $currency = null): int
    {
        $currency = strtoupper($currency ?? self::base());
        $configured = config('bookkeeper.currencies', []);

        return (int) ($configured[$currency] ?? self::ISO_MINOR_UNITS[$currency] ?? 2);
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
}
