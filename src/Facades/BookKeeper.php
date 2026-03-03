<?php

namespace OtimOtim\BookKeeper\Facades;

use Illuminate\Support\Facades\Facade;
use OtimOtim\BookKeeper\BookKeeperManager;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\JournalEntry;
use OtimOtim\BookKeeper\Enums\AccountType;

/**
 * @method static Account      createAccount(string $code, string $name, AccountType $type, array $options = [])
 * @method static Account      account(int|string $codeOrId)
 * @method static float        balance(Account|int|string $account)
 * @method static float        balanceAsOf(Account|int|string $account, \DateTimeInterface|string $date)
 * @method static JournalEntry record(array $lines, array $options = [])
 * @method static JournalEntry transfer(Account|int|string $debitAccount, Account|int|string $creditAccount, float $amount, array $options = [])
 * @method static array        balanceSheet(?string $asOfDate = null)
 * @method static array        incomeStatement(?string $from = null, ?string $to = null)
 * @method static array        trialBalance(?string $asOfDate = null)
 * @method static array        generalLedger(Account $account, ?string $from = null, ?string $to = null)
 *
 * @see \OtimOtim\BookKeeper\BookKeeperManager
 */
class BookKeeper extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BookKeeperManager::class;
    }
}
