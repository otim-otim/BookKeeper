<?php

namespace OtimOtim\BookKeeper\Services;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use OtimOtim\BookKeeper\Data\DocumentData;
use OtimOtim\BookKeeper\Data\DocumentLineData;
use OtimOtim\BookKeeper\Data\LineData;
use OtimOtim\BookKeeper\Enums\DocumentStatus;
use OtimOtim\BookKeeper\Enums\DocumentType;
use OtimOtim\BookKeeper\Enums\Side;
use OtimOtim\BookKeeper\Events\DocumentIssued;
use OtimOtim\BookKeeper\Events\DocumentVoided;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Allocation;
use OtimOtim\BookKeeper\Models\Contact;
use OtimOtim\BookKeeper\Models\Document;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;
use OtimOtim\BookKeeper\Support\Morph;

/**
 * Sales invoices and credit notes, supplier bills and debit notes.
 *
 * Documents are described by DocumentData (or the equivalent arrays).
 * Settling them with payments and credits is SettlementService's job.
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
        protected SettlementService $settlements,
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
     * Create a document from its parts (see DocumentData::fromArray).
     */
    public function create(DocumentType|string $type, Contact|Model|int $contact, array $lines, array $options = []): Document
    {
        return $this->createFrom(DocumentData::fromArray(['type' => $type, 'contact' => $contact, 'lines' => $lines] + $options));
    }

    /**
     * Create a document and issue it, unless it is a draft.
     */
    public function createFrom(DocumentData $data): Document
    {
        $contact = $this->contacts->resolve($data->contact);

        return Database::transaction(function () use ($data, $contact) {
            $issueDate = ($data->issueDate ?? now())->copy()->startOfDay();
            $currency = Currency::assertValid($data->currency ?? $contact->currency ?? Currency::base());
            $terms = $data->paymentTermsDays ?? $contact->payment_terms_days ?? config('bookkeeper.documents.default_payment_terms_days', 30);

            $document = Document::create([
                'type' => $data->type,
                'number' => $data->number,
                'contact_id' => $contact->id,
                'issue_date' => $issueDate,
                'due_date' => $data->dueDate ?? $issueDate->copy()->addDays((int) $terms),
                'currency' => $currency,
                'exchange_rate' => $data->exchangeRate ?? $this->rates->rate($currency, $issueDate),
                'tax_inclusive' => $data->taxInclusive,
                'status' => DocumentStatus::Draft,
                'reference' => $data->reference,
                'notes' => $data->notes,
                'branch_id' => $data->branchId,
                'metadata' => $data->metadata,
            ] + Morph::attributes($data->source, 'source'));

            $this->writeLines($document, $data->lines, $data->cashRounding);

            return $data->draft
                ? $document->load('lines')
                : $this->issue($document, ['idempotency_key' => $data->idempotencyKey]);
        });
    }

    /**
     * Replace a draft's lines (and optionally some header fields).
     */
    public function updateDraft(Document $document, array $lines, array $attributes = []): Document
    {
        if ($document->status !== DocumentStatus::Draft) {
            throw new InvalidOperationException('Only draft documents can be edited.');
        }

        return Database::transaction(function () use ($document, $lines, $attributes) {
            $document->fill(array_intersect_key($attributes, array_flip(['issue_date', 'due_date', 'reference', 'notes', 'tax_inclusive', 'metadata'])))->save();
            $document->lines()->delete();
            $this->writeLines($document, DocumentLineData::collect($lines), $attributes['cash_rounding'] ?? null);

            return $document->load('lines');
        });
    }

    /**
     * Post a draft document to the ledger.
     *
     * Invoice: Dr receivable (total), Cr revenue (net), Cr output tax.
     * Bill:    Dr expense (net, plus non-recoverable tax), Dr input tax, Cr payable (total).
     * Credit and debit notes post the mirror image. Cash rounding goes to
     * the "cash_rounding" role.
     */
    public function issue(Document $document, array $options = []): Document
    {
        if ($document->status !== DocumentStatus::Draft) {
            throw new InvalidOperationException("Document [{$document->number}] has already been issued.");
        }

        return Database::transaction(function () use ($document, $options) {
            $document->loadMissing(['lines.taxRate', 'contact']);
            $number = $document->number ?? $this->nextNumber($document);
            [$lines, $baseTotal] = $this->journalLines($document);

            $entry = $this->ledger->record($lines, [
                'date' => $document->issue_date,
                'memo' => ucfirst(str_replace('_', ' ', $document->type->value)).' '.$number.' - '.$document->contact->name,
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

    // -----------------------------------------------------------------------
    // Settlement (delegated; kept here for a convenient API)
    // -----------------------------------------------------------------------

    public function applyCredit(Document $credit, Document $target, Money|int|float|string|null $amount = null, array $options = []): Allocation
    {
        return $this->settlements->applyCredit($credit, $target, $amount, $options);
    }

    public function unapply(Allocation $allocation): void
    {
        $this->settlements->unapply($allocation);
    }

    public function refreshStatus(Document $document): Document
    {
        return $this->settlements->refreshStatus($document);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The journal lines for a document and the base-currency total they put
     * on the control account.
     *
     * @return array{0: list<LineData>, 1: Money}
     */
    protected function journalLines(Document $document): array
    {
        $type = $document->type;
        $rate = (string) $document->exchange_rate;

        // The control account carries the contact's balance: debit for
        // invoices, credit for bills, and the reverse for credit and debit notes.
        $controlSide = ($type->isSales() xor $type->isCredit()) ? Side::Debit : Side::Credit;
        $itemSide = $controlSide->opposite();
        $sign = $type->isCredit() ? -1 : 1;

        $lines = [];
        $baseTotal = Money::zero();

        foreach ($document->lines as $line) {
            [$net, $tax] = [$line->net_amount, $line->tax_amount];
            $taxRate = $line->taxRate;

            // Non-recoverable purchase tax is part of the cost.
            if ($taxRate && $type->isPurchase() && ! $taxRate->isRecoverable()) {
                [$net, $tax] = [$net->plus($tax), Money::zero($document->currency)];
            }

            $netBase = $net->convertTo(Currency::base(), $rate);
            $lines[] = LineData::converted($line->account_id, $itemSide, $net, $netBase, $rate, $line->description);
            $baseTotal = $baseTotal->plus($netBase);

            if (! $tax->isZero()) {
                $taxBase = $tax->convertTo(Currency::base(), $rate);
                $lines[] = LineData::converted($taxRate->account_id, $itemSide, $tax, $taxBase, $rate, $taxRate->name,
                    taxRate: $taxRate->id,
                    taxableAmount: Money::ofMinor($sign * $netBase->minor()),
                );
                $baseTotal = $baseTotal->plus($taxBase);
            }
        }

        if (! $document->rounding->isZero()) {
            $roundingBase = $document->rounding->abs()->convertTo(Currency::base(), $rate);
            $lines[] = LineData::converted(
                '@cash_rounding',
                $document->rounding->isPositive() ? $itemSide : $controlSide,
                $document->rounding->abs(),
                $roundingBase,
                $rate,
                'Cash rounding',
            );
            $baseTotal = $document->rounding->isPositive() ? $baseTotal->plus($roundingBase) : $baseTotal->minus($roundingBase);
        }

        $control = $this->accounts->findByRole($type->controlRole());
        $lines[] = LineData::converted($control, $controlSide, $document->total, $baseTotal, $rate, subledger: $document->contact);

        return [$lines, $baseTotal];
    }

    /**
     * @param  list<DocumentLineData>  $lines
     */
    protected function writeLines(Document $document, array $lines, ?bool $cashRounding): void
    {
        $subtotal = Money::zero($document->currency);
        $taxTotal = Money::zero($document->currency);

        foreach ($lines as $position => $line) {
            $row = $this->computeLine($document, $line);
            $document->lines()->create($row + ['position' => $position]);
            $subtotal = $subtotal->plus(Money::ofMinor($row['net_amount'], $document->currency));
            $taxTotal = $taxTotal->plus(Money::ofMinor($row['tax_amount'], $document->currency));
        }

        $total = $subtotal->plus($taxTotal);
        $rounded = ($cashRounding ?? config('bookkeeper.documents.cash_rounding', false)) ? $total->roundToCash() : $total;

        $document->update([
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'rounding' => $rounded->minus($total),
            'total' => $rounded,
        ]);
    }

    protected function computeLine(Document $document, DocumentLineData $input): array
    {
        $currency = $document->currency;
        $account = $this->accounts->resolve($input->account);

        if ($account->is_group || ! $account->is_active) {
            throw new InvalidOperationException("Account [{$account->code}] cannot be used on a document line.");
        }

        $unitPrice = Money::of($input->unitPrice instanceof Money ? $input->unitPrice->amount() : $input->unitPrice, $currency);
        $discount = Money::of($input->discount instanceof Money ? $input->discount->amount() : $input->discount, $currency);
        $gross = $unitPrice->multipliedBy(BigDecimal::of($input->quantity))->minus($discount);

        $taxRate = $input->tax !== null ? $this->taxes->resolve($input->tax, $document->issue_date) : null;

        if ($taxRate && $taxRate->type !== $document->type->taxType()) {
            throw new InvalidOperationException("Tax rate [{$taxRate->code}] is a {$taxRate->type->value} rate and cannot be used on a {$document->type->value}.");
        }

        $tax = match (true) {
            $taxRate === null => Money::zero($currency),
            $document->tax_inclusive => $taxRate->taxIn($gross),
            default => $taxRate->taxOn($gross),
        };
        $net = $document->tax_inclusive ? $gross->minus($tax) : $gross;

        if ($net->isNegative()) {
            throw new InvalidOperationException('A document line cannot have a negative amount.');
        }

        return [
            'account_id' => $account->id,
            'description' => $input->description,
            'quantity' => $input->quantity,
            'unit_price' => $unitPrice->minor(),
            'discount' => $discount->minor(),
            'tax_rate_id' => $taxRate?->id,
            'net_amount' => $net->minor(),
            'tax_amount' => $tax->minor(),
            'total' => $net->plus($tax)->minor(),
        ];
    }

    protected function nextNumber(Document $document): string
    {
        $type = $document->type->value;

        return $this->sequences->next(
            "document:{$type}",
            config("bookkeeper.documents.number_formats.{$type}", strtoupper($type).'-{Y}-{seq}'),
            $document->issue_date,
        );
    }
}
