<?php

namespace OtimOtim\BookKeeper\Services;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\DocumentStatus;
use OtimOtim\BookKeeper\Enums\DocumentType;
use OtimOtim\BookKeeper\Enums\Side;
use OtimOtim\BookKeeper\Events\DocumentIssued;
use OtimOtim\BookKeeper\Events\DocumentVoided;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\Allocation;
use OtimOtim\BookKeeper\Models\Contact;
use OtimOtim\BookKeeper\Models\Document;
use OtimOtim\BookKeeper\Models\JournalEntry;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Sales invoices and credit notes, supplier bills and debit notes.
 *
 * A document line is an array with:
 *  - account: revenue account (sales) or expense/asset account (purchases)
 *  - description
 *  - quantity (default 1) and unit_price, or amount
 *  - discount: amount off the line
 *  - tax: TaxRate, ID or code
 *
 * Options: issue_date, due_date, currency, exchange_rate, tax_inclusive,
 * number, reference, notes, branch_id, source (model), metadata, draft
 * (save without issuing), idempotency_key.
 */
class DocumentService
{
    public function __construct(
        protected AccountService $accounts,
        protected ContactService $contacts,
        protected LedgerService $ledger,
        protected TaxService $taxes,
        protected ExchangeRateService $rates,
        protected SequenceService $sequences,
    ) {}

    public function invoice(Contact|Model|int $contact, array $lines, array $options = []): Document
    {
        return $this->create(DocumentType::Invoice, $contact, $lines, $options);
    }

    public function creditNote(Contact|Model|int $contact, array $lines, array $options = []): Document
    {
        return $this->create(DocumentType::CreditNote, $contact, $lines, $options);
    }

    public function bill(Contact|Model|int $contact, array $lines, array $options = []): Document
    {
        return $this->create(DocumentType::Bill, $contact, $lines, $options);
    }

    public function debitNote(Contact|Model|int $contact, array $lines, array $options = []): Document
    {
        return $this->create(DocumentType::DebitNote, $contact, $lines, $options);
    }

    /**
     * Create a document and issue it (unless $options['draft'] is true).
     */
    public function create(DocumentType|string $type, Contact|Model|int $contact, array $lines, array $options = []): Document
    {
        $type = $type instanceof DocumentType ? $type : DocumentType::from($type);
        $contact = $this->contacts->resolve($contact);

        if ($lines === []) {
            throw new InvalidOperationException('A document needs at least one line.');
        }

        return Database::transaction(function () use ($type, $contact, $lines, $options) {
            $issueDate = Carbon::parse($options['issue_date'] ?? now())->startOfDay();
            $currency = strtoupper($options['currency'] ?? $contact->currency ?? Currency::base());
            $terms = $options['payment_terms_days'] ?? $contact->payment_terms_days ?? config('bookkeeper.documents.default_payment_terms_days', 30);

            $document = Document::create([
                'type' => $type,
                'number' => $options['number'] ?? null,
                'contact_id' => $contact->id,
                'issue_date' => $issueDate,
                'due_date' => Carbon::parse($options['due_date'] ?? $issueDate->copy()->addDays((int) $terms)),
                'currency' => $currency,
                'exchange_rate' => (string) ($options['exchange_rate'] ?? $this->rates->rate($currency, $issueDate)),
                'tax_inclusive' => (bool) ($options['tax_inclusive'] ?? false),
                'status' => DocumentStatus::Draft,
                'reference' => $options['reference'] ?? null,
                'notes' => $options['notes'] ?? null,
                'branch_id' => $options['branch_id'] ?? null,
                'source_type' => isset($options['source']) ? $options['source']->getMorphClass() : null,
                'source_id' => isset($options['source']) ? $options['source']->getKey() : null,
                'metadata' => $options['metadata'] ?? null,
            ]);

            $this->writeLines($document, $lines);

            return ($options['draft'] ?? false)
                ? $document->load('lines')
                : $this->issue($document, $options);
        });
    }

    /**
     * Replace a draft's lines.
     */
    public function updateDraft(Document $document, array $lines, array $attributes = []): Document
    {
        if ($document->status !== DocumentStatus::Draft) {
            throw new InvalidOperationException('Only draft documents can be edited.');
        }

        return Database::transaction(function () use ($document, $lines, $attributes) {
            $document->fill(array_intersect_key($attributes, array_flip(['issue_date', 'due_date', 'reference', 'notes', 'tax_inclusive', 'metadata'])))->save();
            $document->lines()->delete();
            $this->writeLines($document, $lines);

            return $document->load('lines');
        });
    }

