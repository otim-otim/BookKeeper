<?php

namespace OtimOtim\BookKeeper\Enums;

enum AccountType: string
{
    case Asset     = 'asset';
    case Liability = 'liability';
    case Equity    = 'equity';
    case Revenue   = 'revenue';
    case Expense   = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset     => 'Asset',
            self::Liability => 'Liability',
            self::Equity    => 'Equity',
            self::Revenue   => 'Revenue',
            self::Expense   => 'Expense',
        };
    }

    /**
     * Asset and Expense accounts have a normal DEBIT balance.
     * Liability, Equity, and Revenue accounts have a normal CREDIT balance.
     */
    public function normalBalance(): string
    {
        return match ($this) {
            self::Asset, self::Expense => 'debit',
            self::Liability, self::Equity, self::Revenue => 'credit',
        };
    }

    /**
     * On the Balance Sheet: Assets, Liabilities, Equity.
     * On the Income Statement: Revenue, Expense.
     */
    public function statement(): string
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => 'balance_sheet',
            self::Revenue, self::Expense => 'income_statement',
        };
    }
}
