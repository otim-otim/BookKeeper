<?php

namespace OtimOtim\BookKeeper\RateProviders;

use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use OtimOtim\BookKeeper\Contracts\ExchangeRateProvider;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Support\Currency;

/**
 * Daily reference rates from the free ExchangeRate-API open endpoint
 * (https://open.er-api.com), which covers about 160 currencies including
 * UGX, KES, TZS and RWF. It only serves the latest rates, so it is meant
 * for a daily schedule rather than back-filling history.
 *
 * Needs Laravel's HTTP client (guzzlehttp/guzzle).
 */
class OpenExchangeRatesApiProvider implements ExchangeRateProvider
{
    public function __construct(protected string $endpoint = 'https://open.er-api.com/v6/latest') {}

    public function rates(string $base, array $currencies, CarbonInterface $date): array
    {
        $response = Http::timeout(15)->retry(2, 500)->get($this->endpoint.'/'.strtoupper($base));

        if (! $response->successful() || $response->json('result') !== 'success') {
            throw new InvalidOperationException('Could not fetch exchange rates: '.($response->json('error-type') ?? $response->status()));
        }

        $quoted = (array) $response->json('rates', []);
        $rates = [];

        foreach ($currencies as $currency) {
            // The API quotes units of $currency per 1 base unit; invert it.
            if (! empty($quoted[$currency]) && (float) $quoted[$currency] > 0) {
                $rates[$currency] = (string) BigDecimal::one()
                    ->dividedBy(BigDecimal::of((string) $quoted[$currency]), 10, Currency::roundingMode('HalfUp'));
            }
        }

        return $rates;
    }
}
