<?php

namespace App\Services\Condense;

interface KnowledgeExtractor
{
    /**
     * @param  string|null  $language  The project's configured content language
     *                                 (`projects.language`); null means English.
     * @return list<array{title:string, content:string, category:string,
     *   entities:list<array{name:string,type:string}>,
     *   relations:list<array{subject:string,predicate:string,object:string}>}>
     */
    public function extract(string $transcript, ?string $language): array;
}
