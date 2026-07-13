<?php

namespace App\Services\Search;

use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Support\Rag\PostgresTextSearch;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

class HybridSearcher
{
    private readonly int $limit;

    private readonly float $minScore;

    private readonly bool $expandGraph;

    private readonly float $graphWeight;

    private readonly int $vectorTopK;

    private readonly int $ftsTopK;

    private readonly int $rrfK;

    public function __construct(
        ?int $limit = null,
        ?float $minScore = null,
        ?bool $expandGraph = null,
        ?float $graphWeight = null,
        ?int $vectorTopK = null,
        ?int $ftsTopK = null,
        ?int $rrfK = null,
    ) {
        $this->limit = $limit ?? (int) config('rag.search.limit', 10);
        $this->minScore = $minScore ?? (float) config('rag.search.min_score', 0.30);
        $this->expandGraph = $expandGraph ?? (bool) config('rag.search.graph_expand', true);
        $this->graphWeight = $graphWeight ?? (float) config('rag.search.graph_weight', 0.3);
        $this->vectorTopK = $vectorTopK ?? (int) config('rag.search.vector_top_k', 20);
        $this->ftsTopK = $ftsTopK ?? (int) config('rag.search.fts_top_k', 20);
        $this->rrfK = $rrfK ?? (int) config('rag.search.rrf_k', 60);
    }

    /**
     * @return array<SearchResult>
     */
    public function search(
        string $query,
        ?string $projectId = null,
        ?string $category = null,
    ): array {
        $textSearchConfig = $this->textSearchConfig($projectId);

        // 1. Generate query embedding
        $queryVector = $this->embedQuery($query);

        // 2. Vector search
        $vectorResults = $this->vectorSearch($queryVector, $projectId, $category);

        // 3. Keyword (FTS) search
        $ftsResults = $this->ftsSearch($query, $projectId, $category, $textSearchConfig);

        // 4. Reciprocal Rank Fusion
        $fused = $this->reciprocalRankFusion($vectorResults, $ftsResults);

        // 5. Graph expansion (KAG)
        if ($this->expandGraph && ! empty($fused)) {
            $fused = $this->expandGraph($fused, $projectId);
        }

        // 6. Sort, limit, hydrate, highlight
        $fused = $this->sortAndLimit($fused);

        return $this->hydrate($fused, $query, $textSearchConfig);
    }

    /**
     * @return array<float>
     */
    private function embedQuery(string $query): array
    {
        $response = Embeddings::for([$query])->generate((string) config('rag.embeddings.provider', 'local-embedder'));

        return $response->embeddings[0];
    }

    /**
     * @param  list<float>  $queryVector
     * @return array<int, array{
     *     score: float,
     *     rank: int,
     *     chunkIndex: int,
     *     chunkContent: string
     * }>
     */
    private function vectorSearch(array $queryVector, ?string $projectId, ?string $category): array
    {
        if ($this->vectorTopK <= 0) {
            return [];
        }

        $vector = '['.implode(',', $queryVector).']';

        // OFFSET 0 keeps the approval lookup correlated so PostgreSQL drives
        // the bounded CTE from the HNSW index instead of flattening it into a
        // join that requires sorting every eligible chunk.
        $sql = 'WITH ann_candidates AS MATERIALIZED (
                SELECT
                    ce.entry_id,
                    ce.chunk_index,
                    ce.content,
                    ce.embedding <=> ?::vector AS distance
                FROM chunk_embeddings ce
                WHERE EXISTS (
                    SELECT 1
                    FROM knowledge_entries ke
                    WHERE ke.id = ce.entry_id
                      AND ke.status = \'approved\'';

        $bindings = [$vector];

        if ($projectId !== null) {
            $sql .= ' AND ke.project_id = ?';
            $bindings[] = $projectId;
        }

        if ($category !== null) {
            $sql .= ' AND ke.category = ?';
            $bindings[] = $category;
        }

        $sql .= " OFFSET 0
                )
                  AND (ce.embedding <=> ?::vector) <= ?
                  AND (ce.embedding <=> ?::vector) > '-Infinity'::float8
                  AND (ce.embedding <=> ?::vector) < 'Infinity'::float8
                ORDER BY ce.embedding <=> ?::vector ASC
                LIMIT ?
            )
            SELECT
                entry_id,
                chunk_index,
                content,
                1 - distance AS semantic_similarity,
                distance
            FROM ann_candidates
            ORDER BY distance ASC, entry_id ASC, chunk_index ASC";
        array_push(
            $bindings,
            $vector,
            1.0 - $this->minScore,
            $vector,
            $vector,
            $vector,
        );

