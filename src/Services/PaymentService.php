<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Data\AllocationData;
use OtimOtim\BookKeeper\Data\LineData;
use OtimOtim\BookKeeper\Data\PaymentData;
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
use OtimOtim\BookKeeper\Models\TaxRate;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;
use OtimOtim\BookKeeper\Support\Morph;

/**
 * Customer receipts, vendor payments and refunds, with allocation to
 * documents, withholding tax and realised exchange differences.
 *
 * Received payments settle invoices (and supplier debit notes, i.e. vendor
 * refunds). Made payments settle bills (and customer credit notes, i.e.
 * refunds). Anything not allocated stays on account with the contact.
 */
class PaymentService
{
    public function __construct(
        protected AccountService $accounts,
        protected ContactService $contacts,
        protected LedgerService $ledger,
        protected SettlementService $settlements,
        protected TaxService $taxes,
        protected ExchangeRateService $rates,
        protected SequenceService $sequences,
    ) {}

    /**
     * Money received into $account (bank, cash, mobile money). Options: see PaymentData.
     */
    public function receive(Contact|Model|int $contact, Money|int|float|string $amount, Account|int|string $account, array $options = []): Payment
    {
        return $this->record(PaymentDirection::Received, $contact, $amount, $account, $options);
    }

    /**
     * Money paid out of $account. Options: see PaymentData.
     */
    public function pay(Contact|Model|int $contact, Money|int|float|string $amount, Account|int|string $account, array $options = []): Payment
    {
        return $this->record(PaymentDirection::Made, $contact, $amount, $account, $options);
    }

    public function record(PaymentDirection $direction, Contact|Model|int $contact, Money|int|float|string $amount, Account|int|string $account, array $options = []): Payment
    {
        return $this->recordFrom(PaymentData::fromArray(compact('direction', 'contact', 'amount', 'account') + $options));
    }

