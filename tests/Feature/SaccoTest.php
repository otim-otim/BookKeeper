<?php

use OtimOtim\BookKeeper\Enums\StatementStatus;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Facades\BookKeeper;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\TillSession;
use Tests\Fixtures\Member;

beforeEach(function () {
    travelToDate('2026-01-01');
    useChart('sacco-ug', 'UGX');
    Member::migrate();

    $this->alice = Member::create(['name' => 'Alice']);
    $this->bob = Member::create(['name' => 'Bob']);

    config()->set('bookkeeper.postings', [
        'savings.deposit' => [
            'memo' => 'Savings deposit',
            'lines' => [
                ['debit' => ':cash', 'amount' => 'amount'],
                ['credit' => 'member_savings', 'amount' => 'amount', 'subledger' => 'member'],
            ],
        ],
        'shares.purchase' => [
            'memo' => 'Share purchase',
            'lines' => [
                ['debit' => 'cash', 'amount' => 'amount'],
                ['credit' => 'share_capital', 'amount' => 'amount', 'subledger' => 'member'],
            ],
        ],
        'loan.disbursed' => [
            'memo' => 'Loan disbursement',
            'lines' => [
                ['debit' => 'loan_portfolio', 'amount' => 'principal', 'subledger' => 'member'],
                ['credit' => 'member_savings', 'amount' => 'principal - fee', 'subledger' => 'member'],
                ['credit' => 'loan_fee_income', 'amount' => 'fee'],
            ],
        ],
        'loan.repayment' => [
            'memo' => 'Loan repayment',
            'lines' => [
                ['debit' => 'cash', 'amount' => 'principal + interest'],
                ['credit' => 'loan_portfolio', 'amount' => 'principal', 'subledger' => 'member'],
                ['credit' => 'loan_interest_income', 'amount' => 'interest'],
            ],
        ],
    ]);
});

test('the SACCO chart imports with roles, control accounts and a tree', function () {
    expect(Account::count())->toBeGreaterThan(80)
        ->and(BookKeeper::account('@member_savings')->is_control)->toBeTrue()
        ->and(BookKeeper::account('@share_capital')->hasTag('core_capital'))->toBeTrue()
        ->and(BookKeeper::account('@cash')->ancestors()->pluck('code')->all())->toBe(['1000', '1100', '1110'])
        ->and(BookKeeper::accounts()->tree())->toHaveCount(5)
        ->and(BookKeeper::chart()->import('sacco-ug'))->toBe(0);
});

test('UGX amounts have no decimals', function () {
    BookKeeper::post('savings.deposit', ['amount' => 50000], ['cash' => '@cash', 'member' => $this->alice]);

    expect(BookKeeper::balance('@cash')->amount())->toBe('50000');
});

test('posting templates map business events to balanced entries', function () {
    BookKeeper::post('loan.disbursed', ['principal' => 1000000, 'fee' => 20000], ['member' => $this->alice]);

    expect(BookKeeper::subledgers()->balance('@loan_portfolio', $this->alice))->toEqualMoney(1000000)
        ->and(BookKeeper::subledgers()->balance('@member_savings', $this->alice))->toEqualMoney(980000)
        ->and(BookKeeper::balance('@loan_fee_income'))->toEqualMoney(20000);

    BookKeeper::post('loan.repayment', ['principal' => 100000, 'interest' => 15000], ['member' => $this->alice]);

    expect(BookKeeper::subledgers()->balance('@loan_portfolio', $this->alice))->toEqualMoney(900000)
        ->and(BookKeeper::balance('@loan_interest_income'))->toEqualMoney(15000);
});

test('templates can be defined at runtime and skip zero lines', function () {
    BookKeeper::templates()->define('fee.charged', ['lines' => [
        ['debit' => 'member_savings', 'amount' => 'fee + tax', 'subledger' => 'member'],
        ['credit' => 'transaction_fee_income', 'amount' => 'fee'],
        ['credit' => 'vat_output', 'amount' => 'tax'],
    ]]);

    $entry = BookKeeper::post('fee.charged', ['fee' => 1000, 'tax' => 0], ['member' => $this->alice]);

    expect($entry->lines)->toHaveCount(2)
        ->and($entry->metadata['template'])->toBe('fee.charged');
});

