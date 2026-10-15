<?php

namespace OtimOtim\BookKeeper\RateProviders;

use Carbon\CarbonInterface;
use OtimOtim\BookKeeper\Contracts\ExchangeRateProvider;

/**
 * Rates from config('bookkeeper.exchange_rates.fixed'), for pegged
 * currencies or rates set by policy (e.g. a board-approved rate).
 */
class FixedRateProvider implements ExchangeRateProvider
{
    public function rates(string $base, array $currencies, CarbonInterface $date): array
    {
        $fixed = array_change_key_case((array) config('bookkeeper.exchange_rates.fixed', []), CASE_UPPER);

        return array_map('strval', array_intersect_key($fixed, array_flip($currencies)));
    }
}
