<?php

namespace App\Services\Search;

class SearchResult
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $matchedBy  vector, keyword, and/or graph
     */
    public readonly float $fusionScore;

    public function __construct(
        public readonly int $entryId,
        public readonly string $title,
        public readonly string $snippet,
        public readonly float $score,
        public readonly string $category,
        public readonly array $tags,
        public readonly array $matchedBy,
        public readonly bool $graphExpanded,
        public readonly ?float $semanticSimilarity = null,
        public readonly ?float $keywordScore = null,
        public readonly ?int $matchedChunkIndex = null,
    ) {
        $this->fusionScore = $score;
    }
}
