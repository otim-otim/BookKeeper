<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use OtimOtim\BookKeeper\Models\FiscalYear;

class FiscalYearClosed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly FiscalYear $fiscalYear,
    ) {}
}
