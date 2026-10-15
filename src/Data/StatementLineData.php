<?php

namespace OtimOtim\BookKeeper\Data;

use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Support\Money;

/**
 * One line of a bank or mobile-money statement. Positive amounts are
 * money in, negative money out, in the statement's currency.
 */
final readonly class StatementLineData extends Data
{
    public function __construct(
        public Carbon $date,
        public Money|int|float|string $amount,
        public ?string $description = null,
        public ?string $reference = null,
    ) {}

    /**
     * Keyed (date, amount, description, reference) or positional
     * ([date, amount, description, reference]).
     */
    public static function fromArray(array $data): static
    {
        return new self(
            date: Carbon::parse($data['date'] ?? $data[0])->startOfDay(),
            amount: $data['amount'] ?? $data[1],
            description: $data['description'] ?? $data[2] ?? null,
            reference: $data['reference'] ?? $data[3] ?? null,
        );
    }
}
