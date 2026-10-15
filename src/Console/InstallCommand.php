<?php

namespace OtimOtim\BookKeeper\Console;

use Illuminate\Console\Command;
use OtimOtim\BookKeeper\Services\ChartService;
use OtimOtim\BookKeeper\Services\PeriodService;
use OtimOtim\BookKeeper\Services\SettingsService;

class InstallCommand extends Command
{
    protected $signature = 'bookkeeper:install
        {--chart=standard : Chart of accounts template to import (see --list)}
        {--year= : Create the fiscal year starting in this year (default: current)}
        {--currency= : Base currency for these books (default: from the chart, then config)}
        {--locale= : Language for account names when the chart has translations (e.g. sw)}
        {--list : List the available chart templates}';

    protected $description = 'Import a chart of accounts and open the first fiscal year';

    public function handle(ChartService $charts, PeriodService $periods, SettingsService $settings): int
    {
        if ($this->option('list')) {
            foreach ($charts->templates() as $key => $file) {
                $this->line(sprintf('%-15s %s', $key, $charts->load($key)['name'] ?? ''));
            }

            return self::SUCCESS;
        }

        $currency = $this->option('currency') ?? $charts->load((string) $this->option('chart'))['currency'] ?? null;
        if ($currency) {
            $settings->setBaseCurrency($currency);
        }
        $this->info('Base currency: '.$settings->baseCurrency().'.');

        $created = $charts->import((string) $this->option('chart'), $this->option('locale'));
        $this->info("Imported chart [{$this->option('chart')}]: {$created} account(s) created.");

        $year = (int) ($this->option('year') ?: now()->year);
        $start = now()->setDate($year, (int) config('bookkeeper.fiscal_year.start_month', 1), 1);
        if ($start->isFuture() && ! $this->option('year')) {
            $start->subYear();
        }

        if ($periods->fiscalYearFor($start)) {
            $this->line('Fiscal year already exists.');
        } else {
            $fiscalYear = $periods->createFiscalYear($start);
            $this->info("Opened fiscal year {$fiscalYear->name}.");
        }

        return self::SUCCESS;
    }
}
