<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\DocumentStatus;
use OtimOtim\BookKeeper\Enums\DocumentType;
use OtimOtim\BookKeeper\Enums\PaymentDirection;
use OtimOtim\BookKeeper\Enums\Side;
use OtimOtim\BookKeeper\Enums\TaxType;
use OtimOtim\BookKeeper\Events\PaymentRecorded;
use OtimOtim\BookKeeper\Events\PaymentVoided;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\Allocation;
use OtimOtim\BookKeeper\Models\Contact;
use OtimOtim\BookKeeper\Models\Document;
use OtimOtim\BookKeeper\Models\Payment;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Customer receipts, vendor payments and refunds, with allocation to
 * documents, withholding tax and realised exchange differences.
 *
 * Received payments settle invoices (and supplier debit notes, i.e. vendor
 * refunds). Made payments settle bills (and customer credit notes, i.e.
 * refunds). Anything not allocated stays on account with the contact.
 *
 * Options: date, currency, exchange_rate, allocations (list of
 * [document, amount] pairs, or documents to settle in order), withholding
 * (TaxRate/ID/code), withholding_amount, ledger ('sales'|'purchases' for
 * unallocated payments), method, reference, memo, number, branch_id,
 * source (model), metadata, idempotency_key.
 */
class PaymentService
{
    public function __construct(
        protected AccountService $accounts,
        protected ContactService $contacts,
        protected LedgerService $ledger,
        protected DocumentService $documents,
        protected TaxService $taxes,
        protected ExchangeRateService $rates,
        protected SequenceService $sequences,
    ) {}

    /**
     * Money received into $account (bank, cash, mobile money).
     */
    public function receive(Contact|Model|int $contact, Money|int|float|string $amount, Account|int|string $account, array $options = []): Payment
    {
        return $this->record(PaymentDirection::Received, $contact, $amount, $account, $options);
    }

    /**
     * Money paid out of $account.
     */
    public function pay(Contact|Model|int $contact, Money|int|float|string $amount, Account|int|string $account, array $options = []): Payment
    {
        return $this->record(PaymentDirection::Made, $contact, $amount, $account, $options);
    }

