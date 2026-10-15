<?php

namespace OtimOtim\BookKeeper\Services;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Data\FixedAssetData;
use OtimOtim\BookKeeper\Data\LineData;
use OtimOtim\BookKeeper\Enums\AssetStatus;
use OtimOtim\BookKeeper\Enums\DepreciationMethod;
use OtimOtim\BookKeeper\Enums\EntryType;
use OtimOtim\BookKeeper\Events\AssetsDepreciated;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\AssetDepreciation;
use OtimOtim\BookKeeper\Models\FixedAsset;
use OtimOtim\BookKeeper\Models\JournalEntry;
use OtimOtim\BookKeeper\Support\Currency;
use OtimOtim\BookKeeper\Support\Database;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Fixed asset register with monthly depreciation and disposal.
 *
 * Depreciation is charged per calendar month, starting with the month of
 * the depreciation start date, and posted once per month for all assets.
 */
class FixedAssetService
{
    public function __construct(
        protected AccountService $accounts,
        protected LedgerService $ledger,
    ) {}

    /**
     * Register an asset (see FixedAssetData for the fields; arrays use
     * snake_case keys). With paid_from, the purchase is posted too:
     * Dr asset account, Cr paid_from.
     */
    public function register(FixedAssetData|array $data): FixedAsset
    {
        $data = FixedAssetData::from($data);
        $acquiredOn = ($data->acquiredOn ?? now())->copy()->startOfDay();
        $cost = Money::of($data->cost);

        return Database::transaction(function () use ($data, $acquiredOn, $cost) {
            $assetAccount = $this->accounts->resolve($data->assetAccount);

            $asset = FixedAsset::create([
                'code' => $data->code,
                'name' => $data->name,
                'description' => $data->description,
                'asset_account_id' => $assetAccount->id,
                'accumulated_depreciation_account_id' => $this->accounts->resolve($data->accumulatedDepreciationAccount)->id,
                'depreciation_expense_account_id' => $this->accounts->resolve($data->depreciationExpenseAccount)->id,
                'cost' => $cost,
                'salvage_value' => Money::of($data->salvageValue),
                'method' => $data->method,
                'useful_life_months' => $data->usefulLifeMonths,
                'declining_rate' => $data->decliningRate,
                'acquired_on' => $acquiredOn,
                'depreciation_start_date' => ($data->depreciationStartDate ?? $acquiredOn)->copy()->startOfMonth(),
                'status' => AssetStatus::Active,
                'branch_id' => $data->branchId,
                'metadata' => $data->metadata,
            ]);

            if ($data->paidFrom !== null) {
                $entry = $this->ledger->transfer($assetAccount, $data->paidFrom, $cost, [
                    'date' => $acquiredOn,
                    'memo' => "Acquisition of {$asset->code} {$asset->name}",
                    'branch_id' => $asset->branch_id,
                    'source' => $asset,
                    'credit_subledger' => $data->paidFromSubledger,
                ]);
                $asset->update(['acquisition_entry_id' => $entry->id]);
            }

            return $asset;
        });
    }

    /**
     * Depreciation charge for one asset for the month ending $monthEnd,
     * given what has been charged before it.
     */
    public function chargeFor(FixedAsset $asset, \DateTimeInterface|string $monthEnd, ?Money $accumulated = null): Money
    {
        $monthEnd = Carbon::parse($monthEnd)->endOfMonth()->startOfDay();
        $start = $asset->depreciation_start_date->copy()->startOfMonth();

        if ($monthEnd->lt($start) || $asset->method === DepreciationMethod::None) {
            return Money::zero();
        }

        $accumulated ??= Money::ofMinor((int) $asset->depreciations()->where('period_end', '<', $monthEnd->toDateString())->sum('amount'));
        $remaining = $asset->depreciableAmount()->minus($accumulated);

        if (! $remaining->isPositive()) {
            return Money::zero();
        }

        $monthIndex = (int) $start->diffInMonths($monthEnd->copy()->startOfMonth()) + 1;

        $charge = match ($asset->method) {
            DepreciationMethod::StraightLine => $monthIndex >= $asset->useful_life_months
                ? $remaining
                : $asset->depreciableAmount()->dividedBy($asset->useful_life_months),
            DepreciationMethod::DecliningBalance => $asset->cost->minus($accumulated)
                ->multipliedBy(BigDecimal::of($asset->declining_rate)->dividedBy(1200, 12, Currency::rounding())),
            DepreciationMethod::None => Money::zero(),
        };

        return $charge->min($remaining);
    }

    /**
     * Charge depreciation for every month that ended on or before $asOf and
     * has not been charged yet. One journal entry per month.
     *
     * @return Collection<int, JournalEntry>
     */
    public function depreciate(\DateTimeInterface|string|null $asOf = null): Collection
    {
        $asOf = Carbon::parse($asOf ?? now())->startOfDay();
        $lastMonthEnd = $asOf->copy()->endOfMonth()->isSameDay($asOf) ? $asOf->copy() : $asOf->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay();

        $assets = FixedAsset::query()->where('status', AssetStatus::Active->value)->get();
        if ($assets->isEmpty()) {
            return new Collection;
        }

        $first = $assets->map(function (FixedAsset $asset) {
            $last = $asset->depreciations()->max('period_end');

            return $last ? Carbon::parse($last)->addDay()->startOfMonth() : $asset->depreciation_start_date->copy();
        })->min();

        $entries = new Collection;

        for ($month = $first->copy()->endOfMonth()->startOfDay(); $month->lte($lastMonthEnd); $month = $month->copy()->addDay()->endOfMonth()->startOfDay()) {
            $entry = $this->depreciateMonth($assets, $month);
            if ($entry) {
                $entries->push($entry);
            }
        }

        return $entries;
    }

