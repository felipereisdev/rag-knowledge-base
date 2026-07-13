<?php

namespace App\Services\Search;

class SearchResult
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $matchedBy  vector, keyword, and/or graph
     */
    public function __construct(
        public readonly int $entryId,
        public readonly string $title,
        public readonly string $snippet,
        public readonly float $fusionScore,
        public readonly ?float $semanticSimilarity,
        public readonly ?float $keywordScore,
        public readonly ?int $matchedChunkIndex,
        public readonly string $category,
        public readonly array $tags,
        public readonly array $matchedBy,
        public readonly bool $graphExpanded,
    ) {}
}
