<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use OtimOtim\BookKeeper\Models\JournalEntry;

class EntryReversed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly JournalEntry $original,
        public readonly JournalEntry $reversal,
    ) {}
}
