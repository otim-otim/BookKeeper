<?php

namespace OtimOtim\BookKeeper\Enums;

use OtimOtim\BookKeeper\Support\Money;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Revenue => 'Revenue',
            self::Expense => 'Expense',
        };
    }

    /**
     * Asset and Expense accounts have a normal DEBIT balance.
     * Liability, Equity, and Revenue accounts have a normal CREDIT balance.
     */
    public function normalBalance(): Side
    {
        return match ($this) {
            self::Asset, self::Expense => Side::Debit,
            self::Liability, self::Equity, self::Revenue => Side::Credit,
        };
    }

    public function isDebitNormal(): bool
    {
        return $this->normalBalance() === Side::Debit;
    }

    /**
     * Signed balance (in normal-balance terms) from raw debit and credit totals.
     */
    public function balanceFrom(int $debits, int $credits): int
    {
        return $this->isDebitNormal() ? $debits - $credits : $credits - $debits;
    }

    /**
     * balanceFrom() as Money in the base currency.
     */
    public function balance(int $debits, int $credits): Money
    {
        return Money::ofMinor($this->balanceFrom($debits, $credits));
    }

    public function statement(): string
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => 'balance_sheet',
            self::Revenue, self::Expense => 'income_statement',
        };
    }

    public function isBalanceSheet(): bool
    {
        return $this->statement() === 'balance_sheet';
    }

    /**
     * Leading digit used by the standard numbering convention.
     */
    public function codePrefix(): string
    {
        return match ($this) {
            self::Asset => '1',
            self::Liability => '2',
            self::Equity => '3',
            self::Revenue => '4',
            self::Expense => '5',
        };
    }
}
