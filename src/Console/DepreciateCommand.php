<?php

namespace OtimOtim\BookKeeper\Console;

use Illuminate\Console\Command;
use OtimOtim\BookKeeper\Services\FixedAssetService;

class DepreciateCommand extends Command
{
    protected $signature = 'bookkeeper:depreciate {date? : Charge every month ending on or before this date (default: today)}';

    protected $description = 'Post outstanding monthly depreciation for active fixed assets';

    public function handle(FixedAssetService $assets): int
    {
        $entries = $assets->depreciate($this->argument('date'));
        $this->info("Posted {$entries->count()} depreciation entr".($entries->count() === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
