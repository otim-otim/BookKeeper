<?php

namespace OtimOtim\BookKeeper\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Column values for nullable polymorphic relations (source, creator,
 * subledger, contactable, ...).
 */
final class Morph
{
    /**
     * @return array<string, mixed> ["{$name}_type" => ..., "{$name}_id" => ...]
     */
    public static function attributes(?Model $model, string $name): array
    {
        return [
            "{$name}_type" => $model?->getMorphClass(),
            "{$name}_id" => $model?->getKey(),
        ];
    }
}
