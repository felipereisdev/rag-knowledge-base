<?php

namespace App\Services\Evaluation;

use InvalidArgumentException;

final readonly class EvaluationCase
{
    /** @param list<string> $expectedTitles */
    public function __construct(
        public string $query,
        public array $expectedTitles,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $query = $data['query'] ?? null;
        if (! is_string($query)) {
            throw new InvalidArgumentException(__('rag.evaluation.query_string'));
        }

        $query = trim($query);
        if ($query === '') {
            throw new InvalidArgumentException(__('rag.evaluation.query_required'));
        }

        $titles = $data['expected_titles'] ?? null;
        if (! is_array($titles)) {
            throw new InvalidArgumentException(__('rag.evaluation.expected_titles_array'));
        }

        if (! array_is_list($titles)) {
            throw new InvalidArgumentException(__('rag.evaluation.expected_titles_list'));
        }

        foreach ($titles as $title) {
            if (! is_string($title)) {
                throw new InvalidArgumentException(__('rag.evaluation.expected_titles_strings'));
            }
        }

        $titles = array_values(array_filter(
            array_map(fn (string $title): string => trim($title), $titles),
            fn (string $title): bool => $title !== '',
        ));

        if ($titles === []) {
            throw new InvalidArgumentException(__('rag.evaluation.expected_titles_required'));
        }

        return new self($query, $titles);
    }
}
