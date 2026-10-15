<?php

namespace OtimOtim\BookKeeper\Data;

use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;

/**
 * One year-end appropriation rule: move an amount from $debit (default
 * retained earnings) to $credit. Accounts are roles or "#code".
 */
final readonly class AppropriationRuleData extends Data
{
    public const BASES = ['net_surplus', 'remaining', 'average_total_assets', 'fixed'];

    public function __construct(
        public string $credit,
        public string $base = 'net_surplus',
        public ?string $percent = null,
        public ?string $amount = null,
        public string $debit = 'retained_earnings',
        public ?string $name = null,
    ) {
        if (! in_array($base, self::BASES, true)) {
            throw new InvalidOperationException("Unknown appropriation base [{$base}]; use ".implode(', ', self::BASES).'.');
        }
        if ($base === 'fixed' ? $amount === null : $percent === null) {
            throw new InvalidOperationException($base === 'fixed' ? 'A fixed appropriation needs an amount.' : 'A percentage appropriation needs a percent.');
        }
    }

    public static function fromArray(array $data): static
    {
        return new self(
            credit: $data['credit'],
            base: $data['base'] ?? 'net_surplus',
            percent: isset($data['percent']) ? (string) $data['percent'] : null,
            amount: isset($data['amount']) ? (string) $data['amount'] : null,
            debit: $data['debit'] ?? 'retained_earnings',
            name: $data['name'] ?? null,
        );
    }

    public function label(): string
    {
        return $this->name ?? $this->credit;
    }
}
