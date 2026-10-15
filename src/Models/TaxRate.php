<?php

namespace OtimOtim\BookKeeper\Models;

use OtimOtim\BookKeeper\Casts\DateCast;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OtimOtim\BookKeeper\Enums\TaxType;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Money;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property TaxType $type
 * @property string $rate Percentage, e.g. "18.000000"
 * @property ?int $account_id
 */
class TaxRate extends Model
{
    protected $table = 'bookkeeper_tax_rates';

    protected $casts = [
        'type' => TaxType::class,
        'effective_from' => DateCast::class,
        'effective_to' => DateCast::class,
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Tax on a tax-exclusive amount.
     */
    public function taxOn(Money $net): Money
    {
        return $net->percent($this->rate);
    }

    /**
     * Tax contained in a tax-inclusive amount.
     */
    public function taxIn(Money $gross): Money
    {
        $rate = BigDecimal::of($this->rate);
        $tax = BigDecimal::of($gross->minor())->multipliedBy($rate)->dividedBy($rate->plus(100), 0, Currency::rounding());

        return Money::ofMinor($tax->toInt(), $gross->currency());
    }

    public function isRecoverable(): bool
    {
        return $this->account_id !== null;
    }

    public function scopeEffectiveOn(Builder $query, \DateTimeInterface|string $date): Builder
    {
        $date = \Illuminate\Support\Carbon::parse($date)->toDateString();

        return $query
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