    /**
     * Post a draft document to the ledger.
     *
     * Invoice: Dr receivable (total), Cr revenue (net), Cr output tax.
     * Bill:    Dr expense (net, plus non-recoverable tax), Dr input tax, Cr payable (total).
     * Credit and debit notes post the mirror image.
     */
    public function issue(Document $document, array $options = []): Document
    {
        if ($document->status !== DocumentStatus::Draft) {
            throw new InvalidOperationException("Document [{$document->number}] has already been issued.");
        }

        return Database::transaction(function () use ($document, $options) {
            $document->loadMissing(['lines.taxRate', 'contact']);
            $type = $document->type;
            $rate = (string) $document->exchange_rate;
            $foreign = $document->currency !== Currency::base();

            $number = $document->number ?? $this->sequences->next(
                'document:'.$type->value,
                config("bookkeeper.documents.number_formats.{$type->value}", strtoupper($type->value).'-{Y}-{seq}'),
                $document->issue_date,
            );

            // The control account carries the contact's balance: debit for
            // invoices, credit for bills, and the reverse for credit and debit notes.
            $controlSide = ($type->isSales() xor $type->isCredit()) ? Side::Debit : Side::Credit;
            $itemSide = $controlSide->opposite();
            $sign = $type->isCredit() ? -1 : 1;

            $journal = [];
            $baseTotal = Money::zero();

            foreach ($document->lines as $line) {
                $net = $line->net_amount;
                $tax = $line->tax_amount;
                $taxRate = $line->taxRate;

                // Non-recoverable purchase tax is part of the cost.
                if ($taxRate && $type->isPurchase() && ! $taxRate->isRecoverable()) {
                    $net = $net->plus($tax);
                    $tax = Money::zero($document->currency);
                }

                $netBase = $net->convertTo(Currency::base(), $rate);
                $journal[] = $this->line($line->account_id, $itemSide, $net, $netBase, $foreign, $rate, $line->description);
                $baseTotal = $baseTotal->plus($netBase);

                if (! $tax->isZero()) {
                    $taxBase = $tax->convertTo(Currency::base(), $rate);
                    $journal[] = $this->line($taxRate->account_id, $itemSide, $tax, $taxBase, $foreign, $rate, $taxRate->name) + [
                        'tax_rate' => $taxRate->id,
                        'taxable_amount' => Money::ofMinor($sign * $netBase->minor()),
                    ];
                    $baseTotal = $baseTotal->plus($taxBase);
                }
            }

            $control = $this->accounts->findByRole($type->controlRole());
            $journal[] = $this->line($control, $controlSide, $document->total, $baseTotal, $foreign, $rate, null) + [
                'subledger' => $document->contact,
            ];

            $entry = $this->ledger->record($journal, [
                'date' => $document->issue_date,
                'memo' => ucfirst(str_replace('_', ' ', $type->value)).' '.$number.' - '.$document->contact->name,
                'reference' => $number,
                'branch_id' => $document->branch_id,
                'source' => $document,
                'idempotency_key' => $options['idempotency_key'] ?? null,
            ]);

            $document->update([
                'number' => $number,
                'status' => $document->total->isZero() ? DocumentStatus::Paid : DocumentStatus::Open,
                'base_total' => $baseTotal,
                'journal_entry_id' => $entry->id,
                'issued_at' => now(),
            ]);

            event(new DocumentIssued($document));

            return $document->load('lines');
        });
    }

    /**
     * Void a document: drafts are deleted, issued documents are reversed.
     * Documents with payments or credits applied must be unapplied first.
     */
    public function void(Document $document, array $options = []): Document
    {
        if ($document->status === DocumentStatus::Void) {
            return $document;
        }

        if ($document->status === DocumentStatus::Draft) {
            $document->delete();

            return $document;
        }

        if ($document->allocations()->exists() || $document->creditAllocations()->exists()) {
            throw new InvalidOperationException("Document [{$document->number}] has payments or credits applied; remove them first.");
        }

        return Database::transaction(function () use ($document, $options) {
            $this->ledger->reverse($document->journalEntry, [
                'date' => $options['date'] ?? now(),
                'memo' => 'Void '.$document->number,
            ]);

            $document->update(['status' => DocumentStatus::Void, 'voided_at' => now()]);

            event(new DocumentVoided($document));

            return $document;
        });
    }

