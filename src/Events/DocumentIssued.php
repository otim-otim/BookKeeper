<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use OtimOtim\BookKeeper\Models\Document;

class DocumentIssued implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Document $document,
    ) {}
}
