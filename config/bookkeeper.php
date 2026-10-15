<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | The connection BookKeeper's models use. Null uses the application's
    | default connection, which is what you want for schema-per-tenant setups
    | (e.g. stancl/tenancy) where the default connection is switched per tenant.
    |
    */

    'connection' => env('BOOKKEEPER_DB_CONNECTION'),

    /*
    | Load the package migrations automatically. Turn this off when you
    | publish them somewhere else, e.g. into database/migrations/tenant so
    | each tenant gets its own books and the central database gets none.
    */

    'run_migrations' => env('BOOKKEEPER_RUN_MIGRATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Base currency
    |--------------------------------------------------------------------------
    |
    | Every journal line is stored in the base currency, in integer minor
    | units. Foreign-currency amounts are kept alongside for reference.
    |
    */

    'currency' => env('BOOKKEEPER_CURRENCY', 'USD'),

    /*
    | Number of decimal places (minor units) per currency. Currencies not listed
    | fall back to the ISO 4217 table bundled with the package, then to 2.
    */

    'currencies' => [
        // 'UGX' => 0,
    ],

    /*
    | Rounding mode used when a calculation (tax, interest, depreciation, FX)
    | produces more decimals than the currency allows. Any Brick\Math\RoundingMode
    | case name. Raw amounts passed in with too many decimals are always rejected.
    */

    'rounding' => 'HalfUp',

    /*
    |--------------------------------------------------------------------------
    | Fiscal calendar
    |--------------------------------------------------------------------------
    */

    'fiscal_year' => [
        // Month the fiscal year starts (1 = January).
        'start_month' => 1,

        // Create the fiscal year and monthly periods automatically the first
        // time an entry is posted into a date that has none.
        'auto_create' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Journal behaviour
    |--------------------------------------------------------------------------
    */

    'journal' => [
        // Number format. Tokens: {Y} year, {m} month, {seq} zero-padded sequence.
        'number_format' => 'JE-{Y}-{seq}',
        'sequence_padding' => 6,

        // Reject lines on control accounts that do not name a subledger party.
        'enforce_subledgers' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Account roles
    |--------------------------------------------------------------------------
    |
    | Roles let code refer to accounts by purpose instead of by code. Chart
    | templates assign roles to the accounts they create; anything listed here
    | overrides those assignments (role => account code).
    |
    */

    'roles' => [
        // 'cash' => '1010',
    ],

    /*
    |--------------------------------------------------------------------------
    | Posting templates
    |--------------------------------------------------------------------------
    |
    | Map business events to journal lines. Accounts are roles, account codes,
    | or ":key" to take the account from the context passed at posting time.
    | Amounts name keys in the amounts array, optionally summed with "+".
    | "subledger" names a context key holding the subledger party model.
    |
    | BookKeeper::post('savings.deposit', ['amount' => 50000], ['party' => $account]);
    |
    */

    'postings' => [
        // 'savings.deposit' => [
        //     'memo' => 'Savings deposit',
        //     'lines' => [
        //         ['debit' => ':till', 'amount' => 'amount'],
        //         ['credit' => 'member_savings', 'amount' => 'amount', 'subledger' => 'party'],
        //     ],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Documents (invoices, bills, credit and debit notes) and payments
    |--------------------------------------------------------------------------
    */

    'documents' => [
        'number_formats' => [
            'invoice' => 'INV-{Y}-{seq}',
            'credit_note' => 'CN-{Y}-{seq}',
            'bill' => 'BILL-{Y}-{seq}',
            'debit_note' => 'DN-{Y}-{seq}',
            'payment_received' => 'RCPT-{Y}-{seq}',
            'payment_made' => 'PAY-{Y}-{seq}',
        ],
        'default_payment_terms_days' => 30,

        // Ageing buckets, in days past due (upper bounds). Anything beyond the
        // last bound lands in a final "over" bucket.
        'ageing_buckets' => [0, 30, 60, 90],
    ],

    /*
    |--------------------------------------------------------------------------
    | Teller tills
    |--------------------------------------------------------------------------
    */

    'tills' => [
        // Role of the account that absorbs cash overages and shortages.
        'over_short_role' => 'cash_over_short',
    ],

    /*
    |--------------------------------------------------------------------------
    | Balance snapshots
    |--------------------------------------------------------------------------
    |
    | Daily cumulative balances per account, taken at end of day, so balance
    | queries only sum lines posted after the latest snapshot.
    |
    */

    'snapshots' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Surplus appropriation (year end)
    |--------------------------------------------------------------------------
    |
    | Applied in order by BookKeeper::appropriate(). Each rule moves an amount
    | from the debit role (default: retained_earnings) to the credit role.
    | Bases: net_surplus, remaining, average_total_assets, fixed.
    |
    */

    'appropriation' => [
        // ['name' => 'Statutory reserve', 'credit' => 'statutory_reserve', 'percent' => '10', 'base' => 'net_surplus'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Close tasks
    |--------------------------------------------------------------------------
    |
    | Classes implementing OtimOtim\BookKeeper\Contracts\CloseTask that run as
    | part of end of day, end of month (period) and end of year. They run
    | before the built-in steps, inside the same database transaction.
    |
    */

    'close' => [
        'day' => [],
        'month' => [],
        'year' => [],
    ],

];
