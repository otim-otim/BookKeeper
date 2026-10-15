<?php

namespace OtimOtim\BookKeeper\Data;

/**
 * Base for BookKeeper's data transfer objects.
 *
 * Every service method that writes data accepts either a DTO or the
 * equivalent array (snake_case keys, as in the README). Arrays are
 * converted at the boundary, so the services only ever work with typed,
 * validated values.
 */
abstract readonly class Data
{
    /**
     * Build from an array of snake_case options.
     */
    abstract public static function fromArray(array $data): static;

    public static function from(self|array $data): static
    {
        return $data instanceof static ? $data : static::fromArray($data);
    }

    /**
     * @param  iterable<self|array>  $items
     * @return list<static>
     */
    public static function collect(iterable $items): array
    {
        $collected = [];
        foreach ($items as $item) {
            $collected[] = static::from($item);
        }

        return $collected;
    }

    /**
     * A copy with some properties replaced: $data->with(memo: 'New memo').
     */
    public function with(mixed ...$values): static
    {
        return new static(...array_merge(get_object_vars($this), $values));
    }
}
