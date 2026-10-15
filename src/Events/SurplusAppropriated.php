<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use OtimOtim\BookKeeper\Models\JournalEntry;

class SurplusAppropriated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly JournalEntry $entry,
        public readonly array $breakdown,
    ) {}
}
