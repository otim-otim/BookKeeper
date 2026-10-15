<?php

namespace OtimOtim\BookKeeper\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\DocumentType;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Contact;

/**
 * An invoice, credit note, bill or debit note to create.
 */
final readonly class DocumentData extends Data
{
    /**
     * @param  list<DocumentLineData>  $lines
     */
    public function __construct(
        public DocumentType $type,
        public Contact|Model|int $contact,
        public array $lines,
        public ?Carbon $issueDate = null,
        public ?Carbon $dueDate = null,
        public ?int $paymentTermsDays = null,
        public ?string $currency = null,
        public ?string $exchangeRate = null,
        public bool $taxInclusive = false,
        public ?bool $cashRounding = null,
        public ?string $number = null,
        public ?string $reference = null,
        public ?string $notes = null,
        public ?int $branchId = null,
        public ?Model $source = null,
        public ?array $metadata = null,
        public bool $draft = false,
        public ?string $idempotencyKey = null,
    ) {
        if ($lines === []) {
            throw new InvalidOperationException('A document needs at least one line.');
        }
    }

    /**
     * Keys: type, contact, lines, plus the options listed in the README
     * (issue_date, due_date, currency, tax_inclusive, cash_rounding, ...).
     */
    public static function fromArray(array $data): static
    {
        $type = $data['type'] instanceof DocumentType ? $data['type'] : DocumentType::from($data['type']);

        return new self(
            type: $type,
            contact: $data['contact'],
            lines: DocumentLineData::collect($data['lines'] ?? []),
            issueDate: isset($data['issue_date']) ? Carbon::parse($data['issue_date'])->startOfDay() : null,
            dueDate: isset($data['due_date']) ? Carbon::parse($data['due_date'])->startOfDay() : null,
            paymentTermsDays: isset($data['payment_terms_days']) ? (int) $data['payment_terms_days'] : null,
            currency: isset($data['currency']) ? strtoupper($data['currency']) : null,
            exchangeRate: isset($data['exchange_rate']) ? (string) $data['exchange_rate'] : null,
            taxInclusive: (bool) ($data['tax_inclusive'] ?? false),
            cashRounding: isset($data['cash_rounding']) ? (bool) $data['cash_rounding'] : null,
            number: $data['number'] ?? null,
            reference: $data['reference'] ?? null,
            notes: $data['notes'] ?? null,
            branchId: isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            source: $data['source'] ?? null,
            metadata: $data['metadata'] ?? null,
            draft: (bool) ($data['draft'] ?? false),
            idempotencyKey: $data['idempotency_key'] ?? null,
        );
    }
}
