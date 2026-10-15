<?php

use OtimOtim\BookKeeper\Enums\DocumentStatus;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Facades\BookKeeper;
use OtimOtim\BookKeeper\Models\Contact;

beforeEach(function () {
    travelToDate('2026-04-01');
    useChart('standard');

    BookKeeper::taxes()->create('VAT', 'VAT 18%', 'sales', '18', ['account' => '@vat_output']);
    BookKeeper::taxes()->create('VAT-IN', 'Input VAT 18%', 'purchase', '18', ['account' => '@vat_input']);
    BookKeeper::taxes()->create('WHT', 'Withholding tax 6%', 'withholding', '6', ['account' => '@withholding_tax']);

    $this->customer = BookKeeper::contacts()->customer('Kampala Traders', ['payment_terms_days' => 30]);
    $this->vendor = BookKeeper::contacts()->vendor('Office Supplies Ltd');
});

test('an invoice posts revenue, VAT and the receivable', function () {
    $invoice = BookKeeper::invoice($this->customer, [
        ['account' => '@sales', 'description' => 'Widgets', 'quantity' => 10, 'unit_price' => '50.00', 'tax' => 'VAT'],
        ['account' => '@service_income', 'description' => 'Installation', 'amount' => '100.00'],
    ], ['issue_date' => '2026-04-01']);

    expect($invoice->number)->toBe('INV-2026-000001')
        ->and($invoice->status)->toBe(DocumentStatus::Open)
        ->and($invoice->due_date->toDateString())->toBe('2026-05-01')
        ->and($invoice->subtotal)->toEqualMoney('600.00')
        ->and($invoice->tax_total)->toEqualMoney('90.00')
        ->and($invoice->total)->toEqualMoney('690.00')
        ->and(BookKeeper::balance('@accounts_receivable'))->toEqualMoney('690.00')
        ->and(BookKeeper::balance('@sales'))->toEqualMoney('500.00')
        ->and(BookKeeper::balance('@vat_output'))->toEqualMoney('90.00')
        ->and(BookKeeper::subledgers()->balance('@accounts_receivable', $this->customer))->toEqualMoney('690.00');
});

test('tax-inclusive prices are split into net and tax', function () {
    $invoice = BookKeeper::invoice($this->customer, [
        ['account' => '@sales', 'amount' => '118.00', 'tax' => 'VAT'],
    ], ['tax_inclusive' => true]);

    expect($invoice->subtotal)->toEqualMoney('100.00')
        ->and($invoice->tax_total)->toEqualMoney('18.00')
        ->and($invoice->total)->toEqualMoney('118.00');
});

test('payments settle invoices partially then fully', function () {
    $invoice = BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '1000.00']]);

    $first = BookKeeper::receivePayment($this->customer, '400.00', '@bank', ['allocations' => [$invoice]]);

    expect($first->number)->toBe('RCPT-2026-000001')
        ->and($invoice->refresh()->status)->toBe(DocumentStatus::Partial)
        ->and($invoice->amountDue())->toEqualMoney('600.00');

    BookKeeper::receivePayment($this->customer, '600.00', '@bank', ['allocations' => [[$invoice, '600.00']]]);

    expect($invoice->refresh()->status)->toBe(DocumentStatus::Paid)
        ->and(BookKeeper::balance('@accounts_receivable'))->toEqualMoney(0)
        ->and(BookKeeper::balance('@bank'))->toEqualMoney('1000.00');
});

test('an overpayment stays on account and can be allocated later', function () {
    $first = BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '300.00']]);
    $payment = BookKeeper::receivePayment($this->customer, '500.00', '@bank', ['allocations' => [$first]]);

    expect($payment->unallocatedAmount())->toEqualMoney('200.00')
        ->and(BookKeeper::subledgers()->balance('@accounts_receivable', $this->customer))->toEqualMoney('-200.00');

    $second = BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '150.00']]);
    BookKeeper::payments()->allocate($payment, $second);

    expect($second->refresh()->status)->toBe(DocumentStatus::Paid)
        ->and($payment->refresh()->unallocatedAmount())->toEqualMoney('50.00');
});

test('a credit note reduces the receivable and can be applied to an invoice', function () {
    $invoice = BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '500.00', 'tax' => 'VAT']]);
    $credit = BookKeeper::documents()->creditNote($this->customer, [['account' => '@sales', 'amount' => '100.00', 'tax' => 'VAT']]);

    expect($credit->number)->toBe('CN-2026-000001')
        ->and(BookKeeper::balance('@accounts_receivable'))->toEqualMoney('472.00')
        ->and(BookKeeper::balance('@vat_output'))->toEqualMoney('72.00');

    BookKeeper::documents()->applyCredit($credit, $invoice);

    expect($invoice->refresh()->amountDue())->toEqualMoney('472.00')
        ->and($credit->refresh()->status)->toBe(DocumentStatus::Paid);
});