    public function record(PaymentDirection $direction, Contact|Model|int $contact, Money|int|float|string $amount, Account|int|string $account, array $options = []): Payment
    {
        if (isset($options['idempotency_key']) && ($existing = $this->findByIdempotencyKey($options['idempotency_key']))) {
            return $existing;
        }

        $contact = $this->contacts->resolve($contact);
        $account = $this->accounts->resolve($account);
        $date = Carbon::parse($options['date'] ?? now())->startOfDay();
        $currency = strtoupper($options['currency'] ?? ($amount instanceof Money ? $amount->currency() : ($contact->currency ?? Currency::base())));
        $amount = $amount instanceof Money ? $amount : Money::of($amount, $currency);
        $rate = (string) ($options['exchange_rate'] ?? $this->rates->rate($currency, $date));

        if (! $amount->isPositive()) {
            throw new InvalidOperationException('A payment amount must be positive.');
        }

        $allocations = $this->resolveAllocations($direction, $contact, $currency, $amount, $options['allocations'] ?? []);
        $ledger = $this->ledgerFor($direction, $allocations, $options['ledger'] ?? null);
        $control = $this->accounts->findByRole($ledger === 'sales' ? 'accounts_receivable' : 'accounts_payable');

        // Withholding tax: deducted from the cash that moves.
        $withholdingRate = isset($options['withholding']) ? $this->taxes->resolve($options['withholding'], $date) : null;
        if ($withholdingRate && $withholdingRate->type !== TaxType::Withholding) {
            throw new InvalidOperationException("Tax rate [{$withholdingRate->code}] is not a withholding rate.");
        }
        $withholding = match (true) {
            isset($options['withholding_amount']) => Money::of($options['withholding_amount'], $currency),
            $withholdingRate !== null => $amount->percent($withholdingRate->rate),
            default => Money::zero($currency),
        };

        if ($withholding->isGreaterThan($amount)) {
            throw new InvalidOperationException('Tax withheld cannot exceed the payment amount.');
        }

        return Database::transaction(function () use ($direction, $contact, $account, $date, $currency, $amount, $rate, $allocations, $control, $withholdingRate, $withholding, $options) {
            $number = $options['number'] ?? $this->sequences->next(
                'payment:'.$direction->value,
                config('bookkeeper.documents.number_formats.payment_'.$direction->value, strtoupper($direction->value).'-{Y}-{seq}'),
                $date,
            );

            $payment = Payment::create([
                'direction' => $direction,
                'number' => $number,
                'contact_id' => $contact->id,
                'date' => $date,
                'currency' => $currency,
                'exchange_rate' => $rate,
                'amount' => $amount,
                'withholding_tax_rate_id' => $withholdingRate?->id,
                'withholding_amount' => $withholding,
                'account_id' => $account->id,
                'control_account_id' => $control->id,
                'status' => 'posted',
                'method' => $options['method'] ?? null,
                'reference' => $options['reference'] ?? null,
                'memo' => $options['memo'] ?? null,
                'branch_id' => $options['branch_id'] ?? null,
                'source_type' => isset($options['source']) ? $options['source']->getMorphClass() : null,
                'source_id' => isset($options['source']) ? $options['source']->getKey() : null,
                'metadata' => $options['metadata'] ?? null,
            ]);

            $foreign = $currency !== Currency::base();
            $cashSide = $direction === PaymentDirection::Received ? Side::Debit : Side::Credit;
            $controlSide = $cashSide->opposite();

            $netBase = $amount->minus($withholding)->convertTo(Currency::base(), $rate);
            $withholdingBase = $withholding->convertTo(Currency::base(), $rate);

            $lines = [$this->line($account, $cashSide, $amount->minus($withholding), $netBase, $foreign, $rate, 'Payment '.$number)];

            if ($withholding->isPositive()) {
                $lines[] = $this->line($withholdingRate?->account_id ?? '@withholding_tax', $cashSide, $withholding, $withholdingBase, $foreign, $rate, 'Tax withheld') + [
                    'tax_rate' => $withholdingRate?->id,
                    'taxable_amount' => $amount->convertTo(Currency::base(), $rate),
                ];
            }

            $controlBase = Money::zero();
            $reliefs = [];
            $allocated = Money::zero($currency);

            foreach ($allocations as [$document, $allocationAmount]) {
                $relief = $this->documents->baseRelief($document, $allocationAmount);
                $reliefs[] = [$document, $allocationAmount, $relief];
                $lines[] = $this->line($control, $controlSide, $allocationAmount, $relief, $foreign, $rate, $document->number) + ['subledger' => $contact];
                $controlBase = $controlBase->plus($relief);
                $allocated = $allocated->plus($allocationAmount);
            }

            $unallocated = $amount->minus($allocated);
            if ($unallocated->isPositive()) {
                $unallocatedBase = $unallocated->convertTo(Currency::base(), $rate);
                $lines[] = $this->line($control, $controlSide, $unallocated, $unallocatedBase, $foreign, $rate, 'On account') + ['subledger' => $contact];
                $controlBase = $controlBase->plus($unallocatedBase);
            }

            // Realised exchange difference between document and payment rates.
            $difference = $netBase->plus($withholdingBase)->minus($controlBase);
            if (! $difference->isZero()) {
                $lines[] = [
                    'account' => '@fx_gain_loss',
                    ($difference->isPositive() ? $controlSide : $cashSide)->value => $difference->abs(),
                    'memo' => 'Exchange difference',
                ];
            }

            $entry = $this->ledger->record($lines, [
                'date' => $date,
                'memo' => ($options['memo'] ?? null) ?: ucfirst($direction->value).' payment '.$number.' - '.$contact->name,
                'reference' => $options['reference'] ?? $number,
                'branch_id' => $options['branch_id'] ?? null,
                'source' => $payment,
                'idempotency_key' => $options['idempotency_key'] ?? null,
            ]);

            $payment->update(['journal_entry_id' => $entry->id]);

            foreach ($reliefs as [$document, $allocationAmount, $relief]) {
                Allocation::create([
                    'document_id' => $document->id,
                    'payment_id' => $payment->id,
                    'date' => $date,
                    'amount' => $allocationAmount->minor(),
                    'base_amount' => $relief->minor(),
                ]);
                $this->documents->refreshStatus($document);
            }

            event(new PaymentRecorded($payment));

            return $payment->load('allocations');
        });
    }

    /**
     * Allocate an on-account payment to a document later.
     */
    public function allocate(Payment $payment, Document $document, Money|int|float|string|null $amount = null, array $options = []): Allocation
    {
        if ($payment->isVoid()) {
            throw new InvalidOperationException('The payment is void.');
        }

        $amount = $amount === null
            ? $payment->unallocatedAmount()->min($document->amountDue())
            : Money::of($amount, $payment->currency);

        if ($amount->isGreaterThan($payment->unallocatedAmount())) {
            throw new InvalidOperationException('The amount exceeds what is unallocated on the payment.');
        }

        [[, $amount]] = $this->resolveAllocations($payment->direction, $payment->contact, $payment->currency, $amount, [[$document, $amount]]);

        if ($payment->control_account_id !== $this->accounts->findByRole($document->type->controlRole())->id) {
            throw new InvalidOperationException('The payment and document are on different ledgers.');
        }

        return Database::transaction(function () use ($payment, $document, $amount, $options) {
            $relief = $this->documents->baseRelief($document, $amount);
            $atPaymentRate = $amount->convertTo(Currency::base(), (string) $payment->exchange_rate);

            $fx = $this->documents->fxAdjustment(
                $payment->controlAccount,
                $payment->contact,
                $payment->direction === PaymentDirection::Received ? Side::Credit : Side::Debit,
                $relief->minus($atPaymentRate),
                $options['date'] ?? now(),
                $payment,
            );

            $allocation = Allocation::create([
                'document_id' => $document->id,
                'payment_id' => $payment->id,
                'date' => Carbon::parse($options['date'] ?? now()),
                'amount' => $amount->minor(),
                'base_amount' => $relief->minor(),
                'journal_entry_id' => $fx?->id,
            ]);

            $this->documents->refreshStatus($document);

            return $allocation;
        });
    }

