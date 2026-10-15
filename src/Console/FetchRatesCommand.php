<?php

namespace OtimOtim\BookKeeper\Console;

use Illuminate\Console\Command;
use OtimOtim\BookKeeper\Exceptions\BookKeeperException;
use OtimOtim\BookKeeper\Services\ExchangeRateService;

class FetchRatesCommand extends Command
{
    protected $signature = 'bookkeeper:rates
        {date? : Store the rates under this date (default: today)}
        {--currency=* : Only these currencies}';

    protected $description = 'Fetch exchange rates from the configured provider';

    public function handle(ExchangeRateService $rates): int
    {
        try {
            $stored = $rates->fetch($this->argument('date'), $this->option('currency') ?: null);
        } catch (BookKeeperException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($stored as $currency => $rate) {
            $this->line("{$currency}: {$rate}");
        }

        $this->info('Stored '.count($stored).' rate(s).');

        return self::SUCCESS;
    }
}
