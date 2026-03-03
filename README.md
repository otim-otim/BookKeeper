# BookKeeper — Laravel Accounting Package

A **double-entry bookkeeping** package for Laravel. Install it in any Laravel application to get a full chart of accounts, journal entry recording, and financial statement generation out of the box.

---

## Installation

### 1. Add the package via Composer

```bash
composer require otimdibossman/bookkeeper
```

Laravel auto-discovery will automatically register the `BookKeeperServiceProvider` and the `BookKeeper` facade.

### 2. Run the migrations

```bash
php artisan migrate
```

Or publish the migrations to customise them first:

```bash
php artisan vendor:publish --tag=bookkeeper-migrations
php artisan migrate
```

---

## Quick Start

### Create accounts

```php
use Otimdibossman\BookKeeper\Facades\BookKeeper;
use Otimdibossman\BookKeeper\Enums\AccountType;

// Chart of accounts
BookKeeper::createAccount('1000', 'Cash',             AccountType::Asset);
BookKeeper::createAccount('1100', 'Accounts Receivable', AccountType::Asset);
BookKeeper::createAccount('2000', 'Accounts Payable', AccountType::Liability);
BookKeeper::createAccount('3000', 'Owner Capital',    AccountType::Equity);
BookKeeper::createAccount('4000', 'Sales Revenue',    AccountType::Revenue);
BookKeeper::createAccount('5000', 'Cost of Goods',    AccountType::Expense);
```

### Record a journal entry (double-entry)

```php
BookKeeper::record([
    ['account' => '1000', 'type' => 'debit',  'amount' => 1500.00, 'memo' => 'Cash received'],
    ['account' => '4000', 'type' => 'credit', 'amount' => 1500.00, 'memo' => 'Sale of goods'],
], [
    'date'      => '2024-03-01',
    'memo'      => 'Cash sale to customer',
    'reference' => 'INV-2024-001',
]);
```

The package will throw `UnbalancedEntryException` if debits ≠ credits.

### Transfer (convenience method)

```php
// Debit Cash, Credit Revenue — in one call
BookKeeper::transfer('1000', '4000', 2000.00, [
    'memo'      => 'Payment received',
    'reference' => 'PAY-001',
]);
```

### Check an account balance

```php
$balance = BookKeeper::balance('1000');           // current balance
$balance = BookKeeper::balanceAsOf('1000', '2024-12-31'); // historical
```

---

## Financial Statements

### Balance Sheet

```php
$sheet = BookKeeper::balanceSheet('2024-12-31');

// Returns: assets, liabilities, equity, totals, balanced (bool)
dump($sheet['totals']);
// ['assets' => 50000, 'liabilities' => 20000, 'equity' => 30000, 'liabilities_equity' => 50000]
```

### Income Statement (Profit & Loss)

```php
$pl = BookKeeper::incomeStatement('2024-01-01', '2024-12-31');

dump($pl['totals']);
// ['revenue' => 80000, 'expenses' => 45000, 'net_income' => 35000]
```

### Trial Balance

```php
$trial = BookKeeper::trialBalance('2024-12-31');

dump($trial['balanced']); // true
dump($trial['totals']);   // ['debits' => 120000, 'credits' => 120000]
```

### General Ledger

```php
$account = BookKeeper::account('1000');
$ledger  = BookKeeper::generalLedger($account, '2024-01-01', '2024-12-31');

// Returns all transaction lines for the account with running details
```

---

## Direct Service Access

You can also inject the services directly:

```php
use Otimdibossman\BookKeeper\Services\AccountService;
use Otimdibossman\BookKeeper\Services\LedgerService;
use Otimdibossman\BookKeeper\Services\StatementService;

class MyController
{
    public function __construct(
        private AccountService   $accounts,
        private LedgerService    $ledger,
        private StatementService $statements,
    ) {}
}
```

---

## Account Types

| Type        | Normal Balance | Statement        |
| ----------- | -------------- | ---------------- |
| `Asset`     | Debit          | Balance Sheet    |
| `Liability` | Credit         | Balance Sheet    |
| `Equity`    | Credit         | Balance Sheet    |
| `Revenue`   | Credit         | Income Statement |
| `Expense`   | Debit          | Income Statement |

---

## Database Tables

| Table                        | Purpose                           |
| ---------------------------- | --------------------------------- |
| `bookkeeper_accounts`        | Chart of accounts                 |
| `bookkeeper_journal_entries` | Groups of balanced debits/credits |
| `bookkeeper_transactions`    | Individual debit/credit lines     |

---

## Running Tests

```bash
composer install
vendor/bin/phpunit
```

---

## License

MIT
