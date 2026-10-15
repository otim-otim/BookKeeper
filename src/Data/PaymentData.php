<?php

namespace OtimOtim\BookKeeper\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\PaymentDirection;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\Contact;
use OtimOtim\BookKeeper\Models\TaxRate;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Money received from a customer or paid to a vendor.
 */
final readonly class PaymentData extends Data
{
    /**
     * @param  list<AllocationData>  $allocations
     * @param  ?string  $ledger  'sales' or 'purchases', for payments not allocated to documents
     */
    public function __construct(
        public PaymentDirection $direction,
        public Contact|Model|int $contact,
        public Money|int|float|string $amount,
        public Account|int|string $account,
        public ?Carbon $date = null,
        public ?string $currency = null,
        public ?string $exchangeRate = null,
        public array $allocations = [],
        public TaxRate|int|string|null $withholding = null,
        public Money|int|float|string|null $withholdingAmount = null,
        public ?string $ledger = null,
        public ?string $method = null,
        public ?string $reference = null,
        public ?string $memo = null,
        public ?string $number = null,
        public ?int $branchId = null,
        public ?Model $source = null,
        public ?array $metadata = null,
        public ?string $idempotencyKey = null,
    ) {}

    public static function fromArray(array $data): static
    {
        $direction = $data['direction'] instanceof PaymentDirection ? $data['direction'] : PaymentDirection::from($data['direction']);

        return new self(
            direction: $direction,
            contact: $data['contact'],
            amount: $data['amount'],
            account: $data['account'],
            date: isset($data['date']) ? Carbon::parse($data['date'])->startOfDay() : null,
            currency: isset($data['currency']) ? strtoupper($data['currency']) : null,
            exchangeRate: isset($data['exchange_rate']) ? (string) $data['exchange_rate'] : null,
            allocations: AllocationData::collect($data['allocations'] ?? []),
            withholding: $data['withholding'] ?? null,
            withholdingAmount: $data['withholding_amount'] ?? null,
            ledger: $data['ledger'] ?? null,
            method: $data['method'] ?? null,
            reference: $data['reference'] ?? null,
            memo: $data['memo'] ?? null,
            number: $data['number'] ?? null,
            branchId: isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            source: $data['source'] ?? null,
            metadata: $data['metadata'] ?? null,
            idempotencyKey: $data['idempotency_key'] ?? null,
        );
    }

    /**
     * The payment currency: explicit, from a Money amount, the contact's, or base.
     */
    public function currency(?string $contactCurrency, string $base): string
    {
        return strtoupper($this->currency ?? ($this->amount instanceof Money ? $this->amount->currency() : ($contactCurrency ?? $base)));
    }
}