    /**
     * Projected depreciation schedule for an asset.
     *
     * @return list<array{period_end: string, charge: Money, accumulated: Money, net_book_value: Money}>
     */
    public function schedule(FixedAsset $asset): array
    {
        $rows = [];
        $accumulated = Money::zero();
        $month = $asset->depreciation_start_date->copy()->endOfMonth()->startOfDay();
        $guard = 0;

        while ($guard++ < 1200) {
            $charge = $this->chargeFor($asset, $month, $accumulated);
            if ($charge->isZero()) {
                break;
            }
            $accumulated = $accumulated->plus($charge);
            $rows[] = [
                'period_end' => $month->toDateString(),
                'charge' => $charge,
                'accumulated' => $accumulated,
                'net_book_value' => $asset->cost->minus($accumulated),
            ];
            $month = $month->copy()->addDay()->endOfMonth()->startOfDay();
        }

        return $rows;
    }

    /**
     * Dispose of (sell or scrap) an asset. Removes its cost and accumulated
     * depreciation and books the gain or loss to the "asset_disposal" role.
     *
     * Options: proceeds (amount), proceeds_account (where the proceeds went).
     */
    public function dispose(FixedAsset $asset, \DateTimeInterface|string $date, array $options = []): FixedAsset
    {
        if ($asset->status === AssetStatus::Disposed) {
            throw new InvalidOperationException("Asset [{$asset->code}] is already disposed.");
        }

        $proceeds = Money::of($options['proceeds'] ?? 0);
        if ($proceeds->isPositive() && ! isset($options['proceeds_account'])) {
            throw new InvalidOperationException('Disposal proceeds need a proceeds_account.');
        }

        return Database::transaction(function () use ($asset, $date, $proceeds, $options) {
            $accumulated = $asset->accumulatedDepreciation();
            $netBookValue = $asset->cost->minus($accumulated);
            $gain = $proceeds->minus($netBookValue);

            // Zero lines (no proceeds, no gain) are dropped by the ledger.
            $lines = [
                LineData::debit($asset->accumulated_depreciation_account_id, $accumulated),
                LineData::credit($asset->asset_account_id, $asset->cost),
                LineData::debit($options['proceeds_account'] ?? $asset->asset_account_id, $proceeds),
                // A gain is income (credit); a loss is a debit.
                LineData::signed('@asset_disposal', $gain->negated()),
            ];

            $entry = $this->ledger->record($lines, [
                'date' => $date,
                'memo' => "Disposal of {$asset->code} {$asset->name}",
                'branch_id' => $asset->branch_id,
                'source' => $asset,
            ]);

            $asset->update([
                'status' => AssetStatus::Disposed,
                'disposed_on' => Carbon::parse($date),
                'disposal_proceeds' => $proceeds,
                'disposal_entry_id' => $entry->id,
            ]);

            return $asset;
        });
    }

    protected function depreciateMonth(Collection $assets, Carbon $monthEnd): ?JournalEntry
    {
        return Database::transaction(function () use ($assets, $monthEnd) {
            $lines = [];
            $charges = [];

            foreach ($assets as $asset) {
                if ($asset->depreciations()->where('period_end', $monthEnd->toDateString())->exists()) {
                    continue;
                }

                $charge = $this->chargeFor($asset, $monthEnd);
                if ($charge->isZero()) {
                    continue;
                }

                $charges[] = [$asset, $charge];
                $lines[] = LineData::debit($asset->depreciation_expense_account_id, $charge, memo: $asset->code, branchId: $asset->branch_id);
                $lines[] = LineData::credit($asset->accumulated_depreciation_account_id, $charge, memo: $asset->code, branchId: $asset->branch_id);
            }

            if ($lines === []) {
                return null;
            }

            $entry = $this->ledger->record($lines, [
                'date' => $monthEnd,
                'type' => EntryType::System,
                'memo' => 'Depreciation for '.$monthEnd->format('F Y'),
                'idempotency_key' => 'depreciation:'.$monthEnd->toDateString().':'.md5(implode(',', array_map(fn ($c) => $c[0]->id, $charges))),
            ]);

            foreach ($charges as [$asset, $charge]) {
                AssetDepreciation::create([
                    'fixed_asset_id' => $asset->id,
                    'period_end' => $monthEnd,
                    'amount' => $charge,
                    'journal_entry_id' => $entry->id,
                ]);

                if ($asset->netBookValue()->compareTo($asset->salvage_value) <= 0) {
                    $asset->update(['status' => AssetStatus::FullyDepreciated]);
                }
            }

            event(new AssetsDepreciated($monthEnd, $entry));

            return $entry;
        });
    }
}
