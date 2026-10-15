<?php

namespace OtimOtim\BookKeeper\Models;

use OtimOtim\BookKeeper\Casts\DateCast;

/**
 * Base-currency units per 1 unit of a foreign currency on a date.
 */
class ExchangeRate extends Model
{
    protected $table = 'bookkeeper_exchange_rates';

    protected $casts = [
        'date' => DateCast::class,
    ];
}
