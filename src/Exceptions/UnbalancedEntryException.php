<?php

namespace Otimdibossman\BookKeeper\Exceptions;

use RuntimeException;

class UnbalancedEntryException extends RuntimeException
{
    public function __construct(float $totalDebits, float $totalCredits)
    {
        parent::__construct(
            sprintf(
                'Journal entry is not balanced. Total debits: %.4f, Total credits: %.4f, Difference: %.4f',
                $totalDebits,
                $totalCredits,
                abs($totalDebits - $totalCredits)
            )
        );
    }
}
