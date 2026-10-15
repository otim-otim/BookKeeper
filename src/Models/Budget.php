<?php

namespace OtimOtim\BookKeeper\Models;

use OtimOtim\BookKeeper\Casts\DateCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OtimOtim\BookKeeper\Enums\BudgetStatus;

/**
 * @property int $id
 * @property string $name
 * @property \Illuminate\Support\Carbon $start_date
 * @property \Illuminate\Support\Carbon $end_date
 * @property ?int $branch_id
 * @property BudgetStatus $status
 */
class Budget extends Model
{
    protected $table = 'bookkeeper_budgets';

    protected $casts = [
        'start_date' => DateCast::class,
        'end_date' => DateCast::class,
        'status' => BudgetStatus::class,
        'approved_at' => 'datetime',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }
}
