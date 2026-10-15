<?php

namespace OtimOtim\BookKeeper\Data;

use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\TaxRate;
use OtimOtim\BookKeeper\Support\Money;

/**
 * A line on an invoice, credit note, bill or debit note: quantity ×
 * unit price (or a single amount), less discount, plus tax.
 */
final readonly class DocumentLineData extends Data
{
    public function __construct(
        public Account|int|string $account,
        public Money|int|float|string $unitPrice,
        public string $quantity = '1',
        public Money|int|float|string $discount = 0,
        public TaxRate|int|string|null $tax = null,
        public ?string $description = null,
    ) {}

    /**
     * Keys: account, description, quantity + unit_price (or amount), discount, tax.
     */
    public static function fromArray(array $data): static
    {
        if (! isset($data['account'])) {
            throw new InvalidOperationException('Every document line needs an account.');
        }

        $hasAmount = isset($data['amount']);
        if (! $hasAmount && ! isset($data['unit_price'])) {
            throw new InvalidOperationException('A document line needs unit_price or amount.');
        }

        return new self(
            account: $data['account'],
            unitPrice: $hasAmount ? $data['amount'] : $data['unit_price'],
            quantity: $hasAmount ? '1' : (string) ($data['quantity'] ?? '1'),
            discount: $data['discount'] ?? 0,
            tax: $data['tax'] ?? null,
            description: $data['description'] ?? null,
        );
    }
}
