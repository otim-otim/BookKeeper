# Upgrading from 1.x to 2.0

Version 2 rebuilds BookKeeper as a full accounting engine. The facade methods from 1.x still exist, but some behaviour has changed.

## Requirements

- PHP 8.4 or later (was 8.1).
- Laravel 12 or 13 (was 10–13).

## Database

The schema is new, and the 1.x migrations have been replaced.

| 1.x | 2.0 |
| --- | --- |
| `bookkeeper_transactions` (`type`, `amount` decimal) | `bookkeeper_journal_lines` (`debit`, `credit` as bigint minor units) |
| `bookkeeper_journal_entries` | Same table name, plus `number`, `type`, `status`, `branch_id`, `source_*`, `creator_*`, `idempotency_key`, `period_id`, reversal links |
| `bookkeeper_accounts` | Adds `parent_id`, `subtype`, `role`, `is_group`, `is_control`, `is_system`, `tags` |

To carry over data from 1.x:

1. Run the 2.0 migrations into a fresh schema.
2. Recreate the accounts.
3. Post closing balances as opening balances with `BookKeeper::openingBalances()`.

For a full history, replay each 1.x journal entry with `BookKeeper::record()` and `type`/`amount` lines. The 1.x line format is still accepted.

## Behaviour changes

- **Amounts are `Money` objects.** `balance()`, `balanceAsOf()` and every report return `Money`, not `float`. Use `->amount()` for a decimal string, `->minor()` for the integer, or `->toFloat()` if you must. Integers and floats you pass in are still major units, so `100` means 100.00.
- **Currency default.** The default currency comes from config (`BOOKKEEPER_CURRENCY`) instead of being hard-coded to USD.
- **Immutability.** Posted entries are immutable. Use `BookKeeper::reverse()` instead of editing or deleting.
- **Periods.** Postings need an open period. Fiscal years are created automatically unless `fiscal_year.auto_create` is `false`.
- **Report signatures.** `generalLedger()` takes any account reference (model, ID, code or `@role`) instead of only a model.
- **Balance sheet.** The balance sheet now includes unclosed profit as `current_earnings`, so it balances before year end.
- **Relation names.** `Transaction` is now `JournalLine`. The `transactions()` relations remain as deprecated aliases of `lines()`.

## Changes within 2.0 (multi-currency, DTOs)

These changes apply if you installed an earlier 2.0 development build.

- **New migration.** `2026_10_16_000001_create_bookkeeper_settings_table` adds the `bookkeeper_settings` table, `bookkeeper_bank_statements.currency` and `bookkeeper_documents.rounding`. Tenant apps should republish it with `--tag=bookkeeper-tenant-migrations` and run tenant migrations.
- **Base currency is stored per set of books.** It is locked on the first posting. Existing books lock to the configured currency the next time something posts.
- **Unknown currency codes are rejected.** Add custom units to `bookkeeper.currencies`.
- **Month-end close revalues foreign-currency balances.** Turn this off with `revaluation.enabled = false`.
- **DTOs.** Service methods accept DTOs from `OtimOtim\BookKeeper\Data`. The array forms still work unchanged. `LedgerService` now depends on `LineNormalizer`, and settlement moved from `DocumentService` to `SettlementService`. `DocumentService::applyCredit()`, `unapply()` and `refreshStatus()` still delegate there.
- **New account role.** Charts gained a `cash_rounding` account (5950). Re-run `BookKeeper::chart()->import(...)` to add it to existing books.
