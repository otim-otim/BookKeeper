<?php

namespace OtimOtim\BookKeeper\Data;

use OtimOtim\BookKeeper\Enums\EntryType;

/**
 * A posting template: a named business event and the journal lines it
 * produces (see config('bookkeeper.postings')).
 */
final readonly class PostingTemplateData extends Data
{
    /**
     * @param  list<TemplateLineData>  $lines
     */
    public function __construct(
        public string $name,
        public array $lines,
        public ?string $memo = null,
        public ?EntryType $type = null,
    ) {}

    public static function fromArray(array $data): static
    {
        $type = $data['type'] ?? null;

        return new self(
            name: $data['name'] ?? '',
            lines: TemplateLineData::collect($data['lines'] ?? []),
            memo: $data['memo'] ?? null,
            type: is_string($type) ? EntryType::from($type) : $type,
        );
    }
}
