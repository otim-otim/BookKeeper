<?php

namespace OtimOtim\BookKeeper\Contracts;

use OtimOtim\BookKeeper\Close\CloseContext;

/**
 * A step the host application runs as part of end of day, month or year:
 * interest accrual, penalties, loan-loss provisions, fee charges, etc.
 *
 * Register implementations in config('bookkeeper.close.{day|month|year}').
 * Tasks run inside the close transaction, before BookKeeper's own steps,
 * and should post through the BookKeeper facade with an idempotency key.
 */
interface CloseTask
{
    public function handle(CloseContext $context): void;
}
