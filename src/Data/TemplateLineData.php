<?php

namespace OtimOtim\BookKeeper\Data;

use OtimOtim\BookKeeper\Enums\Side;
use OtimOtim\BookKeeper\Exceptions\InvalidEntryException;

/**
 * One line of a posting template: an account reference (role, "#code" or
 * ":context_key"), an amount expression ("principal - fee") and optionally
 * the context key of the subledger party.
 */
final readonly class TemplateLineData extends Data
{
    public function __construct(
        public Side $side,
        public string $account,
        public string $amount = 'amount',
        public ?string $subledger = null,
        public ?string $memo = null,
        public ?string $taxRate = null,
        public ?int $branchId = null,
    ) {}

    public static function fromArray(array $data): static
    {
        $side = isset($data['debit']) ? Side::Debit : (isset($data['credit']) ? Side::Credit
            : throw new InvalidEntryException('A template line names neither a debit nor a credit account.'));

        return new self(
            side: $side,
            account: (string) $data[$side->value],
            amount: $data['amount'] ?? 'amount',
            subledger: $data['subledger'] ?? null,
            memo: $data['memo'] ?? null,
            taxRate: $data['tax_rate'] ?? null,
            branchId: $data['branch_id'] ?? null,
        );
    }
}
