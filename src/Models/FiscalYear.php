<?php

namespace OtimOtim\BookKeeper\Models;

use OtimOtim\BookKeeper\Casts\DateCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OtimOtim\BookKeeper\Enums\PeriodStatus;

/**
 * @property int $id
 * @property string $name
 * @property \Illuminate\Support\Carbon $start_date
 * @property \Illuminate\Support\Carbon $end_date
 * @property PeriodStatus $status
 */
class FiscalYear extends Model
{
    protected $table = 'bookkeeper_fiscal_years';

    protected $casts = [
        'start_date' => DateCast::class,
        'end_date' => DateCast::class,
        'status' => PeriodStatus::class,
        'closed_at' => 'datetime',
    ];

    public function periods(): HasMany
    {
        return $this->hasMany(Period::class)->orderBy('start_date');
    }

    public function closingEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'closing_entry_id');
    }

    public function isOpen(): bool
    {
        return $this->status === PeriodStatus::Open;
    }

    public function contains(\DateTimeInterface|string $date): bool
    {
        $date = \Illuminate\Support\Carbon::parse($date)->toDateString();

        return $date >= $this->start_date->toDateString() && $date <= $this->end_date->toDateString();
    }
}
