<?php

namespace OtimOtim\BookKeeper\Models;

use OtimOtim\BookKeeper\Casts\DateCast;

/**
 * Cumulative debit and credit totals per account at the end of a day.
 */
class AccountBalance extends Model
{
    protected $table = 'bookkeeper_account_balances';

    protected $casts = [
        'date' => DateCast::class,
    ];
}
