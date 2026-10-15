<?php

namespace OtimOtim\BookKeeper\Console;

use Illuminate\Console\Command;
use OtimOtim\BookKeeper\Exceptions\BookKeeperException;
use OtimOtim\BookKeeper\Services\CloseService;

class CloseMonthCommand extends Command
{
    protected $signature = 'bookkeeper:close-month {date? : Any date in the period to close (default: last month)}';

    protected $description = 'Run month end: close tasks, depreciation, and lock the period';

    public function handle(CloseService $close): int
    {
        $date = $this->argument('date') ?? now()->subMonthNoOverflow()->endOfMonth()->toDateString();

        try {
            $run = $close->closeMonth($date);
        } catch (BookKeeperException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Closed the period ending {$run->date->toDateString()}.");

        return self::SUCCESS;
    }
}