test('a bill posts the expense and recoverable input tax', function () {
    $bill = BookKeeper::bill($this->vendor, [
        ['account' => '@office_expenses', 'amount' => '200.00', 'tax' => 'VAT-IN'],
    ], ['number' => 'SUP-778']);

    expect($bill->number)->toBe('SUP-778')
        ->and(BookKeeper::balance('@accounts_payable'))->toEqualMoney('236.00')
        ->and(BookKeeper::balance('@office_expenses'))->toEqualMoney('200.00')
        ->and(BookKeeper::balance('@vat_input'))->toEqualMoney('36.00');
});

test('non-recoverable purchase tax is added to the cost', function () {
    BookKeeper::taxes()->create('VAT-NR', 'VAT not recoverable', 'purchase', '18');
    BookKeeper::bill($this->vendor, [['account' => '@office_expenses', 'amount' => '100.00', 'tax' => 'VAT-NR']]);

    expect(BookKeeper::balance('@office_expenses'))->toEqualMoney('118.00')
        ->and(BookKeeper::balance('@accounts_payable'))->toEqualMoney('118.00');
});

test('paying a bill with withholding tax', function () {
    $bill = BookKeeper::bill($this->vendor, [['account' => '@office_expenses', 'amount' => '1000.00']]);

    $payment = BookKeeper::makePayment($this->vendor, '1000.00', '@bank', [
        'allocations' => [$bill],
        'withholding' => 'WHT',
    ]);

    expect($payment->withholding_amount)->toEqualMoney('60.00')
        ->and($payment->netAmount())->toEqualMoney('940.00')
        ->and($bill->refresh()->status)->toBe(DocumentStatus::Paid)
        ->and(BookKeeper::balance('@bank'))->toEqualMoney('-940.00')
        ->and(BookKeeper::balance('@withholding_tax'))->toEqualMoney('60.00')
        ->and(BookKeeper::balance('@accounts_payable'))->toEqualMoney(0);

    $report = BookKeeper::taxes()->report('2026-04-01', '2026-04-30');
    expect($report['totals']['withholding'])->toEqualMoney('60.00');
});

test('the tax report nets output tax against input tax', function () {
    BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '1000.00', 'tax' => 'VAT']]);
    BookKeeper::bill($this->vendor, [['account' => '@office_expenses', 'amount' => '400.00', 'tax' => 'VAT-IN']]);
    BookKeeper::documents()->creditNote($this->customer, [['account' => '@sales', 'amount' => '100.00', 'tax' => 'VAT']]);

    $report = BookKeeper::taxes()->report('2026-04-01', '2026-04-30');
    $vat = collect($report['rates'])->firstWhere('code', 'VAT');

    expect($vat['taxable'])->toEqualMoney('900.00')
        ->and($vat['tax'])->toEqualMoney('162.00')
        ->and($report['totals']['input_tax'])->toEqualMoney('72.00')
        ->and($report['totals']['net_payable'])->toEqualMoney('90.00');
});

test('tax rate changes take effect by date', function () {
    BookKeeper::taxes()->create('VAT', 'VAT 20%', 'sales', '20', ['account' => '@vat_output', 'effective_from' => '2026-07-01']);

    $before = BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '100.00', 'tax' => 'VAT']], ['issue_date' => '2026-06-30']);
    $after = BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '100.00', 'tax' => 'VAT']], ['issue_date' => '2026-07-01']);

    expect($before->tax_total)->toEqualMoney('18.00')
        ->and($after->tax_total)->toEqualMoney('20.00');
});

test('voiding an invoice reverses it; paid invoices cannot be voided', function () {
    $invoice = BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '100.00']]);
    BookKeeper::documents()->void($invoice);

    expect($invoice->status)->toBe(DocumentStatus::Void)
        ->and(BookKeeper::balance('@accounts_receivable'))->toEqualMoney(0);

    $paid = BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '100.00']]);
    BookKeeper::receivePayment($this->customer, '100.00', '@bank', ['allocations' => [$paid]]);

    expect(fn () => BookKeeper::documents()->void($paid->refresh()))->toThrow(InvalidOperationException::class);
});

test('voiding a payment reopens its invoices', function () {
    $invoice = BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '100.00']]);
    $payment = BookKeeper::receivePayment($this->customer, '100.00', '@bank', ['allocations' => [$invoice]]);

    BookKeeper::payments()->void($payment);

    expect($invoice->refresh()->status)->toBe(DocumentStatus::Open)
        ->and(BookKeeper::balance('@bank'))->toEqualMoney(0)
        ->and(BookKeeper::balance('@accounts_receivable'))->toEqualMoney('100.00');
});

