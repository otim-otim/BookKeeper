<?php

namespace OtimOtim\BookKeeper\Services;

use OtimOtim\BookKeeper\Data\LineData;
use OtimOtim\BookKeeper\Data\PostingTemplateData;
use OtimOtim\BookKeeper\Data\TemplateLineData;
use OtimOtim\BookKeeper\Exceptions\InvalidEntryException;
use OtimOtim\BookKeeper\Exceptions\TemplateNotFoundException;
use OtimOtim\BookKeeper\Models\JournalEntry;
use OtimOtim\BookKeeper\Support\AccountReference;
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
    /** @var array<string, PostingTemplateData> */
    protected array $runtime = [];

    public function __construct(protected LedgerService $ledger) {}

    public function define(string $name, PostingTemplateData|array $template): void
    {
        $this->runtime[$name] = PostingTemplateData::from(is_array($template) ? ['name' => $name] + $template : $template);
    }

    public function has(string $name): bool
    {
        return isset($this->runtime[$name]) || array_key_exists($name, (array) config('bookkeeper.postings', []));
    }

    public function get(string $name): PostingTemplateData
    {
        // Template names contain dots, so read the array rather than config("...{$name}").
        $configured = (array) config('bookkeeper.postings', []);

        return $this->runtime[$name]
            ?? (isset($configured[$name]) ? PostingTemplateData::fromArray(['name' => $name] + $configured[$name]) : null)
            ?? throw new TemplateNotFoundException($name);
    }

    /**
     * @return array<string, PostingTemplateData>
     */
    public function all(): array
    {
        $names = array_unique([...array_keys((array) config('bookkeeper.postings', [])), ...array_keys($this->runtime)]);

        return array_combine($names, array_map(fn (string $name) => $this->get($name), $names));
    }

    /**
     * The journal lines a template produces, without posting them.
     *
     * @return list<LineData>
     */
    public function lines(string $name, array $amounts, array $context = []): array
    {
        $template = $this->get($name);
        $lines = [];

        foreach ($template->lines as $line) {
            $amount = $this->evaluate($line->amount, $amounts, $name);

            if (! $amount->isZero()) {
                $lines[] = $this->line($line, $amount, $context, $name);
            }
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
            'memo' => $template->memo,
            'type' => $template->type,
            'source' => $context['source'] ?? null,
            'metadata' => ['template' => $name],
        ]));
    }

    protected function line(TemplateLineData $line, Money $amount, array $context, string $name): LineData
    {
        $account = AccountReference::fromConfig($line->account, $context)
            ?? throw new InvalidEntryException("Template [{$name}] needs the account '".ltrim($line->account, ':')."' in the context.");

        $subledger = $line->subledger === null ? null
            : ($context[$line->subledger] ?? throw new InvalidEntryException("Template [{$name}] needs '{$line->subledger}' in the context."));

        // A negative amount posts on the other side.
        return new LineData(
            account: $account,
            side: $amount->isNegative() ? $line->side->opposite() : $line->side,
            amount: $amount->abs(),
            memo: $line->memo,
            branchId: $line->branchId,
            subledger: $subledger,
            taxRate: $line->taxRate,
        );
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
}
