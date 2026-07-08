<?php

namespace App\Services\Graph;

use App\Models\Entity;
use Illuminate\Support\Facades\DB;

class GraphExplorer
{
    /**
     * Explore the knowledge graph starting from a seed entity.
     *
     * BFS up to $depth hops (clamped to [1, 2]) collecting entities and
     * deduplicated relations along the way.
     *
     * @return array{
     *     entity: array{name: string, type: string}|null,
     *     entities: array<array{id: int, name: string, type: string}>,
     *     relations: array<array{subject: string, predicate: string, object: string}>
     * }
     */
    public function explore(string $projectId, string $entityName, int $depth = 1): array
    {
        $depth = max(1, min(2, $depth));

        $seed = Entity::where('project_id', $projectId)
            ->where('name', $entityName)
            ->first();

        if (! $seed) {
            return ['entity' => null, 'entities' => [], 'relations' => []];
        }

        // BFS up to $depth hops.
        $visitedIds = [(int) $seed->id];
        $frontier = [(int) $seed->id];
        $allRelations = [];

        for ($hop = 0; $hop < $depth; $hop++) {
            if ($frontier === []) {
                break;
            }

            $relations = DB::table('relations')
                ->where('project_id', $projectId)
                ->where(function ($q) use ($frontier) {
                    $q->whereIn('subject_id', $frontier)
                        ->orWhereIn('object_id', $frontier);
                })
                ->get();

            $nextFrontier = [];
            foreach ($relations as $rel) {
                $allRelations[] = $rel;
                $neighborId = in_array((int) $rel->subject_id, $frontier, true)
                    ? (int) $rel->object_id
                    : (int) $rel->subject_id;
                if (! in_array($neighborId, $visitedIds, true)) {
                    $visitedIds[] = $neighborId;
                    $nextFrontier[] = $neighborId;
                }
            }

            $frontier = $nextFrontier;
        }

        $entities = Entity::whereIn('id', $visitedIds)
            ->get()
            ->map(fn ($e) => ['id' => (int) $e->id, 'name' => $e->name, 'type' => $e->type])
            ->all();

        $entityMap = Entity::whereIn('id', $visitedIds)->pluck('name', 'id')->all();

        $relations = array_map(fn ($r) => [
            'subject' => $entityMap[$r->subject_id] ?? '?',
            'predicate' => $r->predicate,
            'object' => $entityMap[$r->object_id] ?? '?',
        ], $allRelations);

        // Dedup relations by subject|predicate|object key.
        $seen = [];
        $uniqueRelations = [];
        foreach ($relations as $r) {
            $key = "{$r['subject']}|{$r['predicate']}|{$r['object']}";
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueRelations[] = $r;
            }
        }

        return [
            'entity' => ['name' => $seed->name, 'type' => $seed->type],
            'entities' => $entities,
            'relations' => $uniqueRelations,
        ];
    }
}