test('subledgers always reconcile to their control accounts', function () {
    $members = [$this->alice, $this->bob];

    // Property-style: random postings never unbalance the books or the subledgers.
    mt_srand(42);
    for ($i = 0; $i < 60; $i++) {
        $member = $members[mt_rand(0, 1)];
        match (mt_rand(0, 2)) {
            0 => BookKeeper::post('savings.deposit', ['amount' => mt_rand(1, 500) * 1000], ['cash' => '@cash', 'member' => $member]),
            1 => BookKeeper::post('shares.purchase', ['amount' => mt_rand(1, 50) * 1000], ['member' => $member]),
            2 => BookKeeper::post('loan.disbursed', ['principal' => mt_rand(10, 100) * 10000, 'fee' => mt_rand(0, 9) * 1000], ['member' => $member]),
        };
    }

    expect(BookKeeper::trialBalance()['balanced'])->toBeTrue()
        ->and(BookKeeper::balanceSheet()['balanced'])->toBeTrue();

    foreach (['@member_savings', '@share_capital', '@loan_portfolio'] as $account) {
        $check = BookKeeper::subledgers()->reconcile($account);
        expect($check['balanced'])->toBeTrue()
            ->and($check['control'])->toEqualMoney($check['subledgers']);
    }

    $listing = BookKeeper::subledgers()->listing('@member_savings');
    expect($listing)->toHaveCount(2)
        ->and(BookKeeper::subledgers()->party($listing[0]['subledger_type'], $listing[0]['subledger_id']))->toBeInstanceOf(Member::class);
});

test('a member statement shows a running balance', function () {
    BookKeeper::post('savings.deposit', ['amount' => 10000], ['cash' => '@cash', 'member' => $this->alice], ['date' => '2026-01-05']);
    BookKeeper::post('savings.deposit', ['amount' => 5000], ['cash' => '@cash', 'member' => $this->alice], ['date' => '2026-01-10']);
    BookKeeper::post('savings.deposit', ['amount' => 7000], ['cash' => '@cash', 'member' => $this->bob], ['date' => '2026-01-10']);

    $statement = BookKeeper::subledgers()->statement('@member_savings', $this->alice, '2026-01-06', '2026-01-31');

    expect($statement['opening_balance'])->toEqualMoney(10000)
        ->and($statement['lines'])->toHaveCount(1)
        ->and($statement['closing_balance'])->toEqualMoney(15000);
});

test('dividends are paid on time-weighted share balances with withholding tax', function () {
    BookKeeper::taxes()->create('WHT-DIV', 'WHT on dividends 15%', 'withholding', '15', ['account' => '@withholding_tax']);

    // Alice holds 100,000 all year; Bob buys 100,000 on 2 July (half the year).
    BookKeeper::post('shares.purchase', ['amount' => 100000], ['member' => $this->alice], ['date' => '2026-01-01']);
    BookKeeper::post('shares.purchase', ['amount' => 100000], ['member' => $this->bob], ['date' => '2026-07-02']);
    BookKeeper::transfer('@cash', '@loan_interest_income', 500000, ['date' => '2026-06-30']);

    travelToDate('2027-03-01');
    BookKeeper::close()->closeYear(2026);

    $calculation = BookKeeper::dividends()->calculate('@share_capital', '10', '2026-01-01', '2026-12-31', ['withholding' => 'WHT-DIV']);
    $alice = collect($calculation['holders'])->firstWhere('subledger_id', $this->alice->id);
    $bob = collect($calculation['holders'])->firstWhere('subledger_id', $this->bob->id);

    expect($alice['average_balance'])->toEqualMoney(100000)
        ->and($alice['gross'])->toEqualMoney(10000)
        ->and($alice['withholding'])->toEqualMoney(1500)
        ->and($bob['average_balance'])->toEqualMoney(50137) // 183 of 365 days
        ->and($bob['gross'])->toEqualMoney(5014)
        ->and($bob['withholding'])->toEqualMoney(752);

    // Pay each member's net dividend into their savings.
    BookKeeper::dividends()->pay($calculation, [
        'credit' => fn ($type, $id) => ['account' => '@member_savings', 'subledger' => Member::find($id)],
    ]);

    expect(BookKeeper::subledgers()->balance('@member_savings', $this->alice))->toEqualMoney(8500)
        ->and(BookKeeper::balance('@withholding_tax'))->toEqualMoney(1500 + 752)
        ->and(BookKeeper::balance('@retained_earnings'))->toEqualMoney(500000 - 15014);
});