test('draft documents can be edited before issuing', function () {
    $draft = BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '10.00']], ['draft' => true]);

    expect($draft->status)->toBe(DocumentStatus::Draft)
        ->and(BookKeeper::balance('@accounts_receivable'))->toEqualMoney(0);

    BookKeeper::documents()->updateDraft($draft, [['account' => '@sales', 'amount' => '25.00']]);
    BookKeeper::documents()->issue($draft->refresh());

    expect(BookKeeper::balance('@accounts_receivable'))->toEqualMoney('25.00');
});

test('payments must settle the right kind of document', function () {
    $bill = BookKeeper::bill($this->vendor, [['account' => '@office_expenses', 'amount' => '10.00']]);

    BookKeeper::receivePayment($this->vendor, '10.00', '@bank', ['allocations' => [$bill]]);
})->throws(InvalidOperationException::class, 'cannot settle');

test('aged receivables bucket amounts by days overdue', function () {
    BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '100.00']], ['issue_date' => '2026-01-01', 'due_date' => '2026-01-31']);
    BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '200.00']], ['issue_date' => '2026-03-20', 'due_date' => '2026-04-19']);
    $other = BookKeeper::contacts()->customer('Gulu Stores');
    BookKeeper::invoice($other, [['account' => '@sales', 'amount' => '50.00']], ['issue_date' => '2026-02-01', 'due_date' => '2026-03-03']);
    BookKeeper::receivePayment($other, '80.00', '@bank');

    $aging = BookKeeper::aging()->receivables('2026-04-30');
    $kampala = collect($aging['contacts'])->firstWhere('contact', 'Kampala Traders');
    $gulu = collect($aging['contacts'])->firstWhere('contact', 'Gulu Stores');

    expect($aging['buckets'])->toBe(['current', '1-30', '31-60', '61-90', 'over_90'])
        ->and($kampala['buckets']['61-90'])->toEqualMoney('100.00')
        ->and($kampala['buckets']['1-30'])->toEqualMoney('200.00')
        ->and($gulu['buckets']['31-60'])->toEqualMoney('50.00')
        ->and($gulu['unallocated'])->toEqualMoney('-80.00')
        ->and($aging['totals']['total'])->toEqualMoney('270.00')
        ->and($aging['totals']['total'])->toEqualMoney(BookKeeper::balanceAsOf('@accounts_receivable', '2026-04-30'));
});

test('aged payables', function () {
    BookKeeper::bill($this->vendor, [['account' => '@office_expenses', 'amount' => '75.00']], ['issue_date' => '2026-04-01', 'due_date' => '2026-04-15']);

    $aging = BookKeeper::aging()->payables('2026-04-20');

    expect($aging['totals']['buckets']['1-30'])->toEqualMoney('75.00');
});

test('a customer statement comes from the receivable subledger', function () {
    $invoice = BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '300.00']], ['issue_date' => '2026-03-01']);
    BookKeeper::receivePayment($this->customer, '100.00', '@bank', ['allocations' => [$invoice], 'date' => '2026-03-15']);

    $statement = BookKeeper::subledgers()->statement('@accounts_receivable', $this->customer, '2026-03-01', '2026-03-31');

    expect($statement['lines'])->toHaveCount(2)
        ->and($statement['closing_balance'])->toEqualMoney('200.00');
});

test('foreign-currency invoices realise exchange differences on payment', function () {
    BookKeeper::exchangeRates()->set('EUR', '1.10', '2026-04-01');
    BookKeeper::exchangeRates()->set('EUR', '1.15', '2026-04-20');

    $invoice = BookKeeper::invoice($this->customer, [['account' => '@sales', 'amount' => '100.00']], ['currency' => 'EUR', 'issue_date' => '2026-04-01']);

    expect($invoice->base_total)->toEqualMoney('110.00');

    BookKeeper::receivePayment($this->customer, money('100.00', 'EUR'), '@bank', ['allocations' => [$invoice], 'date' => '2026-04-20']);

    expect(BookKeeper::balance('@accounts_receivable'))->toEqualMoney(0)
        ->and(BookKeeper::balance('@bank'))->toEqualMoney('115.00')
        ->and(BookKeeper::balance('@fx_gain_loss'))->toEqualMoney('5.00');
});

test('contacts can be linked to application models', function () {
    $model = Contact::create(['name' => 'Linked member']);
    $contact = BookKeeper::contacts()->for($model);

    expect($contact->contactable->is($model))->toBeTrue()
        ->and(BookKeeper::contacts()->for($model)->id)->toBe($contact->id);
});
