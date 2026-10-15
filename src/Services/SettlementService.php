<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Data\LineData;
use OtimOtim\BookKeeper\Enums\DocumentStatus;
use OtimOtim\BookKeeper\Enums\PaymentDirection;
use OtimOtim\BookKeeper\Enums\Side;
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
 * Settlement of documents: allocations of payments and credits, document
 * status, and the base-currency value relieved from receivable and payable
 * control accounts (with exchange differences when rates differ).
 */
class SettlementService
{
    public function __construct(
        protected AccountService $accounts,
        protected LedgerService $ledger,
    ) {}

    /**
     * Apply a credit note to an invoice (or a debit note to a bill).
     */
    public function applyCredit(Document $credit, Document $target, Money|int|float|string|null $amount = null, array $options = []): Allocation
    {
        $this->assertCanApply($credit, $target);

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
                $target->type->isSales() ? Side::Credit : Side::Debit,
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
     * Remove an allocation (payment or credit application), reversing any
     * exchange adjustment it posted.
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

                $this->fxAdjustment(
                    $payment->controlAccount,
                    $payment->contact,
                    $payment->direction === PaymentDirection::Received ? Side::Credit : Side::Debit,
                    $atPaymentRate->minus(Money::ofMinor($allocation->base_amount)),
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
        if (in_array($document->status, [DocumentStatus::Void, DocumentStatus::Draft], true)) {
            return $document;
        }

        $applied = (int) Allocation::query()->where('document_id', $document->id)->sum('amount')
            + (int) Allocation::query()->where('credit_document_id', $document->id)->sum('amount');

        $document->update([
            'paid_total' => $applied,
            'status' => match (true) {
                $applied >= $document->total->minor() => DocumentStatus::Paid,
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
            new LineData($control, $side, $delta->abs(), subledger: $contact),
            new LineData('@fx_gain_loss', $side->opposite(), $delta->abs()),
        ], [
            'date' => $date,
            'memo' => 'Exchange difference on settlement',
            'source' => $source,
        ]);
    }

    protected function assertCanApply(Document $credit, Document $target): void
    {
        $problem = match (true) {
            $credit->type->appliesTo() !== $target->type => "A {$credit->type->value} cannot be applied to a {$target->type->value}.",
            $credit->contact_id !== $target->contact_id => 'Credit and target documents belong to different contacts.',
            $credit->currency !== $target->currency => 'Credit and target documents are in different currencies.',
            ! $credit->status->isOutstanding() || ! $target->status->isOutstanding() => 'Both documents must be issued and not fully settled.',
            default => null,
        };

        if ($problem !== null) {
            throw new InvalidOperationException($problem);
        }
    }
}
