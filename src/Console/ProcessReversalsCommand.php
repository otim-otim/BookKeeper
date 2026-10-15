<?php

namespace OtimOtim\BookKeeper\Console;

use Illuminate\Console\Command;
use OtimOtim\BookKeeper\Services\LedgerService;

class ProcessReversalsCommand extends Command
{
    protected $signature = 'bookkeeper:reversals {date? : Reverse entries scheduled on or before this date (default: today)}';

    protected $description = 'Post scheduled reversals of accrual entries';

    public function handle(LedgerService $ledger): int
    {
        $reversals = $ledger->processScheduledReversals($this->argument('date'));
        $this->info("Posted {$reversals->count()} reversal(s).");

        return self::SUCCESS;
    }
}
