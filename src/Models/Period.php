<?php

namespace OtimOtim\BookKeeper\Models;

use OtimOtim\BookKeeper\Casts\DateCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OtimOtim\BookKeeper\Enums\PeriodStatus;

/**
 * An accounting period (normally a month) within a fiscal year.
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property string $name
 * @property \Illuminate\Support\Carbon $start_date
 * @property \Illuminate\Support\Carbon $end_date
 * @property PeriodStatus $status
 */
class Period extends Model
{
    protected $table = 'bookkeeper_periods';

    protected $casts = [
        'start_date' => DateCast::class,
        'end_date' => DateCast::class,
        'status' => PeriodStatus::class,
        'closed_at' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function isOpen(): bool
    {
        return $this->status === PeriodStatus::Open;
    }
}
