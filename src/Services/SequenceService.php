<?php

namespace OtimOtim\BookKeeper\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use OtimOtim\BookKeeper\Support\Database;

/**
 * Gap-free document numbering (JE-2026-000001, INV-2026-000001, ...).
 *
 * Numbers are drawn inside the caller's transaction with a row lock, so a
 * rolled-back posting does not consume a number.
 */
class SequenceService
{
    public function next(string $name, string $format, \DateTimeInterface|string|null $date = null): string
    {
        $date = Carbon::parse($date ?? now());
        $key = $name.(str_contains($format, '{Y}') ? ':'.$date->format('Y') : '')
            .(str_contains($format, '{m}') ? ':'.$date->format('m') : '');

        $value = Database::transaction(function () use ($key) {
            $table = Database::connection()->table('bookkeeper_sequences');
            $row = $table->clone()->where('key', $key)->lockForUpdate()->first();

            if ($row === null) {
                try {
                    $table->clone()->insert(['key' => $key, 'next_value' => 2, 'created_at' => now(), 'updated_at' => now()]);

                    return 1;
                } catch (UniqueConstraintViolationException) {
                    // Another process created the sequence first; take the next value from it.
                    $row = $table->clone()->where('key', $key)->lockForUpdate()->first();
                }
            }

            $table->clone()->where('key', $key)->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return (int) $row->next_value;
        });

        $padding = (int) config('bookkeeper.journal.sequence_padding', 6);

        return strtr($format, [
            '{Y}' => $date->format('Y'),
            '{m}' => $date->format('m'),
            '{seq}' => str_pad((string) $value, $padding, '0', STR_PAD_LEFT),
        ]);
    }
}
