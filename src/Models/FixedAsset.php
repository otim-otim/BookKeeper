<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Casts\DateCast;
use OtimOtim\BookKeeper\Casts\MoneyCast;
use OtimOtim\BookKeeper\Enums\AssetStatus;
use OtimOtim\BookKeeper\Enums\DepreciationMethod;
use OtimOtim\BookKeeper\Support\Money;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property Money $cost
 * @property Money $salvage_value
 * @property DepreciationMethod $method
 * @property ?int $useful_life_months
 * @property ?string $declining_rate
 * @property Carbon $acquired_on
 * @property Carbon $depreciation_start_date
 * @property AssetStatus $status
 */
class FixedAsset extends Model
{
    protected $table = 'bookkeeper_fixed_assets';

    protected $casts = [
        'cost' => MoneyCast::class,
        'salvage_value' => MoneyCast::class,
        'disposal_proceeds' => MoneyCast::class,
        'method' => DepreciationMethod::class,
        'status' => AssetStatus::class,
        'acquired_on' => DateCast::class,
        'depreciation_start_date' => DateCast::class,
        'disposed_on' => DateCast::class,
        'metadata' => 'array',
    ];

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(AssetDepreciation::class)->orderBy('period_end');
    }

    public function accumulatedDepreciation(): Money
    {
        return Money::ofMinor((int) $this->depreciations()->sum('amount'));
    }

    public function netBookValue(): Money
    {
        return $this->cost->minus($this->accumulatedDepreciation());
    }

    public function depreciableAmount(): Money
    {
        return $this->cost->minus($this->salvage_value);
    }
}
