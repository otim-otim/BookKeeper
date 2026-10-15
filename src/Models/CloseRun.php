<?php

namespace OtimOtim\BookKeeper\Models;

use OtimOtim\BookKeeper\Casts\DateCast;
use OtimOtim\BookKeeper\Enums\CloseType;

class CloseRun extends Model
{
    protected $table = 'bookkeeper_close_runs';

    protected $casts = [
        'type' => CloseType::class,
        'date' => DateCast::class,
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];
}
