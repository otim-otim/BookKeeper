<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\DocumentStatus;
use OtimOtim\BookKeeper\Enums\DocumentType;
use OtimOtim\BookKeeper\Models\Allocation;
use OtimOtim\BookKeeper\Models\Contact;
use OtimOtim\BookKeeper\Models\Document;
use OtimOtim\BookKeeper\Models\Payment;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Aged receivables and payables, in base currency.
 *
 * Amounts outstanding are as of the report date: documents issued on or
 * before it, less allocations dated on or before it. Credit notes, debit
 * notes and unallocated payments reduce the contact's total.
 */
class AgingService
{
    public function __construct(protected AccountService $accounts) {}

    public function receivables(\DateTimeInterface|string|null $asOf = null, ?Contact $contact = null): array
    {
        return $this->report('sales', $asOf, $contact);
    }

    public function payables(\DateTimeInterface|string|null $asOf = null, ?Contact $contact = null): array
    {
        return $this->report('purchases', $asOf, $contact);
    }

    protected function report(string $ledger, \DateTimeInterface|string|null $asOf, ?Contact $contact): array
    {
        $asOf = Carbon::parse($asOf ?? now())->startOfDay();
        $date = $asOf->toDateString();
        $bounds = config('bookkeeper.documents.ageing_buckets', [0, 30, 60, 90]);
        $labels = $this->labels($bounds);

        $types = $ledger === 'sales'
            ? [DocumentType::Invoice, DocumentType::CreditNote]
            : [DocumentType::Bill, DocumentType::DebitNote];

        $documents = Document::query()
            ->with('contact')
            ->ofType(...$types)
            ->whereNotIn('status', [DocumentStatus::Draft->value])
            ->where('issue_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('voided_at')->orWhere('voided_at', '>', $asOf->copy()->endOfDay()))
            ->when($contact, fn ($q) => $q->where('contact_id', $contact->id))
            ->get();

        $rows = [];
        $zeroBuckets = array_fill_keys($labels, Money::zero());

        foreach ($documents as $document) {
            $applied = (int) Allocation::query()->where('document_id', $document->id)->where('date', '<=', $date)->sum('amount')
                + (int) Allocation::query()->where('credit_document_id', $document->id)->where('date', '<=', $date)->sum('amount');

            $due = Money::ofMinor($document->total->minor() - $applied, $document->currency);
            if ($due->isZero()) {
                continue;
            }

            $base = $due->equals($document->total)
                ? $document->base_total
                : $due->convertTo(Currency::base(), (string) $document->exchange_rate);

            if ($document->type->isCredit()) {
                $base = $base->negated();
            }

            $daysOverdue = $document->due_date->lt($asOf) ? (int) $document->due_date->diffInDays($asOf) : 0;
            $bucket = $this->bucket($daysOverdue, $bounds, $labels);

            $row = &$rows[$document->contact_id];
            $row ??= ['contact_id' => $document->contact_id, 'contact' => $document->contact->name, 'buckets' => $zeroBuckets, 'unallocated' => Money::zero(), 'total' => Money::zero(), 'documents' => []];
            $row['buckets'][$bucket] = $row['buckets'][$bucket]->plus($base);
            $row['total'] = $row['total']->plus($base);
            $row['documents'][] = [
                'id' => $document->id,
                'type' => $document->type->value,
                'number' => $document->number,
                'issue_date' => $document->issue_date->toDateString(),
                'due_date' => $document->due_date->toDateString(),
                'days_overdue' => $daysOverdue,
                'amount_due' => $due,
                'base_amount_due' => $base,
                'bucket' => $bucket,
            ];
            unset($row);
        }

        // Payments not (yet) allocated as of the report date sit on account.
        $controlRole = $ledger === 'sales' ? 'accounts_receivable' : 'accounts_payable';
        $payments = Payment::query()
            ->with('contact')
            ->where('status', 'posted')
            ->where('date', '<=', $date)
            ->where('control_account_id', $this->accounts->findByRole($controlRole)->id)
            ->when($contact, fn ($q) => $q->where('contact_id', $contact->id))
            ->get();

        foreach ($payments as $payment) {
            $allocated = (int) Allocation::query()->where('payment_id', $payment->id)->where('date', '<=', $date)->sum('amount');
            $open = Money::ofMinor($payment->amount->minor() - $allocated, $payment->currency);
            if ($open->isZero()) {
                continue;
            }

            $base = $open->convertTo(Currency::base(), (string) $payment->exchange_rate);
            // Receipts reduce receivables; refunds paid out increase them (and vice versa).
            $reduces = ($ledger === 'sales') === ($payment->direction->value === 'received');
            $base = $reduces ? $base->negated() : $base;

            $row = &$rows[$payment->contact_id];
            $row ??= ['contact_id' => $payment->contact_id, 'contact' => $payment->contact->name, 'buckets' => $zeroBuckets, 'unallocated' => Money::zero(), 'total' => Money::zero(), 'documents' => []];
            $row['unallocated'] = $row['unallocated']->plus($base);
            $row['total'] = $row['total']->plus($base);
            unset($row);
        }

        $totals = ['buckets' => $zeroBuckets, 'unallocated' => Money::zero(), 'total' => Money::zero()];
        foreach ($rows as $row) {
            foreach ($row['buckets'] as $label => $value) {
                $totals['buckets'][$label] = $totals['buckets'][$label]->plus($value);
            }
            $totals['unallocated'] = $totals['unallocated']->plus($row['unallocated']);
            $totals['total'] = $totals['total']->plus($row['total']);
        }

        usort($rows, fn ($a, $b) => strcmp($a['contact'], $b['contact']));

        return ['date' => $date, 'buckets' => $labels, 'contacts' => $rows, 'totals' => $totals];
    }

    /**
     * Bucket labels, e.g. [0, 30, 60, 90] => current, 1-30, 31-60, 61-90, over_90.
     *
     * @return list<string>
     */
    protected function labels(array $bounds): array
    {
        $labels = [];
        $previous = null;

        foreach ($bounds as $bound) {
            $labels[] = $previous === null ? ($bound === 0 ? 'current' : '0-'.$bound) : ($previous + 1).'-'.$bound;
            $previous = $bound;
        }

        $labels[] = 'over_'.$previous;

        return $labels;
    }

    protected function bucket(int $days, array $bounds, array $labels): string
    {
        foreach ($bounds as $i => $bound) {
            if ($days <= $bound) {
                return $labels[$i];
            }
        }

        return end($labels);
    }
}
