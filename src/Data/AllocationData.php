<?php

namespace OtimOtim\BookKeeper\Data;

use OtimOtim\BookKeeper\Models\Document;
use OtimOtim\BookKeeper\Support\Money;

/**
 * How much of a payment settles one document. A null amount settles as
 * much as the document has outstanding (up to what is left of the payment).
 */
final readonly class AllocationData extends Data
{
    public function __construct(
        public Document|int $document,
        public Money|int|float|string|null $amount = null,
    ) {}

    /**
     * Accepts [$document, $amount], ['document' => .., 'amount' => ..].
     */
    public static function fromArray(array $data): static
    {
        return new self($data['document'] ?? $data[0], $data['amount'] ?? $data[1] ?? null);
    }

    public static function from(Data|array|Document $data): static
    {
        return $data instanceof Document ? new self($data) : parent::from($data);
    }
}