test('surplus is appropriated to reserves in rule order', function () {
    BookKeeper::transfer('@cash', '@loan_interest_income', 1000000, ['date' => '2026-03-01']);
    BookKeeper::transfer('@salaries', '@cash', 200000, ['date' => '2026-03-02']);

    travelToDate('2027-02-15');
    BookKeeper::close()->closeYear(2026);

    $result = BookKeeper::appropriation()->appropriate(2026, ['rules' => [
        ['name' => 'Statutory reserve', 'credit' => 'statutory_reserve', 'percent' => '10', 'base' => 'net_surplus'],
        ['name' => 'Share transfer fund', 'credit' => 'share_transfer_fund', 'percent' => '5', 'base' => 'remaining'],
        ['name' => 'Education fund', 'credit' => '#3230', 'base' => 'fixed', 'amount' => 10000],
    ]]);

    expect($result['net_surplus'])->toEqualMoney(800000)
        ->and(BookKeeper::balance('@statutory_reserve'))->toEqualMoney(80000)
        ->and(BookKeeper::balance('@share_transfer_fund'))->toEqualMoney(36000)
        ->and(BookKeeper::balance('3230'))->toEqualMoney(10000)
        ->and(BookKeeper::balance('@retained_earnings'))->toEqualMoney(800000 - 126000);

    // Idempotent per fiscal year.
    expect(BookKeeper::appropriation()->appropriate(2026)['already_appropriated'])->toBeTrue()
        ->and(BookKeeper::balance('@statutory_reserve'))->toEqualMoney(80000);
});

test('teller tills: open, transfer from vault, close with a shortage', function () {
    BookKeeper::transfer('@cash', '@share_capital', 2000000, ['credit_subledger' => $this->alice]);
    $vault = BookKeeper::tills()->create('VAULT', 'Main vault', ['account' => '@cash', 'type' => 'vault']);
    $till = BookKeeper::tills()->create('T1', 'Teller 1', ['account_code' => '1112']);

    expect(fn () => BookKeeper::tills()->transfer($vault, $till, 500000))->toThrow(InvalidOperationException::class, 'no open session');

    $session = BookKeeper::tills()->open($till, $this->bob);
    BookKeeper::tills()->transfer($vault, $till, 500000);
    BookKeeper::post('savings.deposit', ['amount' => 120000], ['cash' => $till->account, 'member' => $this->alice]);

    expect(BookKeeper::tills()->balance($till))->toEqualMoney(620000)
        ->and($till->account->parent->role)->toBe('cash_on_hand');

    $closed = BookKeeper::tills()->close($session, 615000, 'Counted twice');

    expect($closed->variance)->toEqualMoney(-5000)
        ->and($closed->expected_balance)->toEqualMoney(620000)
        ->and(BookKeeper::tills()->balance($till))->toEqualMoney(615000)
        ->and(BookKeeper::balance('@cash_over_short'))->toEqualMoney(5000)
        ->and(fn () => BookKeeper::tills()->open($till))->not->toThrow(InvalidOperationException::class)
        ->and(TillSession::where('till_id', $till->id)->count())->toBe(2);
});

test('mobile money float reconciles against the provider statement', function () {
    BookKeeper::transfer('@mtn_momo', '@cash', 1000000, ['date' => '2026-01-02', 'reference' => 'TOPUP-1']);
    BookKeeper::post('savings.deposit', ['amount' => 50000], ['cash' => '@mtn_momo', 'member' => $this->alice], ['date' => '2026-01-05', 'reference' => 'MP123']);
    BookKeeper::post('savings.deposit', ['amount' => 30000], ['cash' => '@mtn_momo', 'member' => $this->bob], ['date' => '2026-01-30']);

    $statement = BookKeeper::bank()->import('@mtn_momo', [
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'opening_balance' => 0, 'closing_balance' => 1048500,
    ], [
        ['date' => '2026-01-02', 'amount' => 1000000, 'reference' => 'TOPUP-1'],
        ['date' => '2026-01-06', 'amount' => 50000, 'reference' => 'MP123'],
        ['date' => '2026-01-31', 'amount' => -1500, 'description' => 'Provider charges'],
    ]);

    expect(BookKeeper::bank()->autoMatch($statement))->toBe(2);

    $summary = BookKeeper::bank()->summary($statement);
    expect($summary['reconciled'])->toBeFalse()
        ->and($summary['outstanding_total'])->toEqualMoney(30000)
        ->and($summary['unmatched_statement_total'])->toEqualMoney(-1500);

    $charges = $statement->lines()->whereNull('journal_line_id')->first();
    BookKeeper::bank()->adjust($charges, '@mobile_money_charges');

    $statement = BookKeeper::bank()->reconcile($statement->refresh());

    expect($statement->status)->toBe(StatementStatus::Reconciled)
        ->and(BookKeeper::balance('@mobile_money_charges'))->toEqualMoney(1500)
        ->and(BookKeeper::bank()->summary($statement)['difference'])->toEqualMoney(0);
});

