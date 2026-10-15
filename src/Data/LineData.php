<?php

namespace OtimOtim\BookKeeper\Data;

use Illuminate\Database\Eloquent\Model;
use OtimOtim\BookKeeper\Enums\Side;
use OtimOtim\BookKeeper\Exceptions\InvalidEntryException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\TaxRate;
use OtimOtim\BookKeeper\Support\AccountReference;
use OtimOtim\BookKeeper\Support\Money;

/**
 * One debit or credit line of a journal entry.
 *
 * Amounts are Money or major-unit numbers. With a foreign $currency the
 * amount is in that currency and converted to base at $exchangeRate (or the
 * stored rate for the entry date); $baseAmount fixes the converted value
 * when a caller splits a converted total and needs the parts to add up.
 */
final readonly class LineData extends Data
{
    public function __construct(
        public Account|int|string $account,
        public Side $side,
        public Money|int|float|string $amount,
        public ?string $memo = null,
        public ?int $branchId = null,
        public ?Model $subledger = null,
        public ?string $subledgerType = null,
        public int|string|null $subledgerId = null,
        public ?string $currency = null,
        public ?string $exchangeRate = null,
        public Money|int|float|string|null $baseAmount = null,
        public TaxRate|int|string|null $taxRate = null,
        public Money|int|float|string|null $taxableAmount = null,
        /** @internal reversals copy the original foreign amount verbatim */
        public ?int $foreignAmount = null,
    ) {}

    public static function debit(Account|int|string $account, Money|int|float|string $amount, mixed ...$extra): self
    {
        return new self($account, Side::Debit, $amount, ...$extra);
    }

    public static function credit(Account|int|string $account, Money|int|float|string $amount, mixed ...$extra): self
    {
        return new self($account, Side::Credit, $amount, ...$extra);
    }

    /**
     * A line from a signed amount: positive debits, negative credits.
     */
    public static function signed(Account|int|string $account, Money $debitMinusCredit, mixed ...$extra): self
    {
        return new self($account, $debitMinusCredit->isNegative() ? Side::Credit : Side::Debit, $debitMinusCredit->abs(), ...$extra);
    }

    /**
     * A line for an amount in a document or payment currency whose base
     * value has already been worked out.
     */
    public static function converted(Account|int|string $account, Side $side, Money $amount, Money $base, string $rate, ?string $memo = null, mixed ...$extra): self
    {
        $foreign = $amount->currency() !== $base->currency();

        return new self(...array_merge([
            'account' => $account,
            'side' => $side,
            'amount' => $foreign ? $amount : $base,
            'memo' => $memo,
            'currency' => $foreign ? $amount->currency() : null,
            'exchangeRate' => $foreign ? $rate : null,
            'baseAmount' => $foreign ? $base : null,
        ], $extra));
    }

    /**
     * Accepts the array forms documented in the README:
     * ['account' => .., 'debit' => 100], ['account' => .., 'credit' => 100],
     * ['account' => .., 'type' => 'debit', 'amount' => 100] (v1), and
     * ['role' => 'cash', ...] instead of 'account'.
     */
    public static function fromArray(array $data): static
    {
        [$side, $amount] = self::sideAndAmount($data);

        $account = $data['account']
            ?? (isset($data['role']) ? AccountReference::fromConfig($data['role']) : throw new InvalidEntryException('A journal line has no account.'));

        return new self(
            account: $account,
            side: $side,
            amount: $amount,
            memo: $data['memo'] ?? null,
            branchId: isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            subledger: $data['subledger'] ?? null,
            subledgerType: $data['subledger_type'] ?? null,
            subledgerId: $data['subledger_id'] ?? null,
            currency: isset($data['currency']) ? strtoupper($data['currency']) : null,
            exchangeRate: isset($data['exchange_rate']) ? (string) $data['exchange_rate'] : null,
            baseAmount: $data['base_amount'] ?? null,
            taxRate: $data['tax_rate'] ?? null,
            taxableAmount: $data['taxable_amount'] ?? null,
            foreignAmount: $data['foreign_amount'] ?? null,
        );
    }

    public function subledgerType(): ?string
    {
        return $this->subledger?->getMorphClass() ?? $this->subledgerType;
    }

    public function subledgerId(): int|string|null
    {
        return $this->subledger?->getKey() ?? $this->subledgerId;
    }

    /**
     * @return array{0: Side, 1: Money|int|float|string}
     */
    private static function sideAndAmount(array $data): array
    {
        $isSet = fn (string $key) => isset($data[$key]) && ! self::isZero($data[$key]);

        if ($isSet('debit') && $isSet('credit')) {
            throw new InvalidEntryException('A journal line has both a debit and a credit.');
        }

        foreach ([Side::Debit, Side::Credit] as $side) {
            if ($isSet($side->value)) {
                return [$side, $data[$side->value]];
            }
        }

        if (isset($data['type'])) {
            $side = $data['type'] instanceof Side ? $data['type'] : Side::tryFrom(strtolower((string) $data['type']));

            return $side
                ? [$side, $data['amount'] ?? 0]
                : throw new InvalidEntryException("Invalid line type [{$data['type']}]; use 'debit' or 'credit'.");
        }

        // A zero amount: keep the side it was given on.
        return [array_key_exists('credit', $data) ? Side::Credit : Side::Debit, $data['debit'] ?? $data['credit'] ?? 0];
    }

    private static function isZero(mixed $amount): bool
    {
        return $amount instanceof Money ? $amount->isZero() : (is_numeric($amount) && (float) $amount == 0.0);
    }
}
