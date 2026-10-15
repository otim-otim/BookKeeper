<?php

namespace OtimOtim\BookKeeper\Console;

use Illuminate\Console\Command;
use OtimOtim\BookKeeper\Exceptions\BookKeeperException;
use OtimOtim\BookKeeper\Services\CloseService;

class CloseDayCommand extends Command
{
    protected $signature = 'bookkeeper:close-day {date? : Date to close (default: today)}';

    protected $description = 'Run end of day: close tasks, scheduled reversals and balance snapshot';

    public function handle(CloseService $close): int
    {
        try {
            $run = $close->closeDay($this->argument('date'));
        } catch (BookKeeperException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Closed {$run->date->toDateString()}.");

        return self::SUCCESS;
    }
}
