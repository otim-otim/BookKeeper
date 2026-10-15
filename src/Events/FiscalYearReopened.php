<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use OtimOtim\BookKeeper\Models\FiscalYear;

class FiscalYearReopened implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly FiscalYear $fiscalYear,
    ) {}
}
