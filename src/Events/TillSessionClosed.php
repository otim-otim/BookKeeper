<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use OtimOtim\BookKeeper\Models\TillSession;

class TillSessionClosed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly TillSession $session,
    ) {}
}
