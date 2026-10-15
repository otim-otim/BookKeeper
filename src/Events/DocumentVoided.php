<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use OtimOtim\BookKeeper\Models\Document;

class DocumentVoided implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Document $document,
    ) {}
}
