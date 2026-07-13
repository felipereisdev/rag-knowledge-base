<?php

namespace App\Services\Importance;

use App\Enums\KnowledgeSource;

final readonly class ImportanceCandidate
{
    /**
     * @param  list<string>  $tags
     * @param  list<array{name:string, type?:string}>  $entities
     * @param  list<array{subject:string, predicate:string, object:string}>  $relations
     */
    public function __construct(
        public string $title,
        public string $content,
        public string $category,
        KnowledgeSource|string $source,
        public array $tags = [],
        public array $entities = [],
        public array $relations = [],
    ) {
        $this->source = $source instanceof KnowledgeSource ? $source : KnowledgeSource::from($source);
    }

    public KnowledgeSource $source;
}
