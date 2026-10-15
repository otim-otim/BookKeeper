<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Models\JournalEntry;

class AssetsDepreciated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Carbon $periodEnd,
        public readonly ?JournalEntry $entry,
    ) {}
}
