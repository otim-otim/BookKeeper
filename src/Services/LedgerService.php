<?php

namespace Otimdibossman\BookKeeper\Services;

use Illuminate\Support\Facades\DB;
use Otimdibossman\BookKeeper\Exceptions\AccountNotFoundException;
use Otimdibossman\BookKeeper\Exceptions\UnbalancedEntryException;
use Otimdibossman\BookKeeper\Models\Account;
use Otimdibossman\BookKeeper\Models\JournalEntry;
use Otimdibossman\BookKeeper\Models\Transaction;

class LedgerService
{
    public function __construct(protected AccountService $accountService) {}

    /**
     * Record a journal entry containing multiple debit/credit lines.
     *
     * The `$lines` array should contain entries like:
     * [
     *     ['account' => '1000', 'type' => 'debit',  'amount' => 500.00, 'memo' => '...'],
     *     ['account' => '4000', 'type' => 'credit', 'amount' => 500.00, 'memo' => '...'],
     * ]
     *
     * `account` can be an account code (string), an account ID (int), or an Account model.
     *
     * @param  array $lines   Array of transaction lines
     * @param  array $options Entry metadata: date, memo, reference, currency, metadata
     * @throws UnbalancedEntryException
     * @throws AccountNotFoundException
     */
    public function record(array $lines, array $options = []): JournalEntry
    {
        $this->validateLines($lines);

        return DB::transaction(function () use ($lines, $options) {
            $entry = JournalEntry::create([
                'date'      => $options['date'] ?? now()->toDateString(),
                'memo'      => $options['memo'] ?? null,
                'reference' => $options['reference'] ?? null,
                'currency'  => $options['currency'] ?? 'USD',
                'metadata'  => $options['metadata'] ?? null,
            ]);

            foreach ($lines as $line) {
                $account = $this->resolveAccount($line['account']);

                Transaction::create([
                    'journal_entry_id' => $entry->id,
                    'account_id'       => $account->id,
                    'type'             => $line['type'],
                    'amount'           => $line['amount'],
                    'memo'             => $line['memo'] ?? null,
                ]);
            }

            return $entry->load('transactions.account');
        });
    }

    /**
     * Convenience: debit one account and credit another by a given amount.
     *
     * @param  Account|int|string $debitAccount   The account to debit
     * @param  Account|int|string $creditAccount  The account to credit
     * @param  float              $amount
     * @param  array              $options        Entry metadata
     */
    public function transfer(
        Account|int|string $debitAccount,
        Account|int|string $creditAccount,
        float $amount,
        array $options = []
    ): JournalEntry {
        return $this->record([
            ['account' => $debitAccount,  'type' => 'debit',  'amount' => $amount, 'memo' => $options['debit_memo'] ?? null],
            ['account' => $creditAccount, 'type' => 'credit', 'amount' => $amount, 'memo' => $options['credit_memo'] ?? null],
        ], $options);
    }

    /**
     * Get all journal entries, optionally filtered by date range or reference.
     */
    public function entries(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = JournalEntry::with('transactions.account')
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');

        if (isset($filters['from'])) {
            $query->whereDate('date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('date', '<=', $filters['to']);
        }
        if (isset($filters['reference'])) {
            $query->where('reference', $filters['reference']);
        }

        return $query->get();
    }

    /**
     * Validate that the lines array is balanced (debits == credits).
     *
     * @throws UnbalancedEntryException
     */
    protected function validateLines(array $lines): void
    {
        $debits  = 0.0;
        $credits = 0.0;

        foreach ($lines as $line) {
            if (strtolower($line['type']) === 'debit') {
                $debits += (float) $line['amount'];
            } else {
                $credits += (float) $line['amount'];
            }
        }

        if (abs($debits - $credits) >= 0.0001) {
            throw new UnbalancedEntryException($debits, $credits);
        }
    }

    /**
     * Resolve an Account from a model, ID, or code string.
     */
    protected function resolveAccount(Account|int|string $account): Account
    {
        if ($account instanceof Account) {
            return $account;
        }

        return is_int($account)
            ? $this->accountService->findById($account)
            : $this->accountService->findByCode($account);
    }
}
