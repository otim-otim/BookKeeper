<?php

namespace OtimOtim\BookKeeper\Data;

use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Support\Money;

/**
 * A provider statement to reconcile.
 */
final readonly class BankStatementData extends Data
{
    /**
     * @param  list<StatementLineData>  $lines
     */
    public function __construct(
        public Carbon $startDate,
        public Carbon $endDate,
        public Money|int|float|string $closingBalance,
        public Money|int|float|string $openingBalance = 0,
        public array $lines = [],
        public ?string $reference = null,
        public ?string $notes = null,
    ) {}

    public static function fromArray(array $data): static
    {
        return new self(
            startDate: Carbon::parse($data['start_date'])->startOfDay(),
            endDate: Carbon::parse($data['end_date'])->startOfDay(),
            closingBalance: $data['closing_balance'],
            openingBalance: $data['opening_balance'] ?? 0,
            lines: StatementLineData::collect($data['lines'] ?? []),
            reference: $data['reference'] ?? null,
            notes: $data['notes'] ?? null,
        );
    }
}