        return DB::transaction(function () use ($sql, $bindings): array {
            DB::statement('SET LOCAL hnsw.iterative_scan = strict_order');

            $results = [];
            $acceptedRank = 0;
            $candidateLimit = max(1, $this->vectorTopK * 4);

            while (true) {
                // The lookahead row proves whether the boundary-distance tie
                // group is complete before deterministic PHP deduplication.
                $fetchLimit = $candidateLimit + 1;
                $rows = DB::select($sql, [...$bindings, $fetchLimit]);
                $fetchedCount = count($rows);

                if ($fetchedCount > $candidateLimit) {
                    $boundaryDistance = (string) $rows[$candidateLimit - 1]->distance;
                    $extraDistance = (string) $rows[$candidateLimit]->distance;

                    if ($boundaryDistance === $extraDistance) {
                        $candidateLimit *= 2;

                        continue;
                    }
                }

                foreach (array_slice($rows, 0, $candidateLimit) as $row) {
                    $rawScore = is_string($row->semantic_similarity)
                        ? strtoupper($row->semantic_similarity)
                        : (string) $row->semantic_similarity;

                    if (in_array($rawScore, ['NAN', 'INF', '-INF'], true)) {
                        continue;
                    }

                    $score = (float) $row->semantic_similarity;
                    if (! is_finite($score)) {
                        continue;
                    }

                    $entryId = (int) $row->entry_id;
                    if (isset($results[$entryId])) {
                        continue;
                    }

                    $results[$entryId] = [
                        'score' => $score,
                        'rank' => $acceptedRank++,
                        'chunkIndex' => (int) $row->chunk_index,
                        'chunkContent' => (string) $row->content,
                    ];

                    if (count($results) >= $this->vectorTopK) {
                        return $results;
                    }
                }

                if ($fetchedCount < $fetchLimit) {
                    return $results;
                }

                $candidateLimit *= 2;
            }
        });
    }

    /**
     * @return array<string, array{score: float, rank: int}>
     */
    private function ftsSearch(
        string $query,
        ?string $projectId,
        ?string $category,
        string $textSearchConfig,
    ): array {
        $sql = "SELECT id as entry_id, ts_rank(search_vector, plainto_tsquery(?::regconfig, ?)) as score
                FROM knowledge_entries
                WHERE status = 'approved'
                AND search_vector @@ plainto_tsquery(?::regconfig, ?)";

        $bindings = [$textSearchConfig, $query, $textSearchConfig, $query];

        if ($projectId) {
            $sql .= ' AND project_id = ?';
            $bindings[] = $projectId;
        }

        if ($category) {
            $sql .= ' AND category = ?';
            $bindings[] = $category;
        }

        $sql .= ' ORDER BY score DESC LIMIT ?';
        $bindings[] = $this->ftsTopK;

        $results = DB::select($sql, $bindings);

        $aggregated = [];
        $rank = 0;
        foreach ($results as $row) {
            $aggregated[$row->entry_id] = ['score' => (float) $row->score, 'rank' => $rank++];
        }

        return $aggregated;
    }

    /**
     * @param  array<int, array{
     *     score: float,
     *     rank: int,
     *     chunkIndex: int,
     *     chunkContent: string
     * }>  $vector
     * @param  array<string, array{score: float, rank: int}>  $fts
     * @return array<string, array{score: float, matchedBy: array<string>, graphExpanded: bool}>
     */
    private function reciprocalRankFusion(array $vector, array $fts): array
    {
        $fused = [];
        $allEntryIds = array_unique(array_merge(array_keys($vector), array_keys($fts)));

        // Raw RRF scores are bounded by numSources * (1 / rrfK): each list
        // contributes at most 1/(rrfK+0) to a single item, so with two lists at
        // k=60 the ceiling is ~0.033. That is far below the cosine-similarity
        // scale that the rest of the system, the min_score threshold, and the
        // documented relevance calibration (~0.65+) all assume. Normalising by
        // that theoretical maximum maps scores back to [0, 1] while preserving
        // the RRF ordering exactly (it is a monotonic rescale), so a hit ranked
        // first by every active retriever scores 1.0 instead of 0.033.
        $activeSources = ($vector !== [] ? 1 : 0) + ($fts !== [] ? 1 : 0);
        $maxScore = $activeSources > 0 ? $activeSources / $this->rrfK : 1.0;

        foreach ($allEntryIds as $entryId) {
            $score = 0.0;
            $matchedBy = [];

            if (isset($vector[$entryId])) {
                $score += 1 / ($this->rrfK + $vector[$entryId]['rank']);
                $matchedBy[] = 'vector';
            }

            if (isset($fts[$entryId])) {
                $score += 1 / ($this->rrfK + $fts[$entryId]['rank']);
                $matchedBy[] = 'keyword';
            }

            // graphExpanded defaults to false here so every downstream consumer
            // (sortAndLimit, hydrate) can rely on the key existing even when
            // graph expansion is disabled; expandGraph() overrides it to true
            // only for entries it pulls in.
            $fused[$entryId] = [
                'score' => $score / $maxScore,
                'matchedBy' => $matchedBy,
                'graphExpanded' => false,
            ];
        }

        return $fused;
    }

    /**
     * @param  array<string, array{score: float, matchedBy: array<string>, graphExpanded: bool}>  $fused
     * @return array<string, array{score: float, matchedBy: array<string>, graphExpanded: bool}>
     */
    private function expandGraph(array $fused, ?string $projectId): array
    {
        $expanded = [];

        foreach ($fused as $entryId => $data) {
            $expanded[$entryId] = array_merge($data, ['graphExpanded' => false]);

            // Find entries related via entities
            $sql = 'SELECT DISTINCT e2.entry_id
                    FROM entry_entities e1
                    JOIN relations r ON r.subject_id = e1.entity_id
                    JOIN entry_entities e2 ON e2.entity_id = r.object_id
                    WHERE e1.entry_id = ?
                    AND e2.entry_id != ?';

            $bindings = [$entryId, $entryId];

            if ($projectId) {
                $sql .= ' AND r.project_id = ?';
                $bindings[] = $projectId;
            }

            $relatedIds = DB::select($sql, $bindings);

            foreach ($relatedIds as $row) {
                $relatedId = $row->entry_id;
                if (! isset($expanded[$relatedId])) {
                    $expanded[$relatedId] = [
                        'score' => $data['score'] * $this->graphWeight,
                        'matchedBy' => ['graph'],
                        'graphExpanded' => true,
                    ];
                }
            }
        }

        return $expanded;
    }

    /**
     * @param  array<string, array{score: float, matchedBy: array<string>, graphExpanded: bool}>  $fused
     * @return array<string, array{score: float, matchedBy: array<string>, graphExpanded: bool}>
     */
    private function sortAndLimit(array $fused): array
    {
        uasort($fused, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($fused, 0, $this->limit, true);
    }

    /**
     * @param  array<string, array{score: float, matchedBy: array<string>, graphExpanded: bool}>  $fused
     * @return array<SearchResult>
     */
    private function hydrate(array $fused, string $query, string $textSearchConfig): array
    {
        if (empty($fused)) {
            return [];
        }

        // Filter to approved entries at hydration so pending/rejected drafts
        // never surface in search results. This is the single chokepoint
        // that covers all match paths (vector, FTS, and graph expansion);
        // vectorSearch doesn't apply a status filter and graph expansion
        // can pull in related entries of any status.
        $entries = KnowledgeEntry::with(['tags', 'entities'])
            ->whereIn('id', array_keys($fused))
            ->where('status', 'approved')
            ->get()
            ->keyBy('id');

        $results = [];
        foreach ($fused as $entryId => $data) {
            $entry = $entries->get($entryId);
            if (! $entry) {
                continue;
            }

            $snippet = $this->highlight($entry->content, $query, $textSearchConfig);

            $results[] = new SearchResult(
                entryId: (int) $entryId,
                title: $entry->title,
                snippet: $snippet,
                score: $data['score'],
                category: $entry->category,
                tags: $entry->tags->pluck('name')->toArray(),
                matchedBy: $data['matchedBy'],
                graphExpanded: $data['graphExpanded'],
            );
        }

        return $results;
    }

    private function highlight(string $content, string $query, string $textSearchConfig): string
    {
        $result = DB::selectOne(
            'SELECT ts_headline(?::regconfig, ?, plainto_tsquery(?::regconfig, ?)) as headline',
            [$textSearchConfig, $content, $textSearchConfig, $query]
        );

        if ($result === null) {
            return mb_substr($content, 0, 200);
        }

        return $result->headline;
    }

    private function textSearchConfig(?string $projectId): string
    {
        $language = $projectId
            ? Project::query()->whereKey($projectId)->value('language')
            : null;

        return PostgresTextSearch::configForLanguage($language);
    }
}
