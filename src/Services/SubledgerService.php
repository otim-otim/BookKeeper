<?php

namespace OtimOtim\BookKeeper\Services;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\JournalLine;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Subledgers: per-party balances on control accounts (member savings,
 * share capital, loan portfolio, receivables, payables, ...).
 *
 * Every line on a control account names its party, so a party's balance
 * is a view over the ledger and always ties back to the control account.
 */
class SubledgerService
{
    public function __construct(
        protected AccountService $accounts,
        protected BalanceService $balances,
    ) {}

    /**
     * A party's balance on an account, in the account's normal direction.
     */
    public function balance(Account|int|string $account, Model $party, \DateTimeInterface|string|null $asOf = null): Money
    {
        return $this->balances->subledgerBalance($this->accounts->resolve($account), $party, $asOf);
    }

    /**
     * Every party's balance on an account.
     *
     * @return list<array{subledger_type: string, subledger_id: int|string, balance: Money}>
     */
    public function listing(Account|int|string $account, \DateTimeInterface|string|null $asOf = null, bool $includeZero = false): array
    {
        $account = $this->accounts->resolve($account);

        $rows = $this->balances->lineQuery(['accounts' => $this->ids($account), 'to' => $asOf])
            ->whereNotNull('l.subledger_type')
            ->groupBy('l.subledger_type', 'l.subledger_id')
            ->selectRaw('l.subledger_type, l.subledger_id, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->orderBy('l.subledger_type')
            ->orderBy('l.subledger_id')
            ->get();

        $listing = [];
        foreach ($rows as $row) {
            $balance = Money::ofMinor($account->type->balanceFrom((int) $row->debit, (int) $row->credit));
            if ($includeZero || ! $balance->isZero()) {
                $listing[] = [
                    'subledger_type' => $row->subledger_type,
                    'subledger_id' => $row->subledger_id,
                    'balance' => $balance,
                ];
            }
        }

        return $listing;
    }

    /**
     * Compare a control account with the sum of its subledger balances.
     * Lines posted without a party show up as "unassigned".
     *
     * @return array{account: string, control: Money, subledgers: Money, unassigned: Money, difference: Money, balanced: bool}
     */
    public function reconcile(Account|int|string $account, \DateTimeInterface|string|null $asOf = null): array
    {
        $account = $this->accounts->resolve($account);
        $control = $this->balances->balance($account, $asOf);
        $subledgers = Money::sum(array_column($this->listing($account, $asOf, true), 'balance'));

        $unassigned = $this->balances->lineQuery(['accounts' => $this->ids($account), 'to' => $asOf])
            ->whereNull('l.subledger_type')
            ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
            ->first();

        $unassigned = Money::ofMinor($account->type->balanceFrom((int) $unassigned->debit, (int) $unassigned->credit));
        $difference = $control->minus($subledgers)->minus($unassigned);

        return [
            'account' => $account->code,
            'control' => $control,
            'subledgers' => $subledgers,
            'unassigned' => $unassigned,
            'difference' => $difference,
            'balanced' => $difference->isZero() && $unassigned->isZero(),
        ];
    }

    /**
     * A party's statement on an account: opening balance, each line with a
     * running balance, and the closing balance.
     */
    public function statement(Account|int|string $account, Model $party, \DateTimeInterface|string|null $from = null, \DateTimeInterface|string|null $to = null): array
    {
        $account = $this->accounts->resolve($account);
        $opening = $from
            ? $this->balance($account, $party, Carbon::parse($from)->subDay())
            : Money::zero();

        $lines = JournalLine::query()
            ->with('entry')
            ->whereIn('account_id', $this->ids($account))
            ->forSubledger($party)
            ->posted()
            ->whereHas('entry', fn ($q) => $q
                ->when($from, fn ($q) => $q->where('date', '>=', Carbon::parse($from)->toDateString()))
                ->when($to, fn ($q) => $q->where('date', '<=', Carbon::parse($to)->toDateString())))
            ->get()
            ->sortBy(fn (JournalLine $l) => [$l->entry->date->toDateString(), $l->journal_entry_id, $l->id])
            ->values();

        $running = $opening;
        $rows = [];

        foreach ($lines as $line) {
            $running = $running->plus(Money::ofMinor($account->type->balanceFrom($line->debit->minor(), $line->credit->minor())));
            $rows[] = [
                'date' => $line->entry->date->toDateString(),
                'number' => $line->entry->number,
                'reference' => $line->entry->reference,
                'memo' => $line->memo ?? $line->entry->memo,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'balance' => $running,
            ];
        }

        return [
            'account' => ['code' => $account->code, 'name' => $account->name],
            'party' => ['type' => $party->getMorphClass(), 'id' => $party->getKey()],
            'from' => $from ? Carbon::parse($from)->toDateString() : null,
            'to' => $to ? Carbon::parse($to)->toDateString() : null,
            'opening_balance' => $opening,
            'lines' => $rows,
            'closing_balance' => $running,
        ];
    }

    /**
     * Time-weighted (day-weighted) average balance per party over a date
     * range, inclusive. The basis for dividends on shares and interest on
     * savings.
     *
     * @return array<string, array{subledger_type: string, subledger_id: int|string, average: Money, opening: Money, closing: Money}>
     *                                                                                                                                  keyed by "type:id"
     */
    public function averageBalances(Account|int|string $account, \DateTimeInterface|string $from, \DateTimeInterface|string $to, ?Model $party = null): array
    {
        $account = $this->accounts->resolve($account);
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;

        $base = fn () => $this->balances->lineQuery(['accounts' => $this->ids($account), 'subledger' => $party])
            ->whereNotNull('l.subledger_type');

        // Balances carried into the range.
        $openings = $base()
            ->where('e.date', '<', $from->toDateString())
            ->groupBy('l.subledger_type', 'l.subledger_id')
            ->selectRaw('l.subledger_type, l.subledger_id, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get();

        // Daily movements inside the range.
        $movements = $base()
            ->where('e.date', '>=', $from->toDateString())
            ->where('e.date', '<=', $to->toDateString())
            ->groupBy('l.subledger_type', 'l.subledger_id', 'e.date')
            ->selectRaw('l.subledger_type, l.subledger_id, e.date, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get();

        $parties = [];
        foreach ($openings as $row) {
            $key = $row->subledger_type.':'.$row->subledger_id;
            $parties[$key] = [
                'type' => $row->subledger_type,
                'id' => $row->subledger_id,
                'opening' => $account->type->balanceFrom((int) $row->debit, (int) $row->credit),
                'moves' => [],
            ];
        }

        foreach ($movements as $row) {
            $key = $row->subledger_type.':'.$row->subledger_id;
            $parties[$key] ??= ['type' => $row->subledger_type, 'id' => $row->subledger_id, 'opening' => 0, 'moves' => []];
            $date = Carbon::parse($row->date)->toDateString();
            $parties[$key]['moves'][$date] = ($parties[$key]['moves'][$date] ?? 0)
                + $account->type->balanceFrom((int) $row->debit, (int) $row->credit);
        }

        $result = [];
        foreach ($parties as $key => $p) {
            ksort($p['moves']);
            $balance = $p['opening'];
            $weighted = BigDecimal::zero();
            $cursor = $from->copy();

            foreach ($p['moves'] as $date => $move) {
                $moveDate = Carbon::parse($date);
                $weighted = $weighted->plus(BigDecimal::of($balance)->multipliedBy((int) $cursor->diffInDays($moveDate)));
                $balance += $move;
                $cursor = $moveDate;
            }

            $weighted = $weighted->plus(BigDecimal::of($balance)->multipliedBy((int) $cursor->diffInDays($to) + 1));

            $result[$key] = [
                'subledger_type' => $p['type'],
                'subledger_id' => $p['id'],
                'average' => Money::ofMinor($weighted->dividedBy($days, 0, Currency::rounding())->toInt()),
                'opening' => Money::ofMinor($p['opening']),
                'closing' => Money::ofMinor($balance),
            ];
        }

        return $result;
    }

    /**
     * Load the party model for a listing row.
     */
    public function party(string $type, int|string $id): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) ? $class::query()->find($id) : null;
    }

    /**
     * @return list<int>
     */
    protected function ids(Account $account): array
    {
        return $account->is_group ? $account->descendantIds() : [$account->id];
    }
}
