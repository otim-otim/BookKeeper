<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use OtimOtim\BookKeeper\Models\Period;

class PeriodClosed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Period $period,
    ) {}
}
