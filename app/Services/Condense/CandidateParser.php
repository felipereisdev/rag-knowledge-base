<?php

namespace App\Services\Condense;

final class CandidateParser
{
    /**
     * Allowed categories — must match the `chk_category` DB constraint on
     * `knowledge_entries`. Anything else the LLM emits falls back to 'insight'.
     */
    private const CATEGORIES = [
        'business-rule', 'design-decision', 'architecture',
        'documentation', 'insight', 'convention', 'constraint',
    ];

    /**
     * @return list<array{title:string, content:string, category:string,
     *   entities:list<array{name:string,type:string}>,
     *   relations:list<array{subject:string,predicate:string,object:string}>}>
     */
    public function parse(string $raw): array
    {
        $json = $this->extractJsonArray($raw);
        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $content = trim((string) ($item['content'] ?? ''));
            if ($title === '' || $content === '') {
                continue;
            }
            $category = trim((string) ($item['category'] ?? ''));
            if (! in_array($category, self::CATEGORIES, true)) {
                $category = 'insight';
            }

            $out[] = [
                'title' => $title,
                'content' => $content,
                'category' => $category,
                'entities' => $this->cleanEntities($item['entities'] ?? []),
                'relations' => $this->cleanRelations($item['relations'] ?? []),
            ];
        }

        return $out;
    }

    private function extractJsonArray(string $raw): ?string
    {
        $start = strpos($raw, '[');
        $end = strrpos($raw, ']');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return substr($raw, $start, $end - $start + 1);
    }

    /** @return list<array{name:string,type:string}> */
    private function cleanEntities(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $e) {
            $name = is_array($e) ? trim((string) ($e['name'] ?? '')) : '';
            if ($name === '') {
                continue;
            }
            $out[] = ['name' => $name, 'type' => trim((string) ($e['type'] ?? ''))];
        }

        return $out;
    }

    /** @return list<array{subject:string,predicate:string,object:string}> */
    private function cleanRelations(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $r) {
            if (! is_array($r)) {
                continue;
            }
            $s = trim((string) ($r['subject'] ?? ''));
            $p = trim((string) ($r['predicate'] ?? ''));
            $o = trim((string) ($r['object'] ?? ''));
            if ($s === '' || $p === '' || $o === '') {
                continue;
            }
            $out[] = ['subject' => $s, 'predicate' => $p, 'object' => $o];
        }

        return $out;
    }
}
