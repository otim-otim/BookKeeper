<?php

namespace OtimOtim\BookKeeper\Data;

use OtimOtim\BookKeeper\Enums\TillType;
use OtimOtim\BookKeeper\Models\Account;

/**
 * A teller till or vault. Either use an existing cash $account, or let one
 * be created (code $accountCode, under $parent or the "cash_on_hand" role).
 */
final readonly class TillData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public TillType $type = TillType::Till,
        public Account|int|string|null $account = null,
        public ?string $accountCode = null,
        public Account|int|string|null $parent = null,
        public ?int $branchId = null,
        public ?array $metadata = null,
    ) {}

    public static function fromArray(array $data): static
    {
        $type = $data['type'] ?? TillType::Till;

        return new self(
            code: $data['code'],
            name: $data['name'],
            type: $type instanceof TillType ? $type : TillType::from($type),
            account: $data['account'] ?? null,
            accountCode: $data['account_code'] ?? null,
            parent: $data['parent'] ?? null,
            branchId: isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            metadata: $data['metadata'] ?? null,
        );
    }
}