    /**
     * Apply a credit note to an invoice (or a debit note to a bill).
     */
    public function applyCredit(Document $credit, Document $target, Money|int|float|string|null $amount = null, array $options = []): Allocation
    {
        if ($credit->type->appliesTo() !== $target->type) {
            throw new InvalidOperationException("A {$credit->type->value} cannot be applied to a {$target->type->value}.");
        }
        if ($credit->contact_id !== $target->contact_id) {
            throw new InvalidOperationException('Credit and target documents belong to different contacts.');
        }
        if ($credit->currency !== $target->currency) {
            throw new InvalidOperationException('Credit and target documents are in different currencies.');
        }
        if (! $credit->status->isOutstanding() || ! $target->status->isOutstanding()) {
            throw new InvalidOperationException('Both documents must be issued and not fully settled.');
        }

        $amount = $amount === null
            ? $credit->amountDue()->min($target->amountDue())
            : Money::of($amount, $target->currency);

        if (! $amount->isPositive() || $amount->isGreaterThan($credit->amountDue()) || $amount->isGreaterThan($target->amountDue())) {
            throw new InvalidOperationException('The amount must be positive and no more than either document has outstanding.');
        }

        return Database::transaction(function () use ($credit, $target, $amount, $options) {
            $date = Carbon::parse($options['date'] ?? now());
            $relief = $this->baseRelief($target, $amount);
            $consumed = $this->baseRelief($credit, $amount);

            // Rates differ between the two documents: settle the base difference.
            $fx = $this->fxAdjustment(
                $this->accounts->findByRole($target->type->controlRole()),
                $target->contact,
                ($target->type->isSales() ? Side::Credit : Side::Debit),
                $relief->minus($consumed),
                $date,
                $target,
            );

            $allocation = Allocation::create([
                'document_id' => $target->id,
                'credit_document_id' => $credit->id,
                'date' => $date,
                'amount' => $amount->minor(),
                'base_amount' => $relief->minor(),
                'credit_base_amount' => $consumed->minor(),
                'journal_entry_id' => $fx?->id,
            ]);

            $this->refreshStatus($target);
            $this->refreshStatus($credit);

            return $allocation;
        });
    }

    /**
     * Remove an allocation (payment or credit application) from a document,
     * reversing any FX adjustment it posted.
     */
    public function unapply(Allocation $allocation): void
    {
        Database::transaction(function () use ($allocation) {
            if ($allocation->journalEntry && ! $allocation->journalEntry->isReversed()) {
                $this->ledger->reverse($allocation->journalEntry, ['memo' => 'Unapply allocation #'.$allocation->id]);
            }

            if ($allocation->payment_id && ! $allocation->journal_entry_id) {
                // Allocated when the payment was recorded, so the payment entry
                // relieved the control account at the document's value. The payment
                // now stays on account at its own rate: move the difference back.
                $payment = $allocation->payment;
                $atPaymentRate = Money::ofMinor($allocation->amount, $payment->currency)->convertTo(Currency::base(), (string) $payment->exchange_rate);
                $delta = $atPaymentRate->minus(Money::ofMinor($allocation->base_amount));
                $this->fxAdjustment(
                    $payment->controlAccount,
                    $payment->contact,
                    $payment->direction->value === 'received' ? Side::Credit : Side::Debit,
                    $delta,
                    now(),
                    $payment,
                );
            }

            $documents = array_filter([$allocation->document, $allocation->creditDocument]);
            $allocation->delete();

            foreach ($documents as $document) {
                $this->refreshStatus($document);
            }
        });
    }

    /**
     * Recompute paid_total and status from allocations.
     */
    public function refreshStatus(Document $document): Document
    {
        if ($document->status === DocumentStatus::Void || $document->status === DocumentStatus::Draft) {
            return $document;
        }

        $applied = (int) Allocation::query()->where('document_id', $document->id)->sum('amount')
            + (int) Allocation::query()->where('credit_document_id', $document->id)->sum('amount');

        $total = $document->total->minor();

        $document->update([
            'paid_total' => $applied,
            'status' => match (true) {
                $applied >= $total => DocumentStatus::Paid,
                $applied > 0 => DocumentStatus::Partial,
                default => DocumentStatus::Open,
            },
        ]);

        return $document;
    }

