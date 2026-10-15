<?php

namespace OtimOtim\BookKeeper\Exceptions;

use OtimOtim\BookKeeper\Support\Money;

class UnbalancedEntryException extends BookKeeperException
{
    public function __construct(Money $debits, Money $credits)
    {
        parent::__construct(sprintf(
            "Journal entry is not balanced. Total debits: %s, total credits: %s, difference: %s",
            $debits->amount(),
            $credits->amount(),
            $debits->minus($credits)->abs()->amount(),
        ));
    }
}