    public function recordFrom(PaymentData $data): Payment
    {
        if ($data->idempotencyKey !== null && ($existing = $this->findByIdempotencyKey($data->idempotencyKey))) {
            return $existing;
        }

        $contact = $this->contacts->resolve($data->contact);
        $account = $this->accounts->resolve($data->account);
        $date = ($data->date ?? now())->copy()->startOfDay();
        $currency = Currency::assertValid($data->currency($contact->currency, Currency::base()));
        $amount = $data->amount instanceof Money ? $data->amount : Money::of($data->amount, $currency);
        $rate = $data->exchangeRate ?? $this->rates->rate($currency, $date);

        if (! $amount->isPositive()) {
            throw new InvalidOperationException('A payment amount must be positive.');
        }

        $allocations = $this->resolveAllocations($data->direction, $contact, $amount, $data->allocations);
        $control = $this->accounts->findByRole($this->ledgerFor($data, $allocations) === 'sales' ? 'accounts_receivable' : 'accounts_payable');
        [$withholdingRate, $withholding] = $this->withholding($data, $amount, $date);

        return Database::transaction(function () use ($data, $contact, $account, $date, $amount, $rate, $allocations, $control, $withholdingRate, $withholding) {
            $payment = Payment::create([
                'direction' => $data->direction,
                'number' => $data->number ?? $this->nextNumber($data->direction, $date),
                'contact_id' => $contact->id,
                'date' => $date,
                'currency' => $amount->currency(),
                'exchange_rate' => $rate,
                'amount' => $amount,
                'withholding_tax_rate_id' => $withholdingRate?->id,
                'withholding_amount' => $withholding,
                'account_id' => $account->id,
                'control_account_id' => $control->id,
                'method' => $data->method,
                'reference' => $data->reference,
                'memo' => $data->memo,
                'branch_id' => $data->branchId,
                'metadata' => $data->metadata,
            ] + Morph::attributes($data->source, 'source'));

            [$lines, $reliefs] = $this->journalLines($payment, $account, $control, $contact, $allocations, $withholdingRate);

            $entry = $this->ledger->record($lines, [
                'date' => $date,
                'memo' => $data->memo ?: ucfirst($data->direction->value).' payment '.$payment->number.' - '.$contact->name,
                'reference' => $data->reference ?? $payment->number,
                'branch_id' => $data->branchId,
                'source' => $payment,
                'idempotency_key' => $data->idempotencyKey,
            ]);

            $payment->update(['journal_entry_id' => $entry->id]);

            foreach ($reliefs as [$document, $allocated, $relief]) {
                Allocation::create([
                    'document_id' => $document->id,
                    'payment_id' => $payment->id,
                    'date' => $date,
                    'amount' => $allocated->minor(),
                    'base_amount' => $relief->minor(),
                ]);
                $this->settlements->refreshStatus($document);
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

        [[, $amount]] = $this->resolveAllocations($payment->direction, $payment->contact, $amount, [new AllocationData($document, $amount)]);

        if ($payment->control_account_id !== $this->accounts->findByRole($document->type->controlRole())->id) {
            throw new InvalidOperationException('The payment and document are on different ledgers.');
        }

        return Database::transaction(function () use ($payment, $document, $amount, $options) {
            $relief = $this->settlements->baseRelief($document, $amount);
            $atPaymentRate = $amount->convertTo(Currency::base(), (string) $payment->exchange_rate);

            $fx = $this->settlements->fxAdjustment(
                $payment->controlAccount,
                $payment->contact,
                $this->cashSide($payment->direction)->opposite(),
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

            $this->settlements->refreshStatus($document);

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
            $date = $options['date'] ?? now();
            $this->ledger->reverse($payment->journalEntry, ['date' => $date, 'memo' => 'Void payment '.$payment->number]);

            foreach ($payment->allocations()->with(['document', 'journalEntry'])->get() as $allocation) {
                if ($allocation->journalEntry && ! $allocation->journalEntry->isReversed()) {
                    $this->ledger->reverse($allocation->journalEntry, ['date' => $date]);
                }
                $document = $allocation->document;
                $allocation->delete();
                $this->settlements->refreshStatus($document);
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

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Journal lines for a payment: cash and tax withheld on one side, the
     * control account (per allocation, plus anything on account) on the
     * other, and any exchange difference between document and payment rates.
     *
     * @param  list<array{0: Document, 1: Money}>  $allocations
     * @return array{0: list<LineData>, 1: list<array{0: Document, 1: Money, 2: Money}>}
     */
    protected function journalLines(Payment $payment, Account $account, Account $control, Contact $contact, array $allocations, ?TaxRate $withholdingRate): array
    {
        $rate = (string) $payment->exchange_rate;
        $cashSide = $this->cashSide($payment->direction);
        $controlSide = $cashSide->opposite();
        $toBase = fn (Money $m) => $m->convertTo(Currency::base(), $rate);

        $net = $payment->netAmount();
        $lines = [LineData::converted($account, $cashSide, $net, $toBase($net), $rate, 'Payment '.$payment->number)];
        $cashBase = $toBase($net);

        if ($payment->withholding_amount->isPositive()) {
            $withheldBase = $toBase($payment->withholding_amount);
            $lines[] = LineData::converted($withholdingRate?->account_id ?? '@withholding_tax', $cashSide, $payment->withholding_amount, $withheldBase, $rate, 'Tax withheld',
                taxRate: $withholdingRate?->id,
                taxableAmount: $toBase($payment->amount),
            );
            $cashBase = $cashBase->plus($withheldBase);
        }

        $controlBase = Money::zero();
        $reliefs = [];
        $allocated = Money::zero($payment->currency);

        foreach ($allocations as [$document, $amount]) {
            $relief = $this->settlements->baseRelief($document, $amount);
            $reliefs[] = [$document, $amount, $relief];
            $lines[] = LineData::converted($control, $controlSide, $amount, $relief, $rate, $document->number, subledger: $contact);
            $controlBase = $controlBase->plus($relief);
            $allocated = $allocated->plus($amount);
        }

        $onAccount = $payment->amount->minus($allocated);
        if ($onAccount->isPositive()) {
            $lines[] = LineData::converted($control, $controlSide, $onAccount, $toBase($onAccount), $rate, 'On account', subledger: $contact);
            $controlBase = $controlBase->plus($toBase($onAccount));
        }

        // Realised exchange difference between document and payment rates.
        $difference = $cashBase->minus($controlBase);
        if (! $difference->isZero()) {
            $lines[] = new LineData('@fx_gain_loss', $difference->isPositive() ? $controlSide : $cashSide, $difference->abs(), 'Exchange difference');
        }

        return [$lines, $reliefs];
    }

    /**
     * @return array{0: ?TaxRate, 1: Money}
     */
    protected function withholding(PaymentData $data, Money $amount, Carbon $date): array
    {
        $rate = $data->withholding !== null ? $this->taxes->resolve($data->withholding, $date) : null;

        if ($rate && $rate->type !== TaxType::Withholding) {
            throw new InvalidOperationException("Tax rate [{$rate->code}] is not a withholding rate.");
        }

        $withheld = match (true) {
            $data->withholdingAmount !== null => Money::of($data->withholdingAmount, $amount->currency()),
            $rate !== null => $amount->percent($rate->rate),
            default => Money::zero($amount->currency()),
        };

        if ($withheld->isGreaterThan($amount)) {
            throw new InvalidOperationException('Tax withheld cannot exceed the payment amount.');
        }

        return [$rate, $withheld];
    }

    /**
     * @param  list<AllocationData>  $input
     * @return list<array{0: Document, 1: Money}>
     */
    protected function resolveAllocations(PaymentDirection $direction, Contact $contact, Money $amount, array $input): array
    {
        $settleable = $direction === PaymentDirection::Received
            ? [DocumentType::Invoice, DocumentType::DebitNote]
            : [DocumentType::Bill, DocumentType::CreditNote];

        $remaining = $amount;
        $result = [];

        foreach ($input as $allocation) {
            $document = $allocation->document instanceof Document ? $allocation->document->refresh() : Document::query()->findOrFail($allocation->document);

            $problem = match (true) {
                ! in_array($document->type, $settleable, true) => "A {$direction->value} payment cannot settle a {$document->type->value}.",
                $document->contact_id !== $contact->id => "Document [{$document->number}] belongs to another contact.",
                $document->currency !== $amount->currency() => "Document [{$document->number}] is in {$document->currency}; the payment is in {$amount->currency()}.",
                ! in_array($document->status, [DocumentStatus::Open, DocumentStatus::Partial], true) => "Document [{$document->number}] is not outstanding.",
                default => null,
            };
            if ($problem !== null) {
                throw new InvalidOperationException($problem);
            }

            $value = $allocation->amount === null ? $document->amountDue()->min($remaining) : Money::of($allocation->amount, $amount->currency());

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

    protected function ledgerFor(PaymentData $data, array $allocations): string
    {
        $ledgers = array_values(array_unique(array_map(fn ($a) => $a[0]->type->isSales() ? 'sales' : 'purchases', $allocations)));

        if (count($ledgers) > 1) {
            throw new InvalidOperationException('A payment cannot settle sales and purchase documents together.');
        }

        return $ledgers[0] ?? $data->ledger ?? ($data->direction === PaymentDirection::Received ? 'sales' : 'purchases');
    }

    protected function cashSide(PaymentDirection $direction): Side
    {
        return $direction === PaymentDirection::Received ? Side::Debit : Side::Credit;
    }

    protected function nextNumber(PaymentDirection $direction, Carbon $date): string
    {
        return $this->sequences->next(
            "payment:{$direction->value}",
            config("bookkeeper.documents.number_formats.payment_{$direction->value}", strtoupper($direction->value).'-{Y}-{seq}'),
            $date,
        );
    }
}