    /**
     * Void a payment: reverse its entry and any exchange adjustments, and
     * release its allocations.
     */
    public function void(Payment $payment, array $options = []): Payment
    {
        if ($payment->isVoid()) {
            return $payment;
        }

        return Database::transaction(function () use ($payment, $options) {
            $this->ledger->reverse($payment->journalEntry, [
                'date' => $options['date'] ?? now(),
                'memo' => 'Void payment '.$payment->number,
            ]);

            foreach ($payment->allocations()->with(['document', 'journalEntry'])->get() as $allocation) {
                if ($allocation->journalEntry && ! $allocation->journalEntry->isReversed()) {
                    $this->ledger->reverse($allocation->journalEntry, ['date' => $options['date'] ?? now()]);
                }
                $document = $allocation->document;
                $allocation->delete();
                $this->documents->refreshStatus($document);
            }

            $payment->update(['status' => 'void', 'voided_at' => now()]);

            event(new PaymentVoided($payment));

            return $payment;
        });
    }

    public function findByIdempotencyKey(string $key): ?Payment
    {
        $entryId = $this->ledger->findByIdempotencyKey($key)?->id;

        return $entryId ? Payment::query()->where('journal_entry_id', $entryId)->first() : null;
    }

    /**
     * @return list<array{0: Document, 1: Money}>
     */
    protected function resolveAllocations(PaymentDirection $direction, Contact $contact, string $currency, Money $amount, array $input): array
    {
        $settleable = $direction === PaymentDirection::Received
            ? [DocumentType::Invoice, DocumentType::DebitNote]
            : [DocumentType::Bill, DocumentType::CreditNote];

        $remaining = $amount;
        $result = [];

        foreach ($input as $item) {
            [$document, $value] = $item instanceof Document ? [$item, null] : [$item[0] ?? $item['document'], $item[1] ?? $item['amount'] ?? null];
            $document = $document instanceof Document ? $document->refresh() : Document::query()->findOrFail($document);

            if (! in_array($document->type, $settleable, true)) {
                throw new InvalidOperationException("A {$direction->value} payment cannot settle a {$document->type->value}.");
            }
            if ($document->contact_id !== $contact->id) {
                throw new InvalidOperationException("Document [{$document->number}] belongs to another contact.");
            }
            if ($document->currency !== $currency) {
                throw new InvalidOperationException("Document [{$document->number}] is in {$document->currency}; the payment is in {$currency}.");
            }
            if (! in_array($document->status, [DocumentStatus::Open, DocumentStatus::Partial], true)) {
                throw new InvalidOperationException("Document [{$document->number}] is not outstanding.");
            }

            $value = $value === null ? $document->amountDue()->min($remaining) : Money::of($value, $currency);

            if (! $value->isPositive() || $value->isGreaterThan($document->amountDue())) {
                throw new InvalidOperationException("Allocation to [{$document->number}] must be positive and no more than its amount due.");
            }

            $remaining = $remaining->minus($value);
            if ($remaining->isNegative()) {
                throw new InvalidOperationException('Allocations exceed the payment amount.');
            }

            $result[] = [$document, $value];
        }

        return $result;
    }

    protected function ledgerFor(PaymentDirection $direction, array $allocations, ?string $ledger): string
    {
        $ledgers = array_unique(array_map(fn ($a) => $a[0]->type->isSales() ? 'sales' : 'purchases', $allocations));

        if (count($ledgers) > 1) {
            throw new InvalidOperationException('A payment cannot settle sales and purchase documents together.');
        }

        return $ledgers[0] ?? $ledger ?? ($direction === PaymentDirection::Received ? 'sales' : 'purchases');
    }

    protected function line(Account|int|string $account, Side $side, Money $amount, Money $base, bool $foreign, string $rate, ?string $memo): array
    {
        $line = ['account' => $account, $side->value => $foreign ? $amount : $base, 'memo' => $memo];

        if ($foreign) {
            $line += ['currency' => $amount->currency(), 'exchange_rate' => $rate, 'base_amount' => $base];
        }

        return $line;
    }
}
