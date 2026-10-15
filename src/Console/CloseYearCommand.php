<?php

namespace OtimOtim\BookKeeper\Console;

use Illuminate\Console\Command;
use OtimOtim\BookKeeper\Exceptions\BookKeeperException;
use OtimOtim\BookKeeper\Services\CloseService;

class CloseYearCommand extends Command
{
    protected $signature = 'bookkeeper:close-year {year : Fiscal year name (FY2026) or starting calendar year (2026)}';

    protected $description = 'Run year end: close open months, post the closing entry and open the next year';

    public function handle(CloseService $close): int
    {
        $year = $this->argument('year');

        try {
            $close->closeYear(ctype_digit((string) $year) ? (int) $year : $year);
        } catch (BookKeeperException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Closed fiscal year {$year}.");

        return self::SUCCESS;
    }
}
