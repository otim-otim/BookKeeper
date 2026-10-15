<?php

namespace OtimOtim\BookKeeper\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use OtimOtim\BookKeeper\Models\BankStatement;

class BankStatementReconciled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly BankStatement $statement,
    ) {}
}
