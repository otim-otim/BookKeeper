<?php

namespace OtimOtim\BookKeeper\Services;

use OtimOtim\BookKeeper\Exceptions\InvalidEntryException;
use OtimOtim\BookKeeper\Exceptions\TemplateNotFoundException;
use OtimOtim\BookKeeper\Models\Account;
use OtimOtim\BookKeeper\Models\JournalEntry;
use OtimOtim\BookKeeper\Support\Money;

/**
 * Posting templates: map a business event to journal lines once, in config
 * or at runtime, then post it by name.
 *
 *   'loan.disbursed' => [
 *       'memo' => 'Loan disbursement',
 *       'lines' => [
 *           ['debit' => 'loan_portfolio', 'amount' => 'principal', 'subledger' => 'loan'],
 *           ['credit' => ':disbursed_from', 'amount' => 'principal - fees'],
 *           ['credit' => 'fee_income', 'amount' => 'fees'],
 *       ],
 *   ]
 *
 * Accounts: a role name, "#code" for an account code, or ":key" to read the
 * account from the context. Amounts: keys into $amounts combined with + and -.
 * Lines whose amount works out to zero are skipped.
 */
class TemplateService
{
    /** @var array<string, array> */
    protected array $runtime = [];

    public function __construct(protected LedgerService $ledger) {}

    public function define(string $name, array $template): void
    {
        $this->runtime[$name] = $template;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): array
    {
        // Template names contain dots, so read the array rather than config("...{$name}").
        return $this->all()[$name] ?? throw new TemplateNotFoundException($name);
    }

    /**
     * @return array<string, array>
     */
    public function all(): array
    {
        return $this->runtime + (array) config('bookkeeper.postings', []);
    }

    /**
     * Build the journal lines a template produces, without posting them.
     */
    public function lines(string $name, array $amounts, array $context = []): array
    {
        $template = $this->get($name);
        $lines = [];

        foreach ($template['lines'] ?? [] as $i => $definition) {
            $side = isset($definition['debit']) ? 'debit' : (isset($definition['credit']) ? 'credit' : null);

            if ($side === null) {
                throw new InvalidEntryException("Template [{$name}] line {$i} names neither a debit nor a credit account.");
            }

            $amount = $this->evaluate($definition['amount'] ?? 'amount', $amounts, $name);

            if ($amount->isZero()) {
                continue;
            }

            $account = $this->account($definition[$side], $context, $name);

            if ($amount->isNegative()) {
                $side = $side === 'debit' ? 'credit' : 'debit';
                $amount = $amount->abs();
            }

            $line = ['account' => $account, $side => $amount];

            if (isset($definition['subledger'])) {
                $line['subledger'] = $context[$definition['subledger']]
                    ?? throw new InvalidEntryException("Template [{$name}] needs '{$definition['subledger']}' in the context.");
            }

            foreach (['memo', 'tax_rate', 'branch_id'] as $key) {
                if (isset($definition[$key])) {
                    $line[$key] = $definition[$key];
                }
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Post a template.
     *
     * @param  array<string, Money|int|float|string>  $amounts  named amounts (major units or Money)
     * @param  array<string, mixed>  $context  models and accounts referenced by the template
     * @param  array<string, mixed>  $options  journal entry options (date, reference, idempotency_key, ...)
     */
    public function post(string $name, array $amounts, array $context = [], array $options = []): JournalEntry
    {
        $template = $this->get($name);

        return $this->ledger->record($this->lines($name, $amounts, $context), $options + array_filter([
            'memo' => $template['memo'] ?? null,
            'type' => $template['type'] ?? null,
            'source' => $context['source'] ?? null,
            'metadata' => ['template' => $name],
        ]));
    }

    protected function evaluate(string $expression, array $amounts, string $name): Money
    {
        preg_match_all('/([+-]?)\s*([A-Za-z_][\w.]*)/', $expression, $terms, PREG_SET_ORDER);

        if (! $terms) {
            throw new InvalidEntryException("Template [{$name}] has an invalid amount expression [{$expression}].");
        }

        $total = Money::zero();

        foreach ($terms as [, $sign, $key]) {
            if (! array_key_exists($key, $amounts)) {
                throw new InvalidEntryException("Template [{$name}] needs the amount '{$key}'.");
            }

            $value = Money::of($amounts[$key]);
            $total = $sign === '-' ? $total->minus($value) : $total->plus($value);
        }

        return $total;
    }

    protected function account(mixed $reference, array $context, string $name): Account|int|string
    {
        if ($reference instanceof Account || is_int($reference)) {
            return $reference;
        }

        if (str_starts_with($reference, ':')) {
            return $context[substr($reference, 1)]
                ?? throw new InvalidEntryException("Template [{$name}] needs the account '".substr($reference, 1)."' in the context.");
        }

        if (str_starts_with($reference, '#')) {
            return substr($reference, 1);
        }

        return '@'.$reference;
    }
}
