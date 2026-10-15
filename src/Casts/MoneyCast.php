<?php

namespace OtimOtim\BookKeeper\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Casts an integer minor-unit column to Money.
 *
 * Usage: 'total' => MoneyCast::class.':currency' to take the currency from
 * the model's "currency" attribute (or a relation path such as
 * "document.currency"); without an argument the base currency
 * is used. When setting, integers are minor units and strings are
 * major-unit amounts.
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(protected ?string $currencyAttribute = null) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::ofMinor((int) $value, $this->currency($model, $attributes));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return match (true) {
            $value === null => null,
            $value instanceof Money => $value->minor(),
            is_int($value) => $value,
            default => Money::of($value, $this->currency($model, $attributes))->minor(),
        };
    }

    /**
     * The currency attribute may be a dotted relation path, e.g. "document.currency".
     */
    protected function currency(Model $model, array $attributes): ?string
    {
        if ($this->currencyAttribute === null) {
            return null;
        }

        return str_contains($this->currencyAttribute, '.')
            ? data_get($model, $this->currencyAttribute)
            : ($attributes[$this->currencyAttribute] ?? null);
    }
}
