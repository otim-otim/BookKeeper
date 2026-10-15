<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OtimOtim\BookKeeper\Enums\AccountSubtype;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Enums\CashFlowCategory;
use OtimOtim\BookKeeper\Enums\Side;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Services\BalanceService;
use OtimOtim\BookKeeper\Support\Money;

/**
 * @property int $id
 * @property ?int $parent_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property ?AccountSubtype $subtype
 * @property ?string $role
 * @property ?string $currency
 * @property bool $is_group
 * @property bool $is_control
 * @property bool $is_system
 * @property bool $is_active
 * @property ?array $tags
 */
class Account extends Model
{
    protected $table = 'bookkeeper_accounts';

    protected $casts = [
        'type' => AccountType::class,
        'subtype' => AccountSubtype::class,
        'cash_flow_category' => CashFlowCategory::class,
        'is_group' => 'boolean',
        'is_control' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'tags' => 'array',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Account $account) {
            if ($account->is_system) {
                throw new InvalidOperationException("Account [{$account->code}] is a system account and cannot be deleted.");
            }
            if ($account->lines()->exists()) {
                throw new InvalidOperationException("Account [{$account->code}] has journal lines; deactivate it instead.");
            }
            if ($account->children()->exists()) {
                throw new InvalidOperationException("Account [{$account->code}] has child accounts.");
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id')->orderBy('code');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * @deprecated v1 name for lines().
     */
    public function transactions(): HasMany
    {
        return $this->lines();
    }

    public function normalBalance(): Side
    {
        return $this->type->normalBalance();
    }

    public function isContra(): bool
    {
        return (bool) $this->subtype?->isContra();
    }

    public function cashFlowCategory(): CashFlowCategory
    {
        return $this->cash_flow_category
            ?? $this->subtype?->cashFlowCategory()
            ?? CashFlowCategory::Operating;
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags ?? [], true);
    }

    /**
     * Current balance in the account's normal-balance direction, including
     * all descendant accounts for group accounts.
     */
    public function balance(?int $branchId = null): Money
    {
        return app(BalanceService::class)->balance($this, null, $branchId);
    }

    public function balanceAsOf(\DateTimeInterface|string $date, ?int $branchId = null): Money
    {
        return app(BalanceService::class)->balance($this, $date, $branchId);
    }

    /**
     * IDs of this account and every account beneath it.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        while ($frontier) {
            $frontier = static::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            array_push($ids, ...$frontier);
        }

        return $ids;
    }

    /**
     * Ancestors from the root down to the direct parent.
     */
    public function ancestors(): Collection
    {
        $ancestors = new Collection;
        $current = $this->parent;

        while ($current) {
            $ancestors->prepend($current);
            $current = $current->parent;
        }

        return $ancestors;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_group', false);
    }

    public function scopeOfType(Builder $query, AccountType|string $type): Builder
    {
        return $query->where('type', $type instanceof AccountType ? $type->value : $type);
    }

    public function scopeOfSubtype(Builder $query, AccountSubtype|string ...$subtypes): Builder
    {
        return $query->whereIn('subtype', array_map(fn ($s) => $s instanceof AccountSubtype ? $s->value : $s, $subtypes));
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }
}
