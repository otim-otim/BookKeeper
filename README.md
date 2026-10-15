# BookKeeper — Accounting for Laravel

BookKeeper is a complete double-entry accounting engine for Laravel. Install it and your application gets:

- **Chart of accounts:** a hierarchical chart with subtypes, roles and control accounts, plus ready-made templates (general business and Uganda SACCO).
- **Immutable journal:** balanced entries that can only be corrected by reversal. Entries carry drafts, idempotency keys, branch, source document and creator.
- **Fiscal years and periods:** periods can be locked. End-of-day, month and year close run with pluggable tasks.
- **Subledgers:** per-member and per-customer balances on control accounts. They always reconcile to the general ledger.
- **Posting templates:** map business events (a deposit, a loan disbursement) to journal lines in config.
- **Receivables and payables:** invoices, credit notes, bills and debit notes. Payments with allocation, withholding tax and realised FX differences. Ageing reports.
- **Tax:** VAT and withholding tax with effective-dated rates, and a tax report.
- **Fixed assets:** straight-line and declining-balance depreciation, and disposal.
- **Budgets:** budget versus actual.
- **Teller tills and vaults:** cash sessions with overage and shortage posting.
- **Bank and mobile-money reconciliation.**
- **Year-end:** surplus appropriation, and dividends on time-weighted balances.
- **Reports:** trial balance, balance sheet, income statement, cash flow, changes in equity, general ledger, subledger statements and prudential ratios.
- **Exact money:** amounts are integer minor units with exact decimal arithmetic. Floats are never used for money.
- **Any currency, any country:**
  - Every ISO 4217 currency is built in, and each set of books has its own locked base currency.
  - Foreign-currency accounts report balances in their own currency.
  - Month-end revaluation, pluggable exchange-rate sources, locale-aware formatting and cash rounding.
  - Chart templates for Uganda, Kenya and Tanzania SACCOs and for IFRS, in English and Swahili.
- **Typed API:** every write operation accepts a readonly data transfer object (DTO) or the equivalent array.

Requires **PHP 8.4+** and **Laravel 12 or 13**. Works on SQLite, MySQL and PostgreSQL.

---

## Contents

