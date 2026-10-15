<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Casts\DateCast;
use OtimOtim\BookKeeper\Enums\BudgetStatus;

/**
 * @property int $id
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
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
