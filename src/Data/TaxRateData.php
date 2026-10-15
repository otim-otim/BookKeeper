<?php

namespace OtimOtim\BookKeeper\Data;

use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\TaxType;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;

/**
 * A tax rate version. A later effective_from for the same code replaces
 * the previous version from that date.
 */
final readonly class TaxRateData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public TaxType $type,
        public string $rate,
        public Account|int|string|null $account = null,
        public ?Carbon $effectiveFrom = null,
        public ?Carbon $effectiveTo = null,
        public bool $isActive = true,
        public ?string $description = null,
    ) {
        if ($type !== TaxType::Purchase && $account === null) {
            throw new InvalidOperationException("A {$type->value} tax rate needs an account to post tax to.");
        }
    }

    public static function fromArray(array $data): static
    {
        return new self(
            code: $data['code'],
            name: $data['name'],
            type: $data['type'] instanceof TaxType ? $data['type'] : TaxType::from($data['type']),
            rate: (string) $data['rate'],
            account: $data['account'] ?? null,
            effectiveFrom: isset($data['effective_from']) ? Carbon::parse($data['effective_from'])->startOfDay() : null,
            effectiveTo: isset($data['effective_to']) ? Carbon::parse($data['effective_to'])->startOfDay() : null,
            isActive: (bool) ($data['is_active'] ?? true),
            description: $data['description'] ?? null,
        );
    }
}
