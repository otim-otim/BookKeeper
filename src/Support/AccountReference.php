<?php

namespace OtimOtim\BookKeeper\Support;

use OtimOtim\BookKeeper\Models\Account;

/**
 * Account references in configuration (posting templates, appropriation
 * rules) are written as a role ("cash"), an account code ("#1121") or a
 * context key (":till"). This turns them into what AccountService::resolve()
 * accepts.
 */
final class AccountReference
{
    /**
     * @param  array<string, mixed>  $context  values for ":key" references
     */
    public static function fromConfig(Account|int|string $reference, array $context = []): Account|int|string|null
    {
        return match (true) {
            $reference instanceof Account, is_int($reference) => $reference,
            str_starts_with($reference, ':') => $context[substr($reference, 1)] ?? null,
            str_starts_with($reference, '#') => substr($reference, 1),
            str_starts_with($reference, '@') => $reference,
            default => '@'.$reference,
        };
    }
}
