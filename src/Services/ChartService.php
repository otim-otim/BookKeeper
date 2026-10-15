<?php

namespace OtimOtim\BookKeeper\Services;

use OtimOtim\BookKeeper\Enums\AccountSubtype;
use OtimOtim\BookKeeper\Enums\AccountType;
use OtimOtim\BookKeeper\Exceptions\InvalidOperationException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Support\Database;

/**
 * Chart of accounts templates.
 *
 * A template is a PHP file returning ['name' => ..., 'accounts' => [...]]
 * where each account is ['code', 'name', 'type'?, 'subtype'?, 'role'?,
 * 'control'?, 'tags'?, 'description'?, 'children'? => [...]]. Types are
 * inferred from the subtype or the parent. Bundled templates live in
 * resources/charts; published or custom ones in resources/bookkeeper/charts.
 */
class ChartService
{
    public function __construct(protected AccountService $accounts) {}

    /**
     * @return array<string, string> template key => file path
     */
    public function templates(): array
    {
        $templates = [];

        foreach ([__DIR__.'/../../resources/charts', function_exists('resource_path') ? resource_path('bookkeeper/charts') : null] as $dir) {
            if ($dir && is_dir($dir)) {
                foreach (glob($dir.'/*.php') as $file) {
                    $templates[basename($file, '.php')] = $file;
                }
            }
        }

        ksort($templates);

        return $templates;
    }

    public function load(string $template): array
    {
        $file = $this->templates()[$template] ?? (is_file($template) ? $template : null);

        return $file ? require $file : throw new InvalidOperationException("Chart template [{$template}] not found. Available: ".implode(', ', array_keys($this->templates())).'.');
    }

    /**
     * Create the accounts in a template. Existing codes are left alone, but
     * pick up roles they do not have yet, so importing is safe to repeat.
     *
     * @return int accounts created
     */
    public function import(string|array $template): int
    {
        $definition = is_array($template) ? $template : $this->load($template);

        return Database::transaction(function () use ($definition) {
            $created = 0;
            foreach ($definition['accounts'] ?? [] as $node) {
                $created += $this->importNode($node, null);
            }

            return $created;
        });
    }

    protected function importNode(array $node, ?Account $parent): int
    {
        $subtype = isset($node['subtype']) ? AccountSubtype::from($node['subtype']) : null;
        $type = isset($node['type']) ? AccountType::from($node['type']) : ($subtype?->type() ?? $parent?->type);

        if ($type === null) {
            throw new InvalidOperationException("Chart account [{$node['code']}] needs a type or subtype.");
        }

        $created = 0;
        $account = Account::query()->where('code', $node['code'])->first();

        if ($account === null) {
            $account = $this->accounts->create($node['code'], $node['name'], $type, [
                'parent' => $parent,
                'subtype' => $subtype,
                'role' => $node['role'] ?? null,
                'description' => $node['description'] ?? null,
                'is_group' => ! empty($node['children']) || ($node['group'] ?? false),
                'is_control' => $node['control'] ?? false,
                'is_system' => true,
                'tags' => $node['tags'] ?? null,
                'currency' => $node['currency'] ?? null,
                'cash_flow_category' => $node['cash_flow'] ?? null,
            ]);
            $created++;
        } elseif (isset($node['role']) && $account->role === null && ! $this->accounts->hasRole($node['role'])) {
            $this->accounts->assignRole($account, $node['role']);
        }

        foreach ($node['children'] ?? [] as $child) {
            $created += $this->importNode($child, $account);
        }

        return $created;
    }
}
