<?php

namespace OtimOtim\BookKeeper;

use Illuminate\Database\Eloquent\Model;
use OtimOtim\BookKeeper\Enums\AccountSubtype;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\Contact;
use OtimOtim\BookKeeper\Models\Document;
use OtimOtim\BookKeeper\Models\JournalEntry;
use OtimOtim\BookKeeper\Models\Payment;
use OtimOtim\BookKeeper\Services;
use OtimOtim\BookKeeper\Support\Money;

/**
 * The class behind the BookKeeper facade: one entry point to the whole
 * accounting engine. Common operations are methods here; every module is
 * also reachable through its service accessor (accounts(), ledger(), ...).
 */
class BookKeeperManager
{
    public function __construct(protected \Illuminate\Contracts\Container\Container $app) {}

    // -----------------------------------------------------------------------
    // Services
    // -----------------------------------------------------------------------

    public function accounts(): Services\AccountService
    {
        return $this->app->make(Services\AccountService::class);
    }

    public function chart(): Services\ChartService
    {
        return $this->app->make(Services\ChartService::class);
    }

    public function ledger(): Services\LedgerService
    {
        return $this->app->make(Services\LedgerService::class);
    }

    public function balances(): Services\BalanceService
    {
        return $this->app->make(Services\BalanceService::class);
    }

    public function periods(): Services\PeriodService
    {
        return $this->app->make(Services\PeriodService::class);
    }

    public function statements(): Services\StatementService
    {
        return $this->app->make(Services\StatementService::class);
    }

    public function subledgers(): Services\SubledgerService
    {
        return $this->app->make(Services\SubledgerService::class);
    }

    public function templates(): Services\TemplateService
    {
        return $this->app->make(Services\TemplateService::class);
    }

    public function taxes(): Services\TaxService
    {
        return $this->app->make(Services\TaxService::class);
    }

    public function exchangeRates(): Services\ExchangeRateService
    {
        return $this->app->make(Services\ExchangeRateService::class);
    }

    public function contacts(): Services\ContactService
    {
        return $this->app->make(Services\ContactService::class);
    }

    public function documents(): Services\DocumentService
    {
        return $this->app->make(Services\DocumentService::class);
    }

    public function payments(): Services\PaymentService
    {
        return $this->app->make(Services\PaymentService::class);
    }

    public function aging(): Services\AgingService
    {
        return $this->app->make(Services\AgingService::class);
    }

    public function assets(): Services\FixedAssetService
    {
        return $this->app->make(Services\FixedAssetService::class);
    }

    public function budgets(): Services\BudgetService
    {
        return $this->app->make(Services\BudgetService::class);
    }

    public function tills(): Services\TillService
    {
        return $this->app->make(Services\TillService::class);
    }

    public function bank(): Services\BankReconciliationService
    {
        return $this->app->make(Services\BankReconciliationService::class);
    }

    public function appropriation(): Services\AppropriationService
    {
        return $this->app->make(Services\AppropriationService::class);
    }

    public function dividends(): Services\DividendService
    {
        return $this->app->make(Services\DividendService::class);
    }

    public function close(): Services\CloseService
    {
        return $this->app->make(Services\CloseService::class);
    }

    // -----------------------------------------------------------------------
    // Chart of accounts
    // -----------------------------------------------------------------------

    public function createAccount(string $code, string $name, AccountType|AccountSubtype|string $type, array $options = []): Account
    {
        return $this->accounts()->create($code, $name, $type, $options);
    }

    /**
     * Find an account by model, ID, code, or "@role".
     */
    public function account(Account|int|string $account): Account
    {
        return $this->accounts()->resolve($account);
    }

    public function balance(Account|int|string $account, ?int $branchId = null): Money
    {
        return $this->accounts()->balance($account, $branchId);
    }

    public function balanceAsOf(Account|int|string $account, \DateTimeInterface|string $date, ?int $branchId = null): Money
    {
        return $this->accounts()->balanceAsOf($account, $date, $branchId);
    }

    // -----------------------------------------------------------------------
    // Journal
    // -----------------------------------------------------------------------

    /**
     * Record (and by default post) a balanced journal entry.
     *
     * @param  array  $lines  e.g. [['account' => '1010', 'debit' => 100], ['account' => '@sales', 'credit' => 100]]
     * @param  array  $options  date, memo, reference, branch_id, source, idempotency_key, status, ...
     */
    public function record(array $lines, array $options = []): JournalEntry
    {
        return $this->ledger()->record($lines, $options);
    }

    public function transfer(
        Account|int|string $debitAccount,
        Account|int|string $creditAccount,
        Money|int|float|string $amount,
        array $options = [],
    ): JournalEntry {
        return $this->ledger()->transfer($debitAccount, $creditAccount, $amount, $options);
    }

    public function reverse(JournalEntry $entry, array $options = []): JournalEntry
    {
        return $this->ledger()->reverse($entry, $options);
    }

    /**
     * Post a configured posting template, e.g.
     * BookKeeper::post('savings.deposit', ['amount' => 50000], ['party' => $account]).
     */
    public function post(string $template, array $amounts, array $context = [], array $options = []): JournalEntry
    {
        return $this->templates()->post($template, $amounts, $context, $options);
    }

    // -----------------------------------------------------------------------
    // Receivables and payables
    // -----------------------------------------------------------------------

    public function invoice(Contact|Model|int $contact, array $lines, array $options = []): Document
    {
        return $this->documents()->invoice($contact, $lines, $options);
    }

    public function bill(Contact|Model|int $contact, array $lines, array $options = []): Document
    {
        return $this->documents()->bill($contact, $lines, $options);
    }

    public function receivePayment(Contact|Model|int $contact, Money|int|float|string $amount, Account|int|string $account, array $options = []): Payment
    {
        return $this->payments()->receive($contact, $amount, $account, $options);
    }

    public function makePayment(Contact|Model|int $contact, Money|int|float|string $amount, Account|int|string $account, array $options = []): Payment
    {
        return $this->payments()->pay($contact, $amount, $account, $options);
    }

    // -----------------------------------------------------------------------
    // Reports
    // -----------------------------------------------------------------------

    public function trialBalance(\DateTimeInterface|string|null $asOf = null, array $options = []): array
    {
        return $this->statements()->trialBalance($asOf, $options);
    }

    public function balanceSheet(\DateTimeInterface|string|null $asOf = null, array $options = []): array
    {
        return $this->statements()->balanceSheet($asOf, $options);
    }

    public function incomeStatement(\DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null, array $options = []): array
    {
        return $this->statements()->incomeStatement($from, $to, $options);
    }

    public function cashFlow(\DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null, array $options = []): array
    {
        return $this->statements()->cashFlow($from, $to, $options);
    }

    public function changesInEquity(\DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null, array $options = []): array
    {
        return $this->statements()->changesInEquity($from, $to, $options);
    }

    public function generalLedger(Account|int|string $account, \DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null, array $options = []): array
    {
        return $this->statements()->generalLedger($account, $from, $to, $options);
    }

    /**
     * Forward anything else to the ledger service, e.g. BookKeeper::draft().
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->ledger()->{$method}(...$arguments);
    }
}
