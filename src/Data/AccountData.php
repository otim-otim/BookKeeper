<?php

namespace OtimOtim\BookKeeper\Data;

use OtimOtim\BookKeeper\Enums\AccountSubtype;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Enums\CashFlowCategory;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;

/**
 * An account to add to the chart.
 *
 * $names holds translations (locale => name). $name is the name stored on
 * the account; translations are kept in its metadata for
 * Account::localizedName().
 */
final readonly class AccountData extends Data
{
    /**
     * @param  array<string, string>  $names
     * @param  list<string>|null  $tags
     */
    public function __construct(
        public string $code,
        public string $name,
        public AccountType $type,
        public ?AccountSubtype $subtype = null,
        public Account|int|string|null $parent = null,
        public ?string $role = null,
        public ?string $description = null,
        public ?string $currency = null,
        public bool $isGroup = false,
        public bool $isControl = false,
        public bool $isSystem = false,
        public bool $isActive = true,
        public ?CashFlowCategory $cashFlowCategory = null,
        public ?array $tags = null,
        public ?array $metadata = null,
        public array $names = [],
    ) {
        if ($subtype && $subtype->type() !== $type) {
            throw new InvalidOperationException("Subtype [{$subtype->value}] belongs to {$subtype->type()->value} accounts, not {$type->value}.");
        }
    }

    /**
     * Build from a code, name and type (an AccountType, an AccountSubtype, or
     * either's string value) plus the options documented in the README.
     */
    public static function make(string $code, string|array $name, AccountType|AccountSubtype|string $type, array $options = []): self
    {
        return self::fromArray(['code' => $code, 'name' => $name, 'type' => $type] + $options);
    }

    public static function fromArray(array $data): static
    {
        $subtype = $data['subtype'] ?? null;
        $subtype = is_string($subtype) ? AccountSubtype::from($subtype) : $subtype;
        $type = $data['type'] ?? $subtype?->type() ?? throw new InvalidOperationException("Account [{$data['code']}] needs a type or subtype.");

        if ($type instanceof AccountSubtype) {
            [$subtype, $type] = [$type, $type->type()];
        } elseif (is_string($type)) {
            $asSubtype = AccountType::tryFrom($type) ? null : AccountSubtype::from($type);
            [$subtype, $type] = $asSubtype ? [$asSubtype, $asSubtype->type()] : [$subtype, AccountType::from($type)];
        }

        $cashFlow = $data['cash_flow_category'] ?? $data['cash_flow'] ?? null;
        [$name, $names] = self::names($data['name'], $data['locale'] ?? null);

        return new self(
            code: (string) $data['code'],
            name: $name,
            type: $type,
            subtype: $subtype,
            parent: $data['parent'] ?? null,
            role: $data['role'] ?? null,
            description: $data['description'] ?? null,
            currency: isset($data['currency']) ? strtoupper($data['currency']) : null,
            isGroup: (bool) ($data['is_group'] ?? $data['group'] ?? false),
            isControl: (bool) ($data['is_control'] ?? $data['control'] ?? false),
            isSystem: (bool) ($data['is_system'] ?? false),
            isActive: (bool) ($data['is_active'] ?? true),
            cashFlowCategory: is_string($cashFlow) ? CashFlowCategory::from($cashFlow) : $cashFlow,
            tags: $data['tags'] ?? null,
            metadata: $data['metadata'] ?? null,
            names: $names,
        );
    }

    /**
     * Attributes for the bookkeeper_accounts row.
     */
    public function attributes(?Account $parent): array
    {
        $metadata = $this->names === [] ? $this->metadata : ['names' => $this->names] + ($this->metadata ?? []);

        return [
            'parent_id' => $parent?->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'subtype' => $this->subtype,
            'role' => $this->role,
            'description' => $this->description,
            'currency' => $this->currency,
            'is_group' => $this->isGroup,
            'is_control' => $this->isControl,
            'is_system' => $this->isSystem,
            'is_active' => $this->isActive,
            'cash_flow_category' => $this->cashFlowCategory,
            'tags' => $this->tags,
            'metadata' => $metadata,
        ];
    }

    /**
     * A name may be a string or translations (['en' => .., 'sw' => ..]).
     * The stored name is the one for $locale, then English, then the first.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function names(string|array $name, ?string $locale): array
    {
        if (is_string($name)) {
            return [$name, []];
        }

        $locale ??= config('bookkeeper.locale') ?? (function_exists('app') ? app()->getLocale() : 'en');
        $language = strtolower(strtok((string) $locale, '_-'));

        return [$name[$locale] ?? $name[$language] ?? $name['en'] ?? reset($name), $name];
    }
}
