<?php

namespace OtimOtim\BookKeeper\Console;

use Illuminate\Console\Command;
use OtimOtim\BookKeeper\Services\ChartService;
use OtimOtim\BookKeeper\Services\PeriodService;

class InstallCommand extends Command
{
    protected $signature = 'bookkeeper:install
        {--chart=standard : Chart of accounts template to import (see --list)}
        {--year= : Create the fiscal year starting in this year (default: current)}
        {--list : List the available chart templates}';

    protected $description = 'Import a chart of accounts and open the first fiscal year';

    public function handle(ChartService $charts, PeriodService $periods): int
    {
        if ($this->option('list')) {
            foreach ($charts->templates() as $key => $file) {
                $this->line(sprintf('%-15s %s', $key, $charts->load($key)['name'] ?? ''));
            }

            return self::SUCCESS;
        }

        $created = $charts->import((string) $this->option('chart'));
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
