<?php

namespace OtimOtim\BookKeeper\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Enums\DepreciationMethod;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Support\Money;

/**
 * A fixed asset to register.
 */
final readonly class FixedAssetData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public Money|int|float|string $cost,
        public Account|int|string $assetAccount = '@fixed_assets',
        public DepreciationMethod $method = DepreciationMethod::StraightLine,
        public ?int $usefulLifeMonths = null,
        public ?string $decliningRate = null,
        public Money|int|float|string $salvageValue = 0,
        public ?Carbon $acquiredOn = null,
        public ?Carbon $depreciationStartDate = null,
        public Account|int|string $accumulatedDepreciationAccount = '@accumulated_depreciation',
        public Account|int|string $depreciationExpenseAccount = '@depreciation_expense',
        public Account|int|string|null $paidFrom = null,
        public ?Model $paidFromSubledger = null,
        public ?string $description = null,
        public ?int $branchId = null,
        public ?array $metadata = null,
    ) {
        if ($method === DepreciationMethod::StraightLine && ! $usefulLifeMonths) {
            throw new InvalidOperationException('Straight-line depreciation needs useful_life_months.');
        }
        if ($method === DepreciationMethod::DecliningBalance && ! $decliningRate) {
            throw new InvalidOperationException('Declining-balance depreciation needs declining_rate (annual %).');
        }
        if (Money::of($salvageValue)->isGreaterThan(Money::of($cost))) {
            throw new InvalidOperationException('Salvage value cannot exceed cost.');
        }
    }

    public static function fromArray(array $data): static
    {
        $method = $data['method'] ?? DepreciationMethod::StraightLine;
        $date = fn (string $key) => isset($data[$key]) ? Carbon::parse($data[$key])->startOfDay() : null;

        return new self(
            code: $data['code'],
            name: $data['name'],
            cost: $data['cost'],
            assetAccount: $data['asset_account'] ?? '@fixed_assets',
            method: $method instanceof DepreciationMethod ? $method : DepreciationMethod::from($method),
            usefulLifeMonths: isset($data['useful_life_months']) ? (int) $data['useful_life_months'] : null,
            decliningRate: isset($data['declining_rate']) ? (string) $data['declining_rate'] : null,
            salvageValue: $data['salvage_value'] ?? 0,
            acquiredOn: $date('acquired_on'),
            depreciationStartDate: $date('depreciation_start_date'),
            accumulatedDepreciationAccount: $data['accumulated_depreciation_account'] ?? '@accumulated_depreciation',
            depreciationExpenseAccount: $data['depreciation_expense_account'] ?? '@depreciation_expense',
            paidFrom: $data['paid_from'] ?? null,
            paidFromSubledger: $data['paid_from_subledger'] ?? null,
            description: $data['description'] ?? null,
            branchId: isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            metadata: $data['metadata'] ?? null,
        );
    }
}