1. [Installation](#installation)
2. [Concepts](#concepts)
3. [Double-entry bookkeeping](#double-entry-bookkeeping)
4. [Money](#money)
5. [Currencies and countries](#currencies-and-countries)
6. [Data transfer objects](#data-transfer-objects)
7. [Chart of accounts](#chart-of-accounts)
8. [Journal entries](#journal-entries)
9. [Posting templates](#posting-templates)
10. [Subledgers](#subledgers)
11. [Periods and closing](#periods-and-closing)
12. [Receivables and payables](#receivables-and-payables)
13. [Tax](#tax)
14. [Fixed assets](#fixed-assets)
15. [Budgets](#budgets)
16. [Teller tills](#teller-tills)
17. [Bank and mobile-money reconciliation](#bank-and-mobile-money-reconciliation)
18. [Surplus appropriation and dividends](#surplus-appropriation-and-dividends)
19. [Reports](#reports)
20. [Events](#events)
21. [Configuration reference](#configuration-reference)
22. [Roles the package uses](#roles-the-package-uses)
23. [Database tables](#database-tables)
24. [Exceptions](#exceptions)
25. [Multi-tenant applications](#multi-tenant-applications)
26. [Using BookKeeper in Suluhu](#using-bookkeeper-in-suluhu)
27. [Testing your application](#testing-your-application)
28. [Architecture](#architecture)
29. [Development with Docker](#development-with-docker)
30. [Upgrading from 1.x](#upgrading-from-1x)

---

## Installation

```bash
composer require otim-otim/bookkeeper
php artisan vendor:publish --tag=bookkeeper-config
php artisan migrate
php artisan bookkeeper:install --chart=standard --currency=USD
# SACCO charts set their country's currency: --chart=sacco-ug|sacco-ke|sacco-tz
# Swahili account names: --locale=sw
```

`bookkeeper:install` imports a chart of accounts and opens the current fiscal year. Run `php artisan bookkeeper:install --list` to see the available templates.

The base currency is chosen once per set of books, using `--currency`, the chart's currency, or `BOOKKEEPER_CURRENCY`. It is locked when the first entry posts. See [Currencies and countries](#currencies-and-countries).

---

## Concepts

| Concept | Description |
| --- | --- |
| **Money** | `OtimOtim\BookKeeper\Support\Money` is an immutable amount in integer minor units. `Money::of('1500.50', 'USD')` and `Money::ofMinor(150050, 'USD')` are the same value. Amounts with more decimals than the currency allows are rejected. Calculated amounts (tax, interest, depreciation) are rounded with the configured mode. Money serialises to a decimal string. |
| **Account reference** | Anywhere an account is expected, you can pass an `Account` model, its ID, its code (`'1121'`) or a role (`'@bank'`). |
| **Role** | The purpose an account plays (`cash`, `accounts_receivable`, `retained_earnings`, ...), so code never hard-codes account numbers. Chart templates assign roles. `config('bookkeeper.roles')` can override them. |
| **Control account** | An account whose every line must name a **subledger party**, such as a member, customer or loan. Party balances are views over the ledger, so they always tie to the control account. |
| **Posted vs draft** | Posted entries affect balances and are immutable. Drafts do not affect balances and can still be edited or deleted. |
| **Base currency** | Every line is stored in the base currency. Foreign amounts and rates are kept alongside. |

---

## Double-entry bookkeeping

Double entry is the foundation everything else builds on. Invoices, payments, depreciation, tills, dividends and year-end close all post through the same ledger, so every one of them obeys these rules.

**Every entry balances.** A journal entry has two or more lines. Each line debits or credits one account, and total debits must equal total credits exactly. The check uses integer minor units, so rounding can never let an unbalanced entry through. An unbalanced entry throws `UnbalancedEntryException`, and nothing is saved.

```php
BookKeeper::record([
    ['account' => '@bank',      'debit'  => '1180.00'],   // money in
    ['account' => '@sales',     'credit' => '1000.00'],   // income earned
    ['account' => '@vat_output','credit' => '180.00'],    // tax owed
]);
```

**Balances follow each account type's normal side:**

| Type | Normal balance | Increases with | Balance = | Statement |
| --- | --- | --- | --- | --- |
| Asset | Debit | Debit | debits − credits | Balance sheet |
| Expense | Debit | Debit | debits − credits | Income statement |
| Liability | Credit | Credit | credits − debits | Balance sheet |
| Equity | Credit | Credit | credits − debits | Balance sheet |
| Revenue | Credit | Credit | credits − debits | Income statement |

Contra accounts, such as accumulated depreciation and loan-loss provision, carry the opposite balance. They show as negative within their section, so the section totals net off correctly.

**The books always agree with themselves.** Because every entry balances, the ledger as a whole stays in balance:

- The **trial balance** always has equal debit and credit totals.
- The **balance sheet** always satisfies assets = liabilities + equity. Profit not yet closed is shown as *current earnings*.
- Every **subledger** (each member's savings, each customer's receivable) sums to its control account.

**History is permanent.** A posted entry, including its lines, cannot be edited or deleted. A mistake is corrected by `BookKeeper::reverse()`, which posts a mirror-image entry and links the two. Both stay visible in the ledger, giving a complete audit trail. Entries carry a gap-free number, the user who posted them, the branch and the source business record.

**One posting, one transaction.** An entry and everything recorded with it (document, payment, allocation, till session) are written in a single database transaction. They are posted together or not at all. An idempotency key makes a retried request return the original entry instead of posting twice.

---

## Money

`OtimOtim\BookKeeper\Support\Money` holds an amount as an integer number of minor units (cents, or whole shillings for UGX), with exact arithmetic from `brick/math`.

```php
use OtimOtim\BookKeeper\Support\Money;

$a = Money::of('1500.50', 'USD');      // from a major-unit amount (string, int, float or Money)
$b = Money::ofMinor(150050, 'USD');    // from minor units
Money::ofRounded('10.005', 'USD');     // round excess decimals instead of rejecting them
Money::zero('UGX');

$a->plus($b)->minus('0.50');           // strings are major units, ints are minor units
$a->multipliedBy('1.5');               // rounded with the configured mode
$a->percent('18');                     // 18% of the amount
$a->allocate([1, 1, 1]);               // split without losing a cent
$a->convertTo('UGX', '3700');          // currency conversion
$a->amount();   // "1500.50"           (decimal string; also __toString and JSON)
$a->minor();    // 150050
$a->isPositive(); $a->isZero(); $a->equals($b); $a->compareTo($b);
Money::sum($list, 'USD');
```

Raw amounts with more decimals than the currency allows (`Money::of('10.005', 'USD')`) throw `InvalidAmountException`. Unknown currency codes throw `InvalidCurrencyException`. Precision comes from ISO 4217: UGX has 0 decimals, USD and KES have 2, KWD has 3.

```php
$a->roundToCash();          // CHF 1.03 → 1.05, UGX 1,020 → 1,000 (smallest coin)
$a->format();               // "$1,500.50" (locale-aware, needs ext-intl)
$a->format('de_DE');        // "1.500,50 $"
```

---

## Currencies and countries

BookKeeper works in any currency and any country. Nothing is hard-coded to one.

**Base currency per set of books.** The books' base currency is stored in the database, not taken from config at run time:

- It is set by `bookkeeper:install --currency=KES`, by `BookKeeper::settings()->setBaseCurrency('KES')`, or on the first posting, from `BOOKKEEPER_CURRENCY`.
- After the first posting it can't change. Every stored amount is in that currency, so a later config change can never reinterpret your books.
- In a multi-tenant app, each tenant's books carry their own base currency.

```php
BookKeeper::settings()->setBaseCurrency('TZS');   // allowed until the first posting
BookKeeper::settings()->baseCurrency();           // "TZS"
BookKeeper::settings()->isBaseCurrencyLocked();
```

**Every ISO 4217 currency is built in,** with its correct minor units. Override one, or add a custom unit, in config:

```php
'currencies' => [
    'KES' => ['cash_increment' => '1'],                // smallest coin
    'XBT' => ['decimals' => 8, 'name' => 'Bitcoin'],   // a non-ISO unit
],
```

`Currency::all()`, `Currency::precision('KWD')`, `Currency::name('TZS')` and `Currency::cashIncrement('CHF')` expose the data.

**Foreign-currency accounts.** Give an account a currency (`['currency' => 'USD']`) and every line on it must be in that currency. Its balance is reported both ways:

```php
BookKeeper::balance('1125');                       // base currency (UGX 560,000)
BookKeeper::account('1125')->currencyBalance();    // USD 150.00
```

The general ledger and trial balance add `currency_balance` columns for these accounts. Bank reconciliation of a foreign account works entirely in that account's currency.

**Exchange rates.** Rates are stored as base units per 1 foreign unit, by date. Lookups use the latest rate on or before the date.

```php
BookKeeper::exchangeRates()->set('USD', '3700', '2026-03-01');
BookKeeper::exchangeRates()->convert(Money::of('100', 'USD'), 'KES');   // foreign → foreign via base
BookKeeper::exchangeRates()->crossRate('USD', 'KES');
```

To fetch rates automatically, set `exchange_rates.provider` and schedule `bookkeeper:rates`. Two providers are bundled:

| Provider | Source |
| --- | --- |
| `RateProviders\OpenExchangeRatesApiProvider` | open.er-api.com: free, daily, about 160 currencies including UGX, KES, TZS and RWF. Needs Laravel's HTTP client. |
| `RateProviders\FixedRateProvider` | `exchange_rates.fixed` in config, for pegged or board-set rates |

To add a source, such as a central bank's feed, implement `Contracts\ExchangeRateProvider`:

```php
class BankOfUgandaRates implements ExchangeRateProvider
{
    public function rates(string $base, array $currencies, CarbonInterface $date): array
    {
        // return ['USD' => '3712.50', 'KES' => '28.61'];   // base units per 1 unit
    }
}
```

**Realised and unrealised exchange differences:**

- **Realised.** Settling a foreign invoice or bill at a different rate posts the difference to `fx_gain_loss`.
- **Unrealised (IAS 21).** At month end, `closeMonth()` restates foreign-currency **monetary** balances at the closing rate: bank, receivables per customer, payables per vendor, and borrowings.
  - It posts the difference to `fx_gain_loss` and reverses it the next day.
  - Non-monetary items, such as fixed assets, inventory and prepayments, keep their historical rate.
  - Run it directly with `BookKeeper::revaluation()->revalue('2026-03-31')`, or preview it with `calculate()`.

**Cash rounding.** For currencies whose smallest coin is larger than one minor unit (CHF 0.05, UGX 50, SEK 1, ...), document totals can round to the coin. Set `'cash_rounding' => true` per document, or `documents.cash_rounding` globally. The difference posts to the `cash_rounding` role.

**Formatting.** `Money::format($locale)` uses PHP's intl extension, with the locale taken from `bookkeeper.locale` or the app locale:

- `$1,234.50` in `en_US`
- `1.234,50 €` in `de_DE`
- `USh 50,000` in `en_UG`

Without intl, it falls back to `UGX 50,000`.

On Alpine images, install `icu-data-full`. The default ICU data only covers English.

**Country chart templates.**

| Template | Country / standard | Currency | Languages |
| --- | --- | --- | --- |
| `standard` | General business | from settings | en |
| `ifrs` | IFRS statement layout (IFRS 16 leases, IAS 37 provisions, IAS 12 deferred tax, OCI) | from settings | en |
| `sacco-ug` | Uganda SACCO (UMRA): MTN and Airtel floats, Stabilisation Fund levy | UGX | en, sw |
| `sacco-ke` | Kenya SACCO (SASRA): M-Pesa, BOSA/FOSA deposits, excise duty, SHIF, housing levy | KES | en, sw |
| `sacco-tz` | Tanzania SACCOS (TCDC): M-Pesa, Mixx by Yas, Airtel, HaloPesa, SDL, WCF | TZS | en, sw |

Template names can be translations (`'name' => ['en' => 'Cash', 'sw' => 'Fedha taslimu']`). `--locale` picks the stored name, and `Account::localizedName('sw')` returns any other. The Swahili names are a first draft and should be reviewed by a native speaker before production use.

Tax rates, fiscal year start and numbering are all configuration, so any country's rules can be expressed without code changes.

---

## Data transfer objects

Every write operation is described by a readonly DTO in `OtimOtim\BookKeeper\Data`. Services accept the DTO, or the equivalent snake_case array shown elsewhere in this README, which is converted with `Dto::fromArray()` at the boundary. Inside, services only work with typed, validated values.

| DTO | Used by |
| --- | --- |
| `EntryData`, `LineData` | `record()`, `draft()`, `transfer()`, `reverse()` |
| `AccountData` | `accounts()->createFrom()`, chart import |
| `DocumentData`, `DocumentLineData` | `documents()->createFrom()` (invoices, bills, notes) |
| `PaymentData`, `AllocationData` | `payments()->recordFrom()` |
| `TaxRateData` | `taxes()->createFrom()` |
| `ContactData` | `contacts()->createFrom()` |
| `FixedAssetData` | `assets()->register()` |
| `BankStatementData`, `StatementLineData` | `bank()->import()` |
| `TillData` | `tills()->createFrom()` |
| `PostingTemplateData`, `TemplateLineData` | `templates()->define()` and config templates |
| `AppropriationRuleData` | `appropriation()` rules |

```php
use OtimOtim\BookKeeper\Data\{EntryData, LineData, DocumentData, DocumentLineData};

BookKeeper::record(
    [LineData::debit('@bank', Money::of('250.00')), LineData::credit('@sales', '250.00', memo: 'Consulting')],
    new EntryData(memo: 'March retainer', reference: 'INV-88', idempotencyKey: 'retainer:2026-03'),
);

BookKeeper::documents()->createFrom(new DocumentData(
    type: DocumentType::Invoice,
    contact: $customer,
    lines: [new DocumentLineData('@sales', '40.00', quantity: '3', tax: 'VAT')],
    cashRounding: true,
));

$entry = new EntryData(memo: 'Original');
$entry->with(memo: 'Changed');      // DTOs are immutable; with() returns a copy
```

DTOs validate when they are constructed. For example, a subtype that doesn't match its type, a document without lines, or an unknown appropriation base all throw immediately.

---

## Chart of accounts

```php
use OtimOtim\BookKeeper\Facades\BookKeeper;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Enums\AccountSubtype;

BookKeeper::createAccount('1000', 'Current assets', AccountType::Asset, ['is_group' => true]);
BookKeeper::createAccount('1010', 'Cash', AccountSubtype::Cash, ['parent' => '1000', 'role' => 'cash', 'tags' => ['liquid']]);
BookKeeper::createAccount('1200', 'Receivables', AccountSubtype::Receivable, ['role' => 'accounts_receivable', 'is_control' => true]);

BookKeeper::chart()->import('sacco-ug');   // import a template (safe to repeat)
BookKeeper::accounts()->tree();             // nested accounts
BookKeeper::account('@cash')->ancestors();  // path from the root
BookKeeper::balance('1000');                // groups roll up their children
```

- **Group accounts** (`is_group`) organise the tree and cannot be posted to. An account becomes a group when it gets a child, provided it has no postings.
- **Subtypes** drive statement classification: current or non-current, liquid, cash-flow category, and contra accounts.
- **Tags** are free-form labels for regulatory ratios, such as `liquid`, `core_capital` and `non_earning`.
- **System accounts** come from templates and cannot be deleted. An account with postings can only be deactivated.

**Account types and subtypes.** The type decides the normal balance and the statement. The subtype refines it:

| Type | Subtypes |
| --- | --- |
| Asset | `cash`, `bank`, `mobile_money`, `short_term_investment`, `receivable`, `loan_portfolio`, `interest_receivable`, `loan_loss_provision`†, `inventory`, `prepayment`, `tax_receivable`, `other_current_asset`, `investment`, `fixed_asset`, `accumulated_depreciation`†, `intangible_asset`, `other_asset` |
| Liability | `payable`, `member_savings`, `fixed_deposits`, `interest_payable`, `tax_payable`, `accrued_liability`, `deferred_income`, `dividends_payable`, `short_term_borrowing`, `long_term_borrowing`, `other_current_liability`, `other_liability` |
| Equity | `share_capital`, `reserve`, `retained_earnings`, `opening_balance_equity`, `drawings`†, `other_equity` |
| Revenue | `operating_revenue`, `interest_income`, `fee_income`, `other_income` |
| Expense | `cost_of_sales`, `interest_expense`, `operating_expense`, `personnel_expense`, `provision_expense`, `depreciation_expense`, `tax_expense`, `other_expense` |

† Contra account. Cash, bank and mobile money count as cash in the cash flow statement. Investments and fixed assets are investing activities. Borrowings, share capital, dividends payable and drawings are financing. Everything else is operating. Override this per account with `cash_flow_category`.


Templates are PHP arrays in `resources/charts`. See the bundled templates under [Currencies and countries](#currencies-and-countries). Publish them with `--tag=bookkeeper-charts`, or drop your own into `resources/bookkeeper/charts`. A template may set a `currency`, and its account names may be translations.

---

## Journal entries

```php
// Record and post
$entry = BookKeeper::record([
    ['account' => '@cash',  'debit'  => '1500.00', 'memo' => 'Cash received'],
    ['account' => '@sales', 'credit' => '1500.00'],
], [
    'date'            => '2026-03-01',
    'memo'            => 'Cash sale',
    'reference'       => 'INV-001',
    'branch_id'       => 3,
    'source'          => $order,               // any Eloquent model, for drill-down
    'idempotency_key' => "order:{$order->id}", // safe to retry
]);

$entry->number;      // JE-2026-000001 (gap-free, assigned when posted)

BookKeeper::transfer('@bank', '@cash', 500);                 // one debit, one credit
BookKeeper::reverse($entry, ['memo' => 'Entered in error']); // the only way to correct
BookKeeper::draft($lines, $options);                         // save without posting
BookKeeper::ledger()->post($draft);
BookKeeper::openingBalances('2026-01-01', ['1010' => 5000, '2100' => 1200]);

// Accruals that reverse themselves (processed at end of day)
BookKeeper::record($lines, ['date' => '2026-03-31', 'reverse_on' => '2026-04-01']);

// Foreign currency: converted at the line rate or the stored rate for the date
BookKeeper::exchangeRates()->set('USD', '3700', '2026-03-01');
BookKeeper::record([
    ['account' => '@bank_usd', 'debit' => '100.00', 'currency' => 'USD'],
    ['account' => '@sales', 'credit' => 370000],
]);
```

The v1 line format, `['account' => '1000', 'type' => 'debit', 'amount' => 100]`, still works.

The rules enforced on every entry:

- **Balance.** Debits must equal credits, compared exactly.
- **Postable accounts.** Lines can only go to accounts that are active and not groups.
- **Subledgers.** Lines on control accounts must name a party.
- **Open periods.** The entry date must fall in an open period.
- **Immutability.** Posted entries and their lines cannot be updated or deleted; doing so throws `ImmutableRecordException`. The only exception is the reversal link.

---

## Posting templates

Templates describe a business event once. Application code then posts it by name.

```php
// config/bookkeeper.php
'postings' => [
    'savings.deposit' => [
        'memo' => 'Savings deposit',
        'lines' => [
            ['debit'  => ':till',          'amount' => 'amount'],                       // account from the context
            ['credit' => 'member_savings', 'amount' => 'amount', 'subledger' => 'account'],
        ],
    ],
    'loan.disbursed' => [
        'lines' => [
            ['debit'  => 'loan_portfolio',  'amount' => 'principal', 'subledger' => 'loan'],
            ['credit' => 'member_savings',  'amount' => 'principal - fee', 'subledger' => 'account'],
            ['credit' => 'loan_fee_income', 'amount' => 'fee'],
        ],
    ],
],
```

```php
BookKeeper::post('savings.deposit', ['amount' => 50000], ['till' => $till->account, 'account' => $savingsAccount], [
    'idempotency_key' => $request->idempotencyKey(),
    'branch_id' => $branch->id,
]);
```

Each line takes three kinds of value:

- **Accounts:** a role, `#code` for an account code, or `:key` for an account passed in the context.
- **Amounts:** keys from the amounts array, combined with `+` and `-`.
- **Subledger:** a context key holding the party model.

Lines that add up to zero are skipped. You can also define templates at runtime with `BookKeeper::templates()->define()`.

---

## Subledgers

```php
BookKeeper::subledgers()->balance('@member_savings', $account);                 // one party
BookKeeper::subledgers()->listing('@member_savings');                           // every party
BookKeeper::subledgers()->statement('@member_savings', $account, $from, $to);   // running balance
BookKeeper::subledgers()->reconcile('@loan_portfolio');                         // control = Σ parties?
BookKeeper::subledgers()->averageBalances('@share_capital', $from, $to);        // day-weighted averages
```

---

## Periods and closing

The first posting into a date creates its fiscal year and twelve monthly periods automatically. The year starts in the month set by `fiscal_year.start_month`; set `auto_create` to `false` to turn this off.

```php
BookKeeper::periods()->createFiscalYear(2027);
BookKeeper::periods()->close('2026-06-01');      // lock June; postings now throw PeriodClosedException
BookKeeper::periods()->reopen('2026-06-01');

BookKeeper::close()->closeDay('2026-06-30');     // tasks, scheduled reversals, balance snapshot
BookKeeper::close()->closeMonth('2026-06-30');   // tasks, FX revaluation, depreciation, lock the period
BookKeeper::close()->closeYear(2026);            // close open months, closing entry → retained earnings, open next year
BookKeeper::close()->reopenYear(2026);
```

Each close runs only once per date. Plug in your own end-of-day, month and year work, such as interest accrual, penalties or loan-loss provisioning:

```php
use OtimOtim\BookKeeper\Contracts\CloseTask;
use OtimOtim\BookKeeper\Close\CloseContext;

class AccrueSavingsInterest implements CloseTask
{
    public function handle(CloseContext $context): void
    {
        // ... compute interest, then post it idempotently:
        BookKeeper::post('savings.interest_accrued', [...], [...], [
            'date' => $context->date,
            'idempotency_key' => $context->key('savings-interest'),
        ]);
    }
}

// config/bookkeeper.php
'close' => ['day' => [AccrueSavingsInterest::class], 'month' => [...], 'year' => [...]],
```

Schedule the artisan commands:

```php
Schedule::command('bookkeeper:close-day')->dailyAt('23:55');
Schedule::command('bookkeeper:close-month')->monthlyOn(1, '01:00');
Schedule::command('bookkeeper:rates')->dailyAt('06:00');
```

| Command | Purpose |
| --- | --- |
| `bookkeeper:install --chart= --currency= --locale= --year=` | Set the base currency, import a chart, open the fiscal year |
| `bookkeeper:close-day {date?}` | End of day |
| `bookkeeper:close-month {date?}` | End of month (default: last month) |
| `bookkeeper:close-year {year}` | End of year |
| `bookkeeper:depreciate {date?}` | Post outstanding depreciation |
| `bookkeeper:snapshot {date?}` | Store daily balance snapshots |
| `bookkeeper:reversals {date?}` | Post scheduled reversals |
| `bookkeeper:rates {date?} --currency=*` | Fetch exchange rates from the configured provider |

---

## Receivables and payables

```php
$customer = BookKeeper::contacts()->customer('Kampala Traders', ['payment_terms_days' => 30, 'tax_number' => '1000123456']);
$customer = BookKeeper::contacts()->for($member);  // or link to any of your models

$invoice = BookKeeper::invoice($customer, [
    ['account' => '@sales', 'description' => 'Widgets', 'quantity' => 10, 'unit_price' => '50.00', 'tax' => 'VAT'],
    ['account' => '@service_income', 'amount' => '100.00', 'discount' => '10.00'],
], ['issue_date' => '2026-04-01', 'tax_inclusive' => false]);

BookKeeper::receivePayment($customer, '400.00', '@bank', ['allocations' => [$invoice]]);
BookKeeper::documents()->creditNote($customer, $lines);
BookKeeper::documents()->applyCredit($creditNote, $invoice);

$bill = BookKeeper::bill($vendor, [['account' => '@office_expenses', 'amount' => '1000.00', 'tax' => 'VAT-IN']]);
BookKeeper::makePayment($vendor, '1180.00', '@bank', ['allocations' => [$bill], 'withholding' => 'WHT']);

BookKeeper::payments()->allocate($payment, $laterInvoice);  // use an on-account payment
BookKeeper::documents()->void($invoice);                    // reverses its entry
BookKeeper::payments()->void($payment);

BookKeeper::aging()->receivables('2026-04-30');  // current, 1-30, 31-60, 61-90, over_90
BookKeeper::aging()->payables();
```

- **Posting.** An invoice debits the receivable control account, with the customer as the subledger party, and credits revenue and output tax. Bills mirror this.
- **Withholding tax** is deducted from the cash that moves.
- **Foreign-currency documents** settle at the payment rate. The difference goes to the `fx_gain_loss` role.

---

## Tax

```php
BookKeeper::taxes()->create('VAT', 'VAT 18%', 'sales', '18', ['account' => '@vat_output']);
BookKeeper::taxes()->create('VAT-IN', 'Input VAT', 'purchase', '18', ['account' => '@vat_input']);
BookKeeper::taxes()->create('WHT', 'Withholding tax 6%', 'withholding', '6', ['account' => '@withholding_tax']);

// Rate change: a new version of the same code; the old one ends the day before
BookKeeper::taxes()->create('VAT', 'VAT 20%', 'sales', '20', ['account' => '@vat_output', 'effective_from' => '2027-07-01']);

BookKeeper::taxes()->calculate(Money::of('118.00'), 'VAT', inclusive: true);  // net, tax, gross
BookKeeper::taxes()->report('2026-04-01', '2026-04-30');                  // output, input, net payable, withheld
```

A purchase rate without an account is not recoverable, so its tax is added to the cost.

---

## Fixed assets

```php
$asset = BookKeeper::assets()->register([
    'code' => 'FA-001', 'name' => 'Laptop', 'cost' => 3600000,
    'useful_life_months' => 36,          // or 'method' => 'declining_balance', 'declining_rate' => 25
    'asset_account' => '1630', 'acquired_on' => '2026-01-10',
    'paid_from' => '@bank',              // optional: posts the purchase
]);

BookKeeper::assets()->depreciate('2026-03-31');   // one entry per month, idempotent; also run by month end
BookKeeper::assets()->schedule($asset);
BookKeeper::assets()->dispose($asset, '2026-04-15', ['proceeds' => 3000000, 'proceeds_account' => '@cash']);
```

---

## Budgets

```php
$budget = BookKeeper::budgets()->create('2026 budget', 2026);
BookKeeper::budgets()->setAnnual($budget, '@salaries', 1200000);        // spread evenly over months
BookKeeper::budgets()->set($budget, '@rent', '2026-03-01', 150000);    // one month
BookKeeper::budgets()->approve($budget);
BookKeeper::budgets()->report($budget, '2026-01-01', '2026-03-31');    // budget, actual, variance, favourable
```

---

## Teller tills

```php
$vault = BookKeeper::tills()->create('VAULT', 'Main vault', ['account' => '@cash', 'type' => 'vault']);
$till  = BookKeeper::tills()->create('T1', 'Teller 1');   // creates its own cash account under @cash_on_hand

$session = BookKeeper::tills()->open($till, $teller);
BookKeeper::tills()->transfer($vault, $till, 500000);
BookKeeper::tills()->assertOpen($till);                   // call before teller postings
BookKeeper::tills()->close($session, counted: 615000);    // posts any shortage or overage to @cash_over_short
```

---

## Bank and mobile-money reconciliation

```php
$statement = BookKeeper::bank()->import('@mtn_momo', [
    'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
    'opening_balance' => 0, 'closing_balance' => 1048500,
], $lines);                                         // [date, amount (+in/-out), description, reference]

BookKeeper::bank()->autoMatch($statement);          // same amount, ±3 days, reference preferred
BookKeeper::bank()->match($statementLine, $journalLine);
BookKeeper::bank()->adjust($chargesLine, '@mobile_money_charges');  // book bank-only items
BookKeeper::bank()->summary($statement);            // outstanding items, difference
BookKeeper::bank()->reconcile($statement);
```

---

## Surplus appropriation and dividends

```php
// After the year is closed and the AGM approves:
BookKeeper::appropriation()->appropriate(2026, ['rules' => [
    ['name' => 'Statutory reserve', 'credit' => 'statutory_reserve', 'percent' => '10', 'base' => 'net_surplus'],
    ['name' => 'Share transfer fund', 'credit' => 'share_transfer_fund', 'percent' => '5', 'base' => 'remaining'],
    ['name' => 'Stabilisation levy', 'credit' => 'stabilisation_levy_payable', 'percent' => '0.5', 'base' => 'average_total_assets'],
]]);

// Dividends on time-weighted share balances, with withholding tax
$calc = BookKeeper::dividends()->calculate('@share_capital', '12', '2026-01-01', '2026-12-31', ['withholding' => 'WHT-DIV']);
BookKeeper::dividends()->pay($calc, [
    'credit' => fn ($type, $id) => ['account' => '@member_savings', 'subledger' => SavingsAccount::primaryFor($id)],
]);
```

Appropriation is idempotent per fiscal year. Rules can also live in `config('bookkeeper.appropriation')`.

---

## Reports

Every report returns arrays of `Money` values, ready for `response()->json()`.

```php
BookKeeper::trialBalance('2026-12-31');
BookKeeper::balanceSheet('2026-12-31', ['branch' => 3]);   // tree rows, current earnings, classification
BookKeeper::incomeStatement('2026-01-01', '2026-12-31');   // excludes closing entries
BookKeeper::cashFlow('2026-01-01', '2026-12-31');          // indirect method, reconciles to cash
BookKeeper::changesInEquity('2026-01-01', '2026-12-31');
BookKeeper::generalLedger('@bank', '2026-03-01', '2026-03-31');   // opening, running balance
BookKeeper::statements()->balanceByTag('liquid');                 // prudential ratios
```

Balances use aggregate SQL. Daily snapshots, taken at end of day, keep balance queries fast as history grows, and a back-dated posting invalidates the snapshots it affects.

---

## Events

These events are dispatched after the database transaction commits:

- `EntryPosted`, `EntryReversed`
- `PeriodClosed`, `PeriodReopened`, `FiscalYearClosed`, `FiscalYearReopened`, `DayClosed`
- `DocumentIssued`, `DocumentVoided`, `PaymentRecorded`, `PaymentVoided`
- `TillSessionClosed`, `BankStatementReconciled`
- `AssetsDepreciated`, `SurplusAppropriated`, `DividendsPaid`

They are all in the `OtimOtim\BookKeeper\Events` namespace. For example, listen to `EntryPosted` to flag cash transactions above a reporting threshold.

---

## Configuration reference

Publish the file with `php artisan vendor:publish --tag=bookkeeper-config`.

| Key | Default | Purpose |
| --- | --- | --- |
| `connection` | `null` (default connection) | Database connection for BookKeeper tables (`BOOKKEEPER_DB_CONNECTION`) |
| `run_migrations` | `true` | Load the package migrations automatically (`BOOKKEEPER_RUN_MIGRATIONS`) |
| `currency` | `USD` | Default base currency for new books (`BOOKKEEPER_CURRENCY`); locked per set of books on first posting |
| `currencies` | `[]` | Overrides and custom units: `'KES' => ['decimals' => 2, 'cash_increment' => '1']` |
| `locale` | `null` (app locale) | Money formatting and chart name language (`BOOKKEEPER_LOCALE`) |
| `rounding` | `HalfUp` | Rounding for calculated amounts (any `Brick\Math\RoundingMode`) |
| `fiscal_year.start_month` | `1` | First month of the fiscal year |
| `fiscal_year.auto_create` | `true` | Create fiscal years and periods on first use |
| `journal.number_format` | `JE-{Y}-{seq}` | Entry numbering; tokens `{Y}`, `{m}`, `{seq}` |
| `journal.sequence_padding` | `6` | Zero padding for `{seq}` |
| `journal.enforce_subledgers` | `true` | Require a party on control-account lines |
| `roles` | `[]` | Role → account code overrides |
| `postings` | `[]` | Posting templates |
| `documents.number_formats` | `INV-…`, `CN-…`, `BILL-…`, `DN-…`, `RCPT-…`, `PAY-…` | Document and payment numbering |
| `documents.default_payment_terms_days` | `30` | Due date when the contact has no terms |
| `documents.ageing_buckets` | `[0, 30, 60, 90]` | Ageing report bucket bounds (days overdue) |
| `tills.over_short_role` | `cash_over_short` | Account role for till overages and shortages |
| `snapshots.enabled` | `true` | Use daily balance snapshots |
| `exchange_rates.provider` | `null` | `ExchangeRateProvider` class for `bookkeeper:rates` |
| `exchange_rates.currencies` | `[]` | Currencies to fetch (empty: those in use) |
| `exchange_rates.fixed` | `[]` | Rates for `FixedRateProvider` |
| `revaluation.enabled` | `true` | Revalue foreign monetary balances at month end |
| `revaluation.role` | `fx_gain_loss` | Account role for unrealised exchange differences |
| `revaluation.reverse` | `true` | Reverse the revaluation the next day |
| `documents.cash_rounding` | `false` | Round document totals to the smallest coin |
| `appropriation` | `[]` | Default year-end appropriation rules |
| `close.day` / `close.month` / `close.year` | `[]` | `CloseTask` classes run at each close |

---

## Roles the package uses

Some features post to accounts by role. Both bundled charts assign all of them. For a custom chart, give these roles to accounts before using the features that need them:

| Role | Used by |
| --- | --- |
| `retained_earnings` | Year-end close, appropriation, dividends (default debit) |
| `opening_balance_equity` | `openingBalances()` |
| `accounts_receivable` / `accounts_payable` | Invoices, credit notes / bills, debit notes, payments (control accounts) |
| `fx_gain_loss` | Exchange differences on settlement |
| `withholding_tax` | Withholding without a tax-rate account |
| `cash_over_short` | Till overages and shortages |
| `cash_rounding` | Cash rounding on document totals |
| `cash_on_hand` | Parent of new till accounts |
| `fixed_assets`, `accumulated_depreciation`, `depreciation_expense`, `asset_disposal` | Fixed assets |
| `dividends_payable` | Default credit for dividends |

---

## Database tables

| Table | Holds |
| --- | --- |
| `bookkeeper_accounts` | Chart of accounts |
| `bookkeeper_journal_entries` / `bookkeeper_journal_lines` | The general ledger: entry headers and their debit and credit lines |
| `bookkeeper_fiscal_years` / `bookkeeper_periods` | Fiscal calendar and period locks |
| `bookkeeper_sequences` | Gap-free numbering |
| `bookkeeper_settings` | Settings of these books, including the locked base currency |
| `bookkeeper_snapshots` / `bookkeeper_account_balances` | Daily cumulative balances |
| `bookkeeper_close_runs` | Which day, month and year closes have run |
| `bookkeeper_tax_rates` / `bookkeeper_exchange_rates` | Tax and currency rates |
| `bookkeeper_contacts` | Customers and vendors |
| `bookkeeper_documents` / `bookkeeper_document_lines` | Invoices, credit notes, bills, debit notes |
| `bookkeeper_payments` / `bookkeeper_allocations` | Payments and what they settle |
| `bookkeeper_fixed_assets` / `bookkeeper_asset_depreciations` | Asset register and depreciation charges |
| `bookkeeper_budgets` / `bookkeeper_budget_lines` | Budgets |
| `bookkeeper_tills` / `bookkeeper_till_sessions` | Tills, vaults and teller sessions |
| `bookkeeper_bank_statements` / `bookkeeper_bank_statement_lines` | Imported statements and matches |

Each table has an Eloquent model in `OtimOtim\BookKeeper\Models` (`Account`, `JournalEntry`, `JournalLine`, `FiscalYear`, `Period`, `Document`, `Payment`, ...). Use them freely for reads. For writes, go through the services so the rules are enforced.

---

## Exceptions

Every exception extends `OtimOtim\BookKeeper\Exceptions\BookKeeperException`.

| Exception | Thrown when |
| --- | --- |
| `UnbalancedEntryException` | Debits do not equal credits |
| `InvalidEntryException` | A line is invalid: group or inactive account, missing subledger, wrong currency, negative amount |
| `AccountNotFoundException` | An account code, ID or role does not exist |
| `PeriodClosedException` | Posting into a closed (or missing) period |
| `ImmutableRecordException` | Editing or deleting a posted entry or line |
| `InvalidAmountException` | An amount has too many decimals or mixes currencies |
| `InvalidCurrencyException` | An unknown currency code (extends `InvalidAmountException`) |
| `InvalidOperationException` | A business rule is broken (paying the wrong document, closing twice, voiding a paid invoice, ...) |
| `TemplateNotFoundException` | A posting template is not defined |

---

## Multi-tenant applications

BookKeeper's tables carry no tenant column, so each tenant needs its own database or schema. This is the stancl/tenancy model.

```bash
php artisan vendor:publish --tag=bookkeeper-tenant-migrations   # → database/migrations/tenant
```

```dotenv
BOOKKEEPER_RUN_MIGRATIONS=false   # don't create the tables in the central database
```

The services are stateless, so they work across tenant switches in queue workers and Octane.

---

## Using BookKeeper in Suluhu

Suluhu, the SACCO platform, keeps each SACCO's books in its own PostgreSQL schema. Set it up as follows:

1. **Install.** Add the package from its VCS repository, publish the tenant migrations, and set `BOOKKEEPER_RUN_MIGRATIONS=false` and `BOOKKEEPER_CURRENCY=UGX`.
2. **Seed the chart.** In the tenant seeder, call `BookKeeper::chart()->import('sacco-ug')` and `BookKeeper::periods()->createFiscalYear(now()->year)`. The SACCO financial year runs January to December, the default.
3. **Fix the accounting service.** Rebuild `AccountingService` on posting templates. Loan approval is not a ledger event, so drop it. Disbursement, repayment, fees, penalties, interest accrual, provisions and write-offs each become a template in `config/bookkeeper.php`.
4. **Post through templates.** Savings, shares and loans call `BookKeeper::post()` inside their own DB transaction, passing the member's account or loan as the subledger party and the request's idempotency key. Member balances then become `BookKeeper::subledgers()->balance()`, not stored counters.
5. **Tellers.** Use `BookKeeper::tills()` for teller sessions, and call `assertOpen()` before cash postings.
6. **Close tasks.** Daily interest accrual, arrears ageing and penalties are `CloseTask`s registered under `close.day`. Month-end provisioning goes under `close.month`.
7. **Reconciliation.** `BookKeeper::subledgers()->reconcile('@member_savings')` (and the same for loans and shares) is the subledger-versus-control check.
8. **Year end.** At year end, run `closeYear`. After the AGM, run `appropriation()->appropriate()`, then `dividends()->calculate()` and `pay()`.
9. **UMRA ratios.** Build these from `balanceByTag('liquid')`, `balanceByTag('short_term')`, `balanceByTag('core_capital')` and `balanceSheet()['totals']`.

---

## Testing your application

BookKeeper's tables are created by its migrations, so `RefreshDatabase` works without extra setup. A typical test seeds a chart and asserts on balances:

```php
beforeEach(function () {
    BookKeeper::chart()->import('sacco-ug');
});

it('posts a deposit to the member subledger', function () {
    $account = SavingsAccount::factory()->create();

    app(SavingsService::class)->deposit($account, 50000, idempotencyKey: 'test-1');

    expect(BookKeeper::subledgers()->balance('@member_savings', $account)->minor())->toBe(50000)
        ->and(BookKeeper::trialBalance()['balanced'])->toBeTrue();
});
```

Freeze the clock (`Carbon::setTestNow()`) when you assert on dates or periods.

---

## Architecture

BookKeeper is organised around SOLID principles so it can be extended without being edited:

- **Single responsibility.** Each service owns one area. For example, `LedgerService` records entries, `LineNormalizer` validates and converts lines, `SettlementService` handles allocations and settlement, `RevaluationService` handles exchange revaluation, and `BalanceService` aggregates balances. `BookKeeperManager`, behind the facade, only delegates.
- **Open/closed.** Behaviour is extended through configuration and contracts, not by changing the package:
  - posting templates, chart templates and role overrides;
  - `CloseTask` for end-of-day, month and year work;
  - `ExchangeRateProvider` for rate sources.
- **Liskov substitution / interface segregation.** The contracts are small, with one method each, so any implementation can stand in.
- **Dependency inversion.** Services receive their collaborators through the container, and are registered as singletons in `BookKeeperServiceProvider::SERVICES`. Bind your own subclass to replace one, for example `$this->app->singleton(TaxService::class, MyTaxService::class)`.
- **DRY.** Shared rules live in one place:
  - `AccountReference` parses `role`, `#code` and `:context` references.
  - `Morph` builds polymorphic columns.
  - `LineData::converted()` and `LineData::signed()` build foreign-currency and sign-dependent lines.
  - `Account::balanceIn()` turns aggregated totals into a balance.
  - Currency data lives only in `Currency`/`Iso4217`.

---

## Development with Docker

The image is PHP 8.5 CLI with Composer, PCOV and the SQLite, MySQL and PostgreSQL drivers.

```bash
make build        # docker compose build app
make test         # SQLite
make test-pgsql   # PostgreSQL 17
make test-mysql   # MySQL 8.4
make test-all
make shell
PHP_VERSION=8.4 make build test   # another PHP version
vendor/bin/pint                    # code style (Laravel preset)
```

Without Docker, run `composer install` and then `vendor/bin/pest`.

---

## Upgrading from 1.x

Version 2 has a new schema and returns `Money` instead of floats. See [UPGRADE.md](UPGRADE.md).

---

## License

MIT
