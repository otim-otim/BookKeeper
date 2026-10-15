<?php

namespace OtimOtim\BookKeeper\Services;

use OtimOtim\BookKeeper\Data\AccountData;
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
     * Account names in a template may be translations; $locale picks the
     * one stored (default: bookkeeper.locale, then the app locale).
     *
     * @return int accounts created
     */
    public function import(string|array $template, ?string $locale = null): int
    {
        $definition = is_array($template) ? $template : $this->load($template);

        return Database::transaction(function () use ($definition, $locale) {
            $created = 0;
            foreach ($definition['accounts'] ?? [] as $node) {
                $created += $this->importNode($node, null, $locale);
            }

            return $created;
        });
    }

    protected function importNode(array $node, ?Account $parent, ?string $locale): int
    {
        $created = 0;
        $account = Account::query()->where('code', $node['code'])->first();

        if ($account === null) {
            $account = $this->accounts->createFrom(AccountData::fromArray([
                'type' => $node['type'] ?? $parent?->type,
                'parent' => $parent,
                'is_group' => ! empty($node['children']) || ($node['group'] ?? false),
                'is_system' => true,
                'locale' => $locale,
            ] + $node));
            $created++;
        } elseif (isset($node['role']) && $account->role === null && ! $this->accounts->hasRole($node['role'])) {
            $this->accounts->assignRole($account, $node['role']);
        }

        foreach ($node['children'] ?? [] as $child) {
            $created += $this->importNode($child, $account, $locale);
        }

        return $created;
    }
}