test('fixed assets depreciate monthly and can be disposed of', function () {
    $asset = BookKeeper::assets()->register([
        'code' => 'FA-001',
        'name' => 'Laptop',
        'cost' => 3600000,
        'useful_life_months' => 36,
        'acquired_on' => '2026-01-10',
        'asset_account' => '1630',
        'paid_from' => '@bank',
    ]);

    expect(BookKeeper::balance('1630'))->toEqualMoney(3600000);

    $entries = BookKeeper::assets()->depreciate('2026-03-31');

    expect($entries)->toHaveCount(3)
        ->and($asset->accumulatedDepreciation())->toEqualMoney(300000)
        ->and(BookKeeper::balance('@depreciation_expense'))->toEqualMoney(300000)
        ->and(BookKeeper::assets()->depreciate('2026-03-31'))->toHaveCount(0)
        ->and($schedule = BookKeeper::assets()->schedule($asset))->toHaveCount(36)
        ->and(end($schedule)['net_book_value'])->toEqualMoney(0);

    BookKeeper::assets()->dispose($asset, '2026-04-15', ['proceeds' => 3000000, 'proceeds_account' => '@cash']);

    expect(BookKeeper::balance('1630'))->toEqualMoney(0)
        ->and(BookKeeper::balance('@accumulated_depreciation'))->toEqualMoney(0)
        ->and(BookKeeper::balance('@asset_disposal'))->toEqualMoney(-300000); // loss: NBV 3.3m, proceeds 3.0m
});

test('month end charges depreciation automatically', function () {
    BookKeeper::assets()->register(['code' => 'FA-2', 'name' => 'Desk', 'cost' => 1200000, 'useful_life_months' => 12, 'acquired_on' => '2026-01-01', 'asset_account' => '@fixed_assets']);

    BookKeeper::close()->closeMonth('2026-01-31');

    expect(BookKeeper::balance('@depreciation_expense'))->toEqualMoney(100000);
});

test('budgets compare against actuals', function () {
    $budget = BookKeeper::budgets()->create('2026 budget', 2026);
    BookKeeper::budgets()->setAnnual($budget, '@salaries', 1200000);
    BookKeeper::budgets()->setAnnual($budget, '@loan_interest_income', 2400000);
    BookKeeper::budgets()->approve($budget);

    BookKeeper::transfer('@salaries', '@cash', 120000, ['date' => '2026-01-28']);
    BookKeeper::transfer('@cash', '@loan_interest_income', 150000, ['date' => '2026-01-31']);

    $report = BookKeeper::budgets()->report($budget, '2026-01-01', '2026-01-31');
    $salaries = collect($report['rows'])->firstWhere('code', '5310');
    $interest = collect($report['rows'])->firstWhere('code', '4100');

    expect($salaries['budget'])->toEqualMoney(100000)
        ->and($salaries['variance'])->toEqualMoney(20000)
        ->and($salaries['favourable'])->toBeFalse()
        ->and($interest['variance'])->toEqualMoney(-50000)
        ->and($report['net_income']['actual'])->toEqualMoney(30000)
        ->and(fn () => BookKeeper::budgets()->set($budget, '@salaries', '2026-02-01', 1))->toThrow(InvalidOperationException::class, 'approved');
});

test('prudential ratios come from tagged balances', function () {
    BookKeeper::post('savings.deposit', ['amount' => 1000000], ['cash' => '@cash', 'member' => $this->alice]);
    BookKeeper::post('shares.purchase', ['amount' => 300000], ['member' => $this->alice]);
    BookKeeper::post('loan.disbursed', ['principal' => 800000, 'fee' => 0], ['member' => $this->bob]);

    $liquid = BookKeeper::statements()->balanceByTag('liquid');
    $shortTerm = BookKeeper::statements()->balanceByTag('short_term');
    $core = BookKeeper::statements()->balanceByTag('core_capital');

    expect($liquid)->toEqualMoney(1300000)
        ->and($shortTerm)->toEqualMoney(1800000)
        ->and($core)->toEqualMoney(300000);
});
