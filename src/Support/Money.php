<?php

namespace OtimOtim\BookKeeper\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use JsonSerializable;
use OtimOtim\BookKeeper\Exceptions\InvalidAmountException;
use Stringable;

/**
 * Immutable money value held as an integer number of minor units.
 *
 * All arithmetic is exact. Operations that can produce fractions of a minor
 * unit (multiplying by a rate, dividing) take a rounding mode, defaulting to
 * the configured one.
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(
        private readonly int $minor,
        private readonly string $currency,
    ) {
        Currency::assertValid($currency);
    }

    /**
     * Create from a major-unit amount, e.g. Money::of('1500.50', 'USD').
     *
     * @throws InvalidAmountException when the amount has more decimals than the currency allows
     */
    public static function of(self|BigNumber|int|float|string $amount, ?string $currency = null): self
    {
        if ($amount instanceof self) {
            return $amount;
        }

        $currency = strtoupper($currency ?? Currency::base());

        try {
            $decimal = is_float($amount) ? BigDecimal::of(self::floatToString($amount)) : BigDecimal::of($amount);
            $minor = $decimal->toScale(Currency::precision($currency))
                ->withPointMovedRight(Currency::precision($currency))
                ->toInt();
        } catch (MathException $e) {
            throw new InvalidAmountException(sprintf(
                'Invalid amount [%s] for currency %s: %s', (string) $amount, $currency, $e->getMessage()
            ), previous: $e);
        }

        return new self($minor, $currency);
    }

    /**
     * Create from a major-unit amount, rounding any excess decimals.
     */
    public static function ofRounded(BigNumber|int|float|string $amount, ?string $currency = null, ?RoundingMode $rounding = null): self
    {
        $currency = strtoupper($currency ?? Currency::base());
        $decimal = is_float($amount) ? BigDecimal::of(self::floatToString($amount)) : BigDecimal::of($amount);
        $precision = Currency::precision($currency);

        return new self(
            $decimal->toScale($precision, $rounding ?? Currency::rounding())->withPointMovedRight($precision)->toInt(),
            $currency,
        );
    }

    public static function ofMinor(int $minor, ?string $currency = null): self
    {
        return new self($minor, strtoupper($currency ?? Currency::base()));
    }

    public static function zero(?string $currency = null): self
    {
        return self::ofMinor(0, $currency);
    }

    /**
     * Sum a list of Money values (all in the same currency).
     *
     * @param  iterable<Money>  $values
     */
    public static function sum(iterable $values, ?string $currency = null): self
    {
        $total = self::zero($currency);

        foreach ($values as $value) {
            $total = $total->plus($value);
        }

        return $total;
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function toDecimal(): BigDecimal
    {
        return BigDecimal::ofUnscaledValue($this->minor, Currency::precision($this->currency));
    }

    /**
     * Major-unit amount as a string, e.g. "1500.50".
     */
    public function amount(): string
    {
        return (string) $this->toDecimal();
    }

    public function plus(self|int|string $other): self
    {
        return new self($this->minor + $this->coerce($other)->minor, $this->currency);
    }

    public function minus(self|int|string $other): self
    {
        return new self($this->minor - $this->coerce($other)->minor, $this->currency);
    }

    /**
     * Multiply by a factor such as a rate or quantity.
     */
    public function multipliedBy(BigNumber|int|string $factor, ?RoundingMode $rounding = null): self
    {
        $result = BigDecimal::of($this->minor)->multipliedBy($factor)->toScale(0, $rounding ?? Currency::rounding());

        return new self($result->toInt(), $this->currency);
    }

    public function dividedBy(BigNumber|int|string $divisor, ?RoundingMode $rounding = null): self
    {
        $result = BigDecimal::of($this->minor)->dividedBy($divisor, 0, $rounding ?? Currency::rounding());

        return new self($result->toInt(), $this->currency);
    }

    /**
     * Percentage of this amount, e.g. ->percent('18') for 18%.
     */
    public function percent(BigNumber|int|string $percent, ?RoundingMode $rounding = null): self
    {
        $result = BigDecimal::of($this->minor)->multipliedBy($percent)->dividedBy(100, 0, $rounding ?? Currency::rounding());

        return new self($result->toInt(), $this->currency);
    }

    /**
     * Split into parts proportional to the given ratios without losing a
     * minor unit; remainders go to the first parts.
     *
     * @param  array<int|string, int|string>  $ratios
     * @return array<int|string, Money>
     */
    public function allocate(array $ratios): array
    {
        $total = BigDecimal::zero();
        foreach ($ratios as $ratio) {
            $total = $total->plus($ratio);
        }

        if ($total->isZero()) {
            throw new InvalidAmountException('Cannot allocate against ratios that sum to zero.');
        }

        $results = [];
        $remainder = $this->minor;

        foreach ($ratios as $key => $ratio) {
            $share = BigDecimal::of($this->minor)->multipliedBy($ratio)->dividedBy($total, 0, Currency::roundingMode('Down'))->toInt();
            $results[$key] = $share;
            $remainder -= $share;
        }

        $step = $remainder >= 0 ? 1 : -1;
        foreach (array_keys($results) as $key) {
            if ($remainder === 0) {
                break;
            }
            $results[$key] += $step;
            $remainder -= $step;
        }

        return array_map(fn (int $minor) => new self($minor, $this->currency), $results);
    }

    public function negated(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function abs(): self
    {
        return new self(abs($this->minor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function compareTo(self|int|string $other): int
    {
        return $this->minor <=> $this->coerce($other)->minor;
    }

    public function equals(self|int|string $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isGreaterThan(self|int|string $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self|int|string $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function min(self $other): self
    {
        return $this->compareTo($other) <= 0 ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->compareTo($other) >= 0 ? $this : $other;
    }

    /**
     * Convert into another currency at the given rate (1 unit of this
     * currency = $rate units of the target currency).
     */
    public function convertTo(string $currency, BigNumber|int|string $rate, ?RoundingMode $rounding = null): self
    {
        $value = $this->toDecimal()->multipliedBy($rate);

        return self::ofRounded($value, $currency, $rounding);
    }

    /**
     * Round to the currency's smallest cash denomination (e.g. CHF 0.05,
     * UGX 50). Amounts in currencies without one are returned unchanged.
     */
    public function roundToCash(?RoundingMode $rounding = null): self
    {
        $increment = Currency::cashIncrement($this->currency);

        if ($increment === null) {
            return $this;
        }

        $step = BigDecimal::of($increment)->withPointMovedRight(Currency::precision($this->currency));
        $units = BigDecimal::of($this->minor)->dividedBy($step, 0, $rounding ?? Currency::rounding());

        return new self($units->multipliedBy($step)->toScale(0)->toInt(), $this->currency);
    }

    public function toFloat(): float
    {
        return $this->toDecimal()->toFloat();
    }

    /**
     * Format for display in a locale, e.g. "USh 50,000", "1.234,56 €",
     * "₹1,00,000.00". Uses the intl extension when installed (locale from
     * config('bookkeeper.locale') or the app locale); otherwise "UGX 50,000".
     */
    public function format(?string $locale = null): string
    {
        $precision = Currency::precision($this->currency);

        if (class_exists(\NumberFormatter::class)) {
            $locale ??= config('bookkeeper.locale') ?? (function_exists('app') ? app()->getLocale() : 'en');
            $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $precision);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $precision);
            $formatted = $formatter->formatCurrency($this->toFloat(), $this->currency);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        $sign = $this->minor < 0 ? '-' : '';

        return $sign.$this->currency.' '.number_format(abs($this->toFloat()), $precision);
    }

    public function jsonSerialize(): string
    {
        return $this->amount();
    }

    public function __toString(): string
    {
        return $this->amount();
    }

    /**
     * Integers are minor units; strings are major-unit amounts.
     */
    private function coerce(self|int|string $other): self
    {
        $money = match (true) {
            $other instanceof self => $other,
            is_int($other) => self::ofMinor($other, $this->currency),
            default => self::of($other, $this->currency),
        };

        if ($money->currency !== $this->currency) {
            throw new InvalidAmountException("Currency mismatch: {$this->currency} and {$money->currency}.");
        }

        return $money;
    }

    private static function floatToString(float $amount): string
    {
        // Shortest representation that round-trips, e.g. 999.99 => "999.99".
        return json_encode($amount, JSON_PRESERVE_ZERO_FRACTION);
    }
}
