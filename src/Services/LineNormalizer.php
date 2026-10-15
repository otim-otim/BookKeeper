<?php

namespace OtimOtim\BookKeeper\Services;

use OtimOtim\BookKeeper\Data\EntryData;
use OtimOtim\BookKeeper\Data\LineData;
use OtimOtim\BookKeeper\Enums\Side;
use OtimOtim\BookKeeper\Exceptions\InvalidEntryException;
use OtimOtim\BookKeeper\Exceptions\UnbalancedEntryException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\TaxRate;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Validates journal lines and turns them into journal_lines rows: resolves
 * accounts and tax rates, converts foreign amounts to base currency, drops
 * zero lines, and checks the entry balances.
 */
class LineNormalizer
{
    public function __construct(
        protected AccountService $accounts,
        protected ExchangeRateService $rates,
    ) {}

    /**
     * @param  list<LineData>  $lines
     * @return list<array<string, mixed>> journal_lines attributes
     *
     * @throws InvalidEntryException
     * @throws UnbalancedEntryException
     */
    public function normalize(array $lines, EntryData $entry): array
    {
        $rows = [];
        $debits = Money::zero();
        $credits = Money::zero();

        foreach ($lines as $i => $line) {
            $account = $this->postableAccount($line->account);
            [$base, $foreign] = $this->amounts($line, $account, $entry, $i);

            if ($base->isNegative()) {
                throw new InvalidEntryException("Line {$i} has a negative amount; use the opposite side instead.");
            }

            if ($base->isZero()) {
                continue;
            }

            if ($account->is_control && $line->subledgerType() === null && config('bookkeeper.journal.enforce_subledgers', true)) {
                throw new InvalidEntryException("Account [{$account->code}] is a control account; line {$i} must name a subledger party.");
            }

            $rows[] = [
                'account_id' => $account->id,
                'debit' => $line->side === Side::Debit ? $base->minor() : 0,
                'credit' => $line->side === Side::Credit ? $base->minor() : 0,
                'memo' => $line->memo,
                'branch_id' => $line->branchId,
                'subledger_type' => $line->subledgerType(),
                'subledger_id' => $line->subledgerId(),
                'currency' => $foreign['currency'],
                'foreign_amount' => $foreign['amount'],
                'exchange_rate' => $foreign['rate'],
                'tax_rate_id' => $this->taxRateId($line->taxRate, $entry),
                'taxable_amount' => $line->taxableAmount === null ? null : Money::of($line->taxableAmount)->minor(),
            ];

            $line->side === Side::Debit ? $debits = $debits->plus($base) : $credits = $credits->plus($base);
        }

        if ($rows === []) {
            throw new InvalidEntryException('A journal entry needs at least one non-zero line.');
        }

        if (! $debits->equals($credits)) {
            throw new UnbalancedEntryException($debits, $credits);
        }

        return $rows;
    }

    protected function postableAccount(Account|int|string $reference): Account
    {
        $account = $this->accounts->resolve($reference);

        if (! $account->is_active) {
            throw new InvalidEntryException("Account [{$account->code}] is inactive.");
        }

        if ($account->is_group) {
            throw new InvalidEntryException("Account [{$account->code}] is a group account and cannot be posted to.");
        }

        return $account;
    }

    /**
     * The base-currency amount, and the foreign-currency details to store.
     *
     * @return array{0: Money, 1: array{currency: ?string, amount: ?int, rate: ?string}}
     */
    protected function amounts(LineData $line, Account $account, EntryData $entry, int $i): array
    {
        $currency = $line->currency === Currency::base() ? null : $line->currency;
        $none = ['currency' => null, 'amount' => null, 'rate' => null];

        if ($account->isForeignCurrency() && $currency !== $account->currency) {
            throw new InvalidEntryException("Account [{$account->code}] is held in {$account->currency}; lines must be in that currency.");
        }

        if ($currency === null) {
            $base = Money::of($line->amount);

            if ($base->currency() !== Currency::base()) {
                throw new InvalidEntryException("Line {$i} is in {$base->currency()}; pass 'currency' so it can be converted to ".Currency::base().'.');
            }

            return [$base, $none];
        }

        // Reversals copy the original line exactly.
        if ($line->foreignAmount !== null) {
            return [Money::of($line->amount), ['currency' => $currency, 'amount' => $line->foreignAmount, 'rate' => $line->exchangeRate]];
        }

        $amount = $line->amount instanceof Money ? $line->amount->amount() : $line->amount;
        $foreign = Money::of($amount, $currency);
        $rate = $line->exchangeRate ?? $this->rates->rate($currency, $entry->date());
        $base = $line->baseAmount !== null ? Money::of($line->baseAmount) : $foreign->convertTo(Currency::base(), $rate);
        $signed = $line->side === Side::Debit ? $foreign->minor() : -$foreign->minor();

        return [$base, ['currency' => $currency, 'amount' => $signed, 'rate' => (string) $rate]];
    }

    protected function taxRateId(TaxRate|int|string|null $taxRate, EntryData $entry): ?int
    {
        return match (true) {
            $taxRate instanceof TaxRate => $taxRate->id,
            is_int($taxRate) => $taxRate,
            is_string($taxRate) => TaxRate::query()->effectiveOn($entry->date())->where('code', $taxRate)->value('id')
                ?? throw new InvalidEntryException("Tax rate [{$taxRate}] not found."),
            default => null,
        };
    }
}
