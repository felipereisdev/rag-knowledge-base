<?php

namespace App\Services\Importance;

use JsonException;

final class ImportanceCandidateNormalizer
{
    public function normalize(ImportanceCandidate $candidate): NormalizedImportanceCandidate
    {
        $data = [
            'title' => $this->normalizeText($candidate->title),
            'content' => $this->normalizeText($candidate->content),
            'category' => $this->normalizeText($candidate->category),
            'source' => $candidate->source->value,
            'tags' => $this->normalizeTags($candidate->tags),
            'entities' => $this->normalizeEntities($candidate->entities),
            'relations' => $this->normalizeRelations($candidate->relations),
        ];

        return new NormalizedImportanceCandidate($data);
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        $tags = array_map(fn (string $tag): string => $this->normalizeText($tag), $tags);
        sort($tags, SORT_STRING);

        return $tags;
    }

    /**
     * @param  list<array{name:string, type?:string}>  $entities
     * @return list<array{name:string, type:string}>
     */
    private function normalizeEntities(array $entities): array
    {
        $entities = array_map(fn (array $entity): array => [
            'name' => $this->normalizeText($entity['name']),
            'type' => $this->normalizeText($entity['type'] ?? ''),
        ], $entities);

        usort($entities, static fn (array $left, array $right): int => $left <=> $right);

        return $entities;
    }

    /**
     * @param  list<array{subject:string, predicate:string, object:string}>  $relations
     * @return list<array{subject:string, predicate:string, object:string}>
     */
    private function normalizeRelations(array $relations): array
    {
        $relations = array_map(fn (array $relation): array => [
            'subject' => $this->normalizeText($relation['subject']),
            'predicate' => $this->normalizeText($relation['predicate']),
            'object' => $this->normalizeText($relation['object']),
        ], $relations);

        usort($relations, static fn (array $left, array $right): int => $left <=> $right);

        return $relations;
    }
}

final readonly class NormalizedImportanceCandidate
{
    private string $json;

    /**
     * @param  array{title:string, content:string, category:string, source:string, tags:list<string>, entities:list<array{name:string, type:string}>, relations:list<array{subject:string, predicate:string, object:string}>}  $data
     */
    public function __construct(private array $data)
    {
        try {
            $this->json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new \LogicException('Importance candidate cannot be serialized.', previous: $exception);
        }
    }

    /**
     * @return array{title:string, content:string, category:string, source:string, tags:list<string>, entities:list<array{name:string, type:string}>, relations:list<array{subject:string, predicate:string, object:string}>}
     */
    public function data(): array
    {
        return $this->data;
    }

    public function json(): string
    {
        return $this->json;
    }

    public function hash(): string
    {
        return hash('sha256', $this->json);
    }
}
