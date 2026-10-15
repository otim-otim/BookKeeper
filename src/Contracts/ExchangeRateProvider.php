<?php

namespace OtimOtim\BookKeeper\Contracts;

use Carbon\CarbonInterface;

/**
 * A source of exchange rates (a central bank, a market data API, a fixed table).
 */
interface ExchangeRateProvider
{
    /**
     * Rates for $date, as base-currency units per 1 unit of each currency.
     * Currencies the source does not quote are simply left out.
     *
     * @param  list<string>  $currencies
     * @return array<string, string> currency => rate (decimal string)
     */
    public function rates(string $base, array $currencies, CarbonInterface $date): array;
}
