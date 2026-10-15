<?php

namespace OtimOtim\BookKeeper\Console;

use Illuminate\Console\Command;
use OtimOtim\BookKeeper\Services\BalanceService;

class SnapshotCommand extends Command
{
    protected $signature = 'bookkeeper:snapshot {date? : Snapshot balances as of the end of this date (default: yesterday)}';

    protected $description = 'Store cumulative account balances to speed up balance queries';

    public function handle(BalanceService $balances): int
    {
        $date = $this->argument('date') ?? now()->subDay()->toDateString();
        $count = $balances->snapshot($date);
        $this->info("Snapshot of {$count} account(s) as of {$date}.");

        return self::SUCCESS;
    }
}
