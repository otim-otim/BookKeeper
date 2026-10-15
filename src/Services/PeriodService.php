<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\CloseType;
use OtimOtim\BookKeeper\Enums\PeriodStatus;
use OtimOtim\BookKeeper\Events\PeriodClosed;
use OtimOtim\BookKeeper\Events\PeriodReopened;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Exceptions\PeriodClosedException;
use OtimOtim\BookKeeper\Models\CloseRun;
use OtimOtim\BookKeeper\Models\FiscalYear;
use OtimOtim\BookKeeper\Models\Period;
use OtimOtim\BookKeeper\Support\Database;

/**
 * Fiscal years and monthly accounting periods, and the rule that nothing
 * posts into a closed period.
 */
class PeriodService
{
    /**
     * Create a fiscal year with twelve monthly periods.
     *
     * $year is the calendar year the fiscal year starts in; the start month
     * comes from config('bookkeeper.fiscal_year.start_month') unless a start
     * date is given in $options['start_date'].
     */
    public function createFiscalYear(int|\DateTimeInterface|string $year, array $options = []): FiscalYear
    {
        $start = match (true) {
            isset($options['start_date']) => Carbon::parse($options['start_date'])->startOfDay(),
            is_int($year) => Carbon::create($year, $this->startMonth(), 1)->startOfDay(),
            default => Carbon::parse($year)->startOfDay(),
        };
        $end = $start->copy()->addYear()->subDay();

        $overlap = FiscalYear::query()
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->first();

        if ($overlap) {
            throw new InvalidOperationException("Fiscal year {$overlap->name} already covers part of {$start->toDateString()} to {$end->toDateString()}.");
        }

        $name = $options['name'] ?? ($start->month === 1
            ? 'FY'.$start->year
            : 'FY'.$start->year.'/'.substr((string) $end->year, -2));

        return Database::transaction(function () use ($start, $end, $name) {
            $fiscalYear = FiscalYear::create([
                'name' => $name,
                'start_date' => $start,
                'end_date' => $end,
                'status' => PeriodStatus::Open,
            ]);

            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                $fiscalYear->periods()->create([
                    'name' => $cursor->format('M Y'),
                    'start_date' => $cursor->copy(),
                    'end_date' => $cursor->copy()->endOfMonth(),
                    'status' => PeriodStatus::Open,
                ]);
                $cursor->addMonthNoOverflow()->startOfMonth();
            }

            return $fiscalYear->load('periods');
        });
    }

    public function fiscalYears(): Collection
    {
        return FiscalYear::query()->orderBy('start_date')->get();
    }

    public function fiscalYearFor(\DateTimeInterface|string $date): ?FiscalYear
    {
        $date = Carbon::parse($date)->toDateString();

        return FiscalYear::query()->where('start_date', '<=', $date)->where('end_date', '>=', $date)->first();
    }

    public function currentFiscalYear(): ?FiscalYear
    {
        return $this->fiscalYearFor(now());
    }

    public function periodFor(\DateTimeInterface|string $date): ?Period
    {
        $date = Carbon::parse($date)->toDateString();

        return Period::query()->where('start_date', '<=', $date)->where('end_date', '>=', $date)->first();
    }

    /**
     * Find a fiscal year by model, ID, name ("FY2026") or the calendar year
     * it starts in (2026). Integers above 1900 are years, others are IDs.
     * A missing year is created when auto-creation is enabled.
     */
    public function resolveFiscalYear(FiscalYear|int|string $fiscalYear): FiscalYear
    {
        if ($fiscalYear instanceof FiscalYear) {
            return $fiscalYear;
        }

        $isYear = is_int($fiscalYear) && $fiscalYear > 1900;

        $found = match (true) {
            $isYear => FiscalYear::query()->whereYear('start_date', $fiscalYear)->orderBy('start_date')->first(),
            is_int($fiscalYear) => FiscalYear::query()->find($fiscalYear),
            default => FiscalYear::query()->where('name', $fiscalYear)->first(),
        };

        if ($found === null && $isYear && config('bookkeeper.fiscal_year.auto_create', true)) {
            $found = $this->createFiscalYear($fiscalYear);
        }

        return $found ?? throw new InvalidOperationException("Fiscal year [{$fiscalYear}] not found.");
    }

    public function resolvePeriod(Period|int|\DateTimeInterface|string $period): Period
    {
        if ($period instanceof Period) {
            return $period;
        }

        $found = is_int($period) ? Period::query()->find($period) : $this->periodFor($period);

        if ($found === null && ! is_int($period) && config('bookkeeper.fiscal_year.auto_create', true)) {
            $this->createFiscalYear($this->fiscalYearStartFor(Carbon::parse($period)));
            $found = $this->periodFor($period);
        }

        return $found ?? throw new InvalidOperationException("Accounting period [{$period}] not found.");
    }

    /**
     * Return the open period covering $date, creating the fiscal year when
     * auto-creation is enabled.
     *
     * @throws PeriodClosedException
     */
    public function assertOpen(\DateTimeInterface|string $date): Period
    {
        $date = Carbon::parse($date);
        $period = $this->periodFor($date);

        if ($period === null) {
            if (! config('bookkeeper.fiscal_year.auto_create', true)) {
                throw PeriodClosedException::noPeriod($date->toDateString());
            }

            $this->createFiscalYear($this->fiscalYearStartFor($date));
            $period = $this->periodFor($date);
        }

        if (! $period->isOpen() || ! $period->fiscalYear->isOpen()) {
            throw PeriodClosedException::forDate($date->toDateString());
        }

        return $period;
    }

    public function isOpen(\DateTimeInterface|string $date): bool
    {
        $period = $this->periodFor($date);

        return $period === null
            ? (bool) config('bookkeeper.fiscal_year.auto_create', true)
            : $period->isOpen() && $period->fiscalYear->isOpen();
    }

    /**
     * Lock a period against posting. Use CloseService::closeMonth() to also
     * run month-end tasks.
     */
    public function close(Period|int|\DateTimeInterface|string $period): Period
    {
        $period = $this->resolvePeriod($period);

        if ($period->isOpen()) {
            $period->update(['status' => PeriodStatus::Closed, 'closed_at' => now()]);
            event(new PeriodClosed($period));
        }

        return $period;
    }

    public function reopen(Period|int|\DateTimeInterface|string $period): Period
    {
        $period = $this->resolvePeriod($period);

        if (! $period->fiscalYear->isOpen()) {
            throw new InvalidOperationException("Fiscal year {$period->fiscalYear->name} is closed; reopen the year first.");
        }

        if (! $period->isOpen()) {
            $period->update(['status' => PeriodStatus::Open, 'closed_at' => null]);

            // Allow month end to run again for this period.
            CloseRun::query()
                ->where('type', CloseType::Month->value)
                ->where('date', $period->end_date->toDateString())
                ->delete();

            event(new PeriodReopened($period));
        }

        return $period;
    }

    /**
     * Close every open period that ends on or before $date.
     *
     * @return Collection<int, Period>
     */
    public function closeThrough(\DateTimeInterface|string $date): Collection
    {
        $periods = Period::query()
            ->where('status', PeriodStatus::Open->value)
            ->where('end_date', '<=', Carbon::parse($date)->toDateString())
            ->orderBy('start_date')
            ->get();

        return $periods->each(fn (Period $p) => $this->close($p));
    }

    protected function startMonth(): int
    {
        return (int) config('bookkeeper.fiscal_year.start_month', 1);
    }

    protected function fiscalYearStartFor(Carbon $date): Carbon
    {
        $start = Carbon::create($date->year, $this->startMonth(), 1)->startOfDay();

        return $start->gt($date) ? $start->subYear() : $start;
    }
}
