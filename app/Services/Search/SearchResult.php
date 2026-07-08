<?php

namespace App\Services\Search;

class SearchResult
{
    /**
     * @param  array<string>  $tags
     * @param  array<string>  $matchedBy  ['vector', 'keyword', 'graph']
     */
    public function __construct(
        public readonly string $entryId,
        public readonly string $title,
        public readonly string $snippet,
        public readonly float $score,
        public readonly string $category,
        public readonly array $tags,
        public readonly array $matchedBy,
        public readonly bool $graphExpanded,
    ) {}
}
