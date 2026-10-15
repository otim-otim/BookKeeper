<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use OtimOtim\BookKeeper\Models\JournalEntry;

class DividendsPaid implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly JournalEntry $entry,
        public readonly array $calculation,
    ) {}
}
