<?php

namespace OtimOtim\BookKeeper\Facades;

use Illuminate\Support\Facades\Facade;
use OtimOtim\BookKeeper\BookKeeperManager;

/**
 * @method static \OtimOtim\BookKeeper\Services\AccountService accounts()
 * @method static \OtimOtim\BookKeeper\Services\ChartService chart()
 * @method static \OtimOtim\BookKeeper\Services\LedgerService ledger()
 * @method static \OtimOtim\BookKeeper\Services\BalanceService balances()
 * @method static \OtimOtim\BookKeeper\Services\PeriodService periods()
 * @method static \OtimOtim\BookKeeper\Services\StatementService statements()
 * @method static \OtimOtim\BookKeeper\Services\SubledgerService subledgers()
 * @method static \OtimOtim\BookKeeper\Services\TemplateService templates()
 * @method static \OtimOtim\BookKeeper\Services\TaxService taxes()
 * @method static \OtimOtim\BookKeeper\Services\ExchangeRateService exchangeRates()
 * @method static \OtimOtim\BookKeeper\Services\SettingsService settings()
 * @method static \OtimOtim\BookKeeper\Services\RevaluationService revaluation()
 * @method static \OtimOtim\BookKeeper\Services\ContactService contacts()
 * @method static \OtimOtim\BookKeeper\Services\DocumentService documents()
 * @method static \OtimOtim\BookKeeper\Services\PaymentService payments()
 * @method static \OtimOtim\BookKeeper\Services\AgingService aging()
 * @method static \OtimOtim\BookKeeper\Services\FixedAssetService assets()
 * @method static \OtimOtim\BookKeeper\Services\BudgetService budgets()
 * @method static \OtimOtim\BookKeeper\Services\TillService tills()
 * @method static \OtimOtim\BookKeeper\Services\BankReconciliationService bank()
 * @method static \OtimOtim\BookKeeper\Services\AppropriationService appropriation()
 * @method static \OtimOtim\BookKeeper\Services\DividendService dividends()
 * @method static \OtimOtim\BookKeeper\Services\CloseService close()
 * @method static \OtimOtim\BookKeeper\Models\Account createAccount(string $code, string $name, \OtimOtim\BookKeeper\Enums\AccountType|\OtimOtim\BookKeeper\Enums\AccountSubtype|string $type, array $options = [])
 * @method static \OtimOtim\BookKeeper\Models\Account account(\OtimOtim\BookKeeper\Models\Account|int|string $account)
 * @method static \OtimOtim\BookKeeper\Support\Money balance(\OtimOtim\BookKeeper\Models\Account|int|string $account, ?int $branchId = null)
 * @method static \OtimOtim\BookKeeper\Support\Money balanceAsOf(\OtimOtim\BookKeeper\Models\Account|int|string $account, \DateTimeInterface|string $date, ?int $branchId = null)
 * @method static \OtimOtim\BookKeeper\Models\JournalEntry record(iterable $lines, \OtimOtim\BookKeeper\Data\EntryData|array $entry = [])
 * @method static \OtimOtim\BookKeeper\Models\JournalEntry draft(iterable $lines, \OtimOtim\BookKeeper\Data\EntryData|array $entry = [])
 * @method static \OtimOtim\BookKeeper\Models\JournalEntry transfer(\OtimOtim\BookKeeper\Models\Account|int|string $debitAccount, \OtimOtim\BookKeeper\Models\Account|int|string $creditAccount, \OtimOtim\BookKeeper\Support\Money|int|float|string $amount, \OtimOtim\BookKeeper\Data\EntryData|array $entry = [])
 * @method static \OtimOtim\BookKeeper\Models\JournalEntry reverse(\OtimOtim\BookKeeper\Models\JournalEntry $entry, \OtimOtim\BookKeeper\Data\EntryData|array $options = [])
 * @method static \OtimOtim\BookKeeper\Models\JournalEntry openingBalances(\DateTimeInterface|string $date, array $balances, array $options = [])
 * @method static \OtimOtim\BookKeeper\Models\JournalEntry post(string $template, array $amounts, array $context = [], array $options = [])
 * @method static \OtimOtim\BookKeeper\Models\Document invoice(\OtimOtim\BookKeeper\Models\Contact|\Illuminate\Database\Eloquent\Model|int $contact, array $lines, array $options = [])
 * @method static \OtimOtim\BookKeeper\Models\Document bill(\OtimOtim\BookKeeper\Models\Contact|\Illuminate\Database\Eloquent\Model|int $contact, array $lines, array $options = [])
 * @method static \OtimOtim\BookKeeper\Models\Payment receivePayment(\OtimOtim\BookKeeper\Models\Contact|\Illuminate\Database\Eloquent\Model|int $contact, \OtimOtim\BookKeeper\Support\Money|int|float|string $amount, \OtimOtim\BookKeeper\Models\Account|int|string $account, array $options = [])
 * @method static \OtimOtim\BookKeeper\Models\Payment makePayment(\OtimOtim\BookKeeper\Models\Contact|\Illuminate\Database\Eloquent\Model|int $contact, \OtimOtim\BookKeeper\Support\Money|int|float|string $amount, \OtimOtim\BookKeeper\Models\Account|int|string $account, array $options = [])
 * @method static array trialBalance(\DateTimeInterface|string|null $asOf = null, array $options = [])
 * @method static array balanceSheet(\DateTimeInterface|string|null $asOf = null, array $options = [])
 * @method static array incomeStatement(\DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null, array $options = [])
 * @method static array cashFlow(\DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null, array $options = [])
 * @method static array changesInEquity(\DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null, array $options = [])
 * @method static array generalLedger(\OtimOtim\BookKeeper\Models\Account|int|string $account, \DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null, array $options = [])
 *
 * @see BookKeeperManager
 */
class BookKeeper extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BookKeeperManager::class;
    }
}