    /**
     * Base-currency value to relieve from the control account when $amount
     * of a document is settled. The final settlement takes whatever is left
     * so the document clears to exactly zero in base currency.
     */
    public function baseRelief(Document $document, Money $amount): Money
    {
        if ($amount->equals($document->amountDue())) {
            $relieved = (int) Allocation::query()->where('document_id', $document->id)->sum('base_amount')
                + (int) Allocation::query()->where('credit_document_id', $document->id)->sum('credit_base_amount');

            return Money::ofMinor($document->base_total->minor() - $relieved);
        }

        return $amount->convertTo(Currency::base(), (string) $document->exchange_rate);
    }

    /**
     * Post a base-currency adjustment between a control account (for one
     * contact) and the "fx_gain_loss" role. $delta is the extra amount to
     * post on $controlSide of the control account; negative goes the other way.
     */
    public function fxAdjustment(Account $control, Contact $contact, Side $controlSide, Money $delta, \DateTimeInterface|string $date, ?Model $source = null): ?JournalEntry
    {
        if ($delta->isZero()) {
            return null;
        }

        $side = $delta->isPositive() ? $controlSide : $controlSide->opposite();

        return $this->ledger->record([
            ['account' => $control, $side->value => $delta->abs(), 'subledger' => $contact],
            ['account' => '@fx_gain_loss', $side->opposite()->value => $delta->abs()],
        ], [
            'date' => $date,
            'memo' => 'Exchange difference on settlement',
            'source' => $source,
        ]);
    }

    protected function writeLines(Document $document, array $lines): void
    {
        $subtotal = Money::zero($document->currency);
        $taxTotal = Money::zero($document->currency);

        foreach (array_values($lines) as $position => $input) {
            $line = $this->computeLine($document, $input);
            $document->lines()->create($line + ['position' => $position]);
            $subtotal = $subtotal->plus(Money::ofMinor($line['net_amount'], $document->currency));
            $taxTotal = $taxTotal->plus(Money::ofMinor($line['tax_amount'], $document->currency));
        }

        $document->update([
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $subtotal->plus($taxTotal),
        ]);
    }

    protected function computeLine(Document $document, array $input): array
    {
        $currency = $document->currency;
        $account = $this->accounts->resolve($input['account'] ?? throw new InvalidOperationException('Every document line needs an account.'));

        if ($account->is_group || ! $account->is_active) {
            throw new InvalidOperationException("Account [{$account->code}] cannot be used on a document line.");
        }

        $quantity = BigDecimal::of((string) ($input['quantity'] ?? '1'));

        if (isset($input['amount'])) {
            $unitPrice = Money::of($input['amount'], $currency);
            $quantity = BigDecimal::one();
        } else {
            $unitPrice = Money::of($input['unit_price'] ?? throw new InvalidOperationException('A document line needs unit_price or amount.'), $currency);
        }

        $discount = Money::of($input['discount'] ?? 0, $currency);
        $gross = $unitPrice->multipliedBy($quantity)->minus($discount);

        $taxRate = isset($input['tax']) ? $this->taxes->resolve($input['tax'], $document->issue_date) : null;

        if ($taxRate && $taxRate->type !== $document->type->taxType()) {
            throw new InvalidOperationException("Tax rate [{$taxRate->code}] is a {$taxRate->type->value} rate and cannot be used on a {$document->type->value}.");
        }

        if ($taxRate === null) {
            [$net, $tax] = [$gross, Money::zero($currency)];
        } elseif ($document->tax_inclusive) {
            $tax = $taxRate->taxIn($gross);
            $net = $gross->minus($tax);
        } else {
            $net = $gross;
            $tax = $taxRate->taxOn($net);
        }

        if ($net->isNegative()) {
            throw new InvalidOperationException('A document line cannot have a negative amount.');
        }

        return [
            'account_id' => $account->id,
            'description' => $input['description'] ?? null,
            'quantity' => (string) $quantity,
            'unit_price' => $unitPrice->minor(),
            'discount' => $discount->minor(),
            'tax_rate_id' => $taxRate?->id,
            'net_amount' => $net->minor(),
            'tax_amount' => $tax->minor(),
            'total' => $net->plus($tax)->minor(),
        ];
    }

    /**
     * A ledger line for an amount in the document currency with a fixed
     * base-currency value.
     */
    protected function line(Account|int $account, Side $side, Money $amount, Money $base, bool $foreign, string $rate, ?string $memo): array
    {
        $line = ['account' => $account, $side->value => $foreign ? $amount : $base, 'memo' => $memo];

        if ($foreign) {
            $line += ['currency' => $amount->currency(), 'exchange_rate' => $rate, 'base_amount' => $base];
        }

        return $line;
    }
}
