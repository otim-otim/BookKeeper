<?php

namespace OtimOtim\BookKeeper\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\EntryType;

/**
 * Header of a journal entry: when, why, where from, and how to post it.
 */
final readonly class EntryData extends Data
{
    public function __construct(
        public ?Carbon $date = null,
        public ?string $memo = null,
        public ?string $reference = null,
        public EntryType $type = EntryType::Standard,
        public bool $draft = false,
        public ?int $branchId = null,
        public ?Model $source = null,
        public ?Model $creator = null,
        public ?string $idempotencyKey = null,
        public ?Carbon $reverseOn = null,
        public ?array $metadata = null,
        /** @internal set by reversals */
        public ?int $reversesId = null,
        /** @internal year-end close and its reversal post into closed periods */
        public bool $allowClosedPeriod = false,
    ) {}

    public static function fromArray(array $data): static
    {
        $type = $data['type'] ?? EntryType::Standard;

        return new self(
            date: isset($data['date']) ? Carbon::parse($data['date'])->startOfDay() : null,
            memo: $data['memo'] ?? null,
            reference: $data['reference'] ?? null,
            type: $type instanceof EntryType ? $type : (EntryType::tryFrom((string) $type) ?? EntryType::Standard),
            draft: ($data['draft'] ?? false) || ($data['status'] ?? null) === 'draft',
            branchId: isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            source: $data['source'] ?? null,
            creator: $data['creator'] ?? null,
            idempotencyKey: $data['idempotency_key'] ?? null,
            reverseOn: isset($data['reverse_on']) ? Carbon::parse($data['reverse_on'])->startOfDay() : null,
            metadata: $data['metadata'] ?? null,
            reversesId: $data['reverses_id'] ?? null,
            allowClosedPeriod: (bool) ($data['allow_closed_period'] ?? false),
        );
    }

    /**
     * The entry date, defaulting to today.
     */
    public function date(): Carbon
    {
        return ($this->date ?? now())->copy()->startOfDay();
    }
}
