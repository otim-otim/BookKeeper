<?php

namespace Otimdibossman\BookKeeper;

use Otimdibossman\BookKeeper\Services\AccountService;
use Otimdibossman\BookKeeper\Services\LedgerService;
use Otimdibossman\BookKeeper\Services\StatementService;
use Otimdibossman\BookKeeper\Models\Account;
use Otimdibossman\BookKeeper\Models\JournalEntry;
use Otimdibossman\BookKeeper\Enums\AccountType;

/**
 * Main BookKeeperManager class — the class behind the BookKeeper facade.
 *
 * All public methods delegate to the specialised services so that
 * callers only need one entry-point to the package.
 */
class BookKeeperManager
{
    public function __construct(
        public readonly AccountService   $accounts,
        public readonly LedgerService    $ledger,
        public readonly StatementService $statements,
    ) {}

    // -----------------------------------------------------------------------
    // Account management
    // -----------------------------------------------------------------------

    public function createAccount(string $code, string $name, AccountType $type, array $options = []): Account
    {
        return $this->accounts->create($code, $name, $type, $options);
    }

    public function account(int|string $codeOrId): Account
    {
        return is_int($codeOrId)
            ? $this->accounts->findById($codeOrId)
            : $this->accounts->findByCode($codeOrId);
    }

    public function balance(Account|int|string $account): float
    {
        return $this->accounts->balance($account);
    }

    public function balanceAsOf(Account|int|string $account, \DateTimeInterface|string $date): float
    {
        return $this->accounts->balanceAsOf($account, $date);
    }

    // -----------------------------------------------------------------------
    // Ledger / journal entries
    // -----------------------------------------------------------------------

    /**
     * Record a double-entry journal entry.
     *
     * @param  array $lines   e.g. [['account'=>'1000','type'=>'debit','amount'=>100], ...]
     * @param  array $options e.g. ['date'=>'2024-01-01', 'memo'=>'Sale', 'reference'=>'INV-001']
     */
    public function record(array $lines, array $options = []): JournalEntry
    {
        return $this->ledger->record($lines, $options);
    }

    /**
     * Convenience: debit one account and credit another.
     */
    public function transfer(
        Account|int|string $debitAccount,
        Account|int|string $creditAccount,
        float $amount,
        array $options = []
    ): JournalEntry {
        return $this->ledger->transfer($debitAccount, $creditAccount, $amount, $options);
    }

    // -----------------------------------------------------------------------
    // Financial statements
    // -----------------------------------------------------------------------

    public function balanceSheet(?string $asOfDate = null): array
    {
        return $this->statements->balanceSheet($asOfDate);
    }

    public function incomeStatement(?string $from = null, ?string $to = null): array
    {
        return $this->statements->incomeStatement($from, $to);
    }

    public function trialBalance(?string $asOfDate = null): array
    {
        return $this->statements->trialBalance($asOfDate);
    }

    public function generalLedger(Account $account, ?string $from = null, ?string $to = null): array
    {
        return $this->statements->generalLedger($account, $from, $to);
    }
}
