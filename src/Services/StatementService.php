<?php

namespace OtimOtim\BookKeeper\Services;

use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Models\Account;

class StatementService
{
    public function __construct(protected AccountService $accountService) {}

    // -----------------------------------------------------------------------
    // Balance Sheet
    // -----------------------------------------------------------------------

    /**
     * Generate a Balance Sheet as of a given date.
     *
     * Returns:
     * [
     *   'date'        => '2024-12-31',
     *   'assets'      => [ ['code'=>'1000', 'name'=>'Cash', 'balance'=>5000.00], ... ],
     *   'liabilities' => [ ... ],
     *   'equity'      => [ ... ],
     *   'totals'      => ['assets' => 5000, 'liabilities' => 2000, 'equity' => 3000],
     *   'balanced'    => true,
     * ]
     */
    public function balanceSheet(?string $asOfDate = null): array
    {
        $date = $asOfDate ?? now()->toDateString();

        $assets      = $this->accountBalances(AccountType::Asset, $date);
        $liabilities = $this->accountBalances(AccountType::Liability, $date);
        $equity      = $this->accountBalances(AccountType::Equity, $date);

        $totalAssets      = array_sum(array_column($assets, 'balance'));
        $totalLiabilities = array_sum(array_column($liabilities, 'balance'));
        $totalEquity      = array_sum(array_column($equity, 'balance'));

        return [
            'date'        => $date,
            'assets'      => $assets,
            'liabilities' => $liabilities,
            'equity'      => $equity,
            'totals'      => [
                'assets'            => $totalAssets,
                'liabilities'       => $totalLiabilities,
                'equity'            => $totalEquity,
                'liabilities_equity' => $totalLiabilities + $totalEquity,
            ],
            'balanced'    => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.0001,
        ];
    }

    // -----------------------------------------------------------------------
    // Income Statement (Profit & Loss)
    // -----------------------------------------------------------------------

    /**
     * Generate an Income Statement for a date range.
     *
     * Returns:
     * [
     *   'from'        => '2024-01-01',
     *   'to'          => '2024-12-31',
     *   'revenue'     => [ ... ],
     *   'expenses'    => [ ... ],
     *   'totals'      => ['revenue' => 50000, 'expenses' => 30000, 'net_income' => 20000],
     * ]
     */
    public function incomeStatement(?string $from = null, ?string $to = null): array
    {
        $from = $from ?? now()->startOfYear()->toDateString();
        $to   = $to ?? now()->toDateString();

        $revenue  = $this->accountBalancesInRange(AccountType::Revenue, $from, $to);
        $expenses = $this->accountBalancesInRange(AccountType::Expense, $from, $to);

        $totalRevenue  = array_sum(array_column($revenue, 'balance'));
        $totalExpenses = array_sum(array_column($expenses, 'balance'));

        return [
            'from'     => $from,
            'to'       => $to,
            'revenue'  => $revenue,
            'expenses' => $expenses,
            'totals'   => [
                'revenue'    => $totalRevenue,
                'expenses'   => $totalExpenses,
                'net_income' => $totalRevenue - $totalExpenses,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Trial Balance
    // -----------------------------------------------------------------------

    /**
     * Generate a Trial Balance as of a given date.
     *
     * Returns a list of all accounts with their debit or credit balance,
     * and confirms the total debits == total credits.
     */
    public function trialBalance(?string $asOfDate = null): array
    {
        $date = $asOfDate ?? now()->toDateString();

        $accounts = Account::active()
            ->with(['transactions' => fn($q) => $q->whereHas('journalEntry', fn($q2) => $q2->whereDate('date', '<=', $date))])
            ->orderBy('code')
            ->get();

        $rows = [];
        $totalDebits  = 0.0;
        $totalCredits = 0.0;

        foreach ($accounts as $account) {
            $debits  = (float) $account->transactions->where('type', 'debit')->sum('amount');
            $credits = (float) $account->transactions->where('type', 'credit')->sum('amount');

            $rows[] = [
                'code'    => $account->code,
                'name'    => $account->name,
                'type'    => $account->type->value,
                'debits'  => $debits,
                'credits' => $credits,
            ];

            $totalDebits  += $debits;
            $totalCredits += $credits;
        }

        return [
            'date'     => $date,
            'accounts' => $rows,
            'totals'   => [
                'debits'  => $totalDebits,
                'credits' => $totalCredits,
            ],
            'balanced' => abs($totalDebits - $totalCredits) < 0.0001,
        ];
    }

    // -----------------------------------------------------------------------
    // General Ledger
    // -----------------------------------------------------------------------

    /**
     * Generate a General Ledger report for a specific account.
     *
     * Returns all transactions for the account ordered by date.
     */
    public function generalLedger(Account $account, ?string $from = null, ?string $to = null): array
    {
        $query = $account->transactions()
            ->with('journalEntry')
            ->whereHas('journalEntry', function ($q) use ($from, $to) {
                if ($from) {
                    $q->whereDate('date', '>=', $from);
                }
                if ($to) {
                    $q->whereDate('date', '<=', $to);
                }
            })
            ->orderBy('created_at');

        $transactions = $query->get()->map(function ($tx) {
            return [
                'date'      => $tx->journalEntry->date->toDateString(),
                'reference' => $tx->journalEntry->reference,
                'memo'      => $tx->memo ?? $tx->journalEntry->memo,
                'debit'     => $tx->type === 'debit'  ? $tx->amount : 0.0,
                'credit'    => $tx->type === 'credit' ? $tx->amount : 0.0,
            ];
        })->toArray();

        return [
            'account'      => [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
            ],
            'from'         => $from,
            'to'           => $to,
            'transactions' => $transactions,
            'totals'       => [
                'debits'  => array_sum(array_column($transactions, 'debit')),
                'credits' => array_sum(array_column($transactions, 'credit')),
                'balance' => $account->balanceAsOf($to ?? now()->toDateString()),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    protected function accountBalances(AccountType $type, string $asOfDate): array
    {
        return Account::active()
            ->ofType($type)
            ->orderBy('code')
            ->get()
            ->map(fn(Account $a) => [
                'code'        => $a->code,
                'name'        => $a->name,
                'description' => $a->description,
                'balance'     => $a->balanceAsOf($asOfDate),
            ])
            ->toArray();
    }

    protected function accountBalancesInRange(AccountType $type, string $from, string $to): array
    {
        return Account::active()
            ->ofType($type)
            ->orderBy('code')
            ->get()
            ->map(function (Account $a) use ($from, $to) {
                $txs = $a->transactions()
                    ->whereHas('journalEntry', fn($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to));

                $debits  = (float) (clone $txs)->where('type', 'debit')->sum('amount');
                $credits = (float) (clone $txs)->where('type', 'credit')->sum('amount');

                return [
                    'code'        => $a->code,
                    'name'        => $a->name,
                    'description' => $a->description,
                    'balance'     => match ($a->type) {
                        AccountType::Asset, AccountType::Expense   => $debits - $credits,
                        AccountType::Liability, AccountType::Equity,
                        AccountType::Revenue                       => $credits - $debits,
                    },
                ];
            })
            ->toArray();
    }
}
