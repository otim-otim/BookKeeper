<?php

namespace OtimOtim\BookKeeper\Exceptions;

class PeriodClosedException extends BookKeeperException
{
    public static function forDate(string $date): self
    {
        return new self("The accounting period for {$date} is closed. Post into an open period or reopen it.");
    }

    public static function noPeriod(string $date): self
    {
        return new self("No accounting period covers {$date}. Create the fiscal year first.");
    }
}
