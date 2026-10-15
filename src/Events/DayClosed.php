<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Carbon;

class DayClosed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Carbon $date,
    ) {}
}
