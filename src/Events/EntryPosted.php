<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use OtimOtim\BookKeeper\Models\JournalEntry;

/**
 * A journal entry was posted to the ledger. Listen to this for cash
 * threshold reporting, notifications or projections.
 */
class EntryPosted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly JournalEntry $entry,
    ) {}
}
