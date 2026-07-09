<?php

namespace App\Services\Search;

use App\Models\KnowledgeEntry;
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
        $this->limit = $limit ?? (int) config('app.rag_search_limit', 10);
        $this->minScore = $minScore ?? (float) config('app.rag_search_min_score', 0.30);
        $this->expandGraph = $expandGraph ?? (bool) config('app.rag_search_graph_expand', true);
        $this->graphWeight = $graphWeight ?? (float) config('app.rag_search_graph_weight', 0.3);
        $this->vectorTopK = $vectorTopK ?? (int) config('app.rag_search_vector_top_k', 20);
        $this->ftsTopK = $ftsTopK ?? (int) config('app.rag_search_fts_top_k', 20);
        $this->rrfK = $rrfK ?? (int) config('app.rag_search_rrf_k', 60);
    }

    /**
     * @return array<SearchResult>
     */
    public function search(
        string $query,
        ?string $projectId = null,
        ?string $category = null,
    ): array {
        // 1. Generate query embedding
        $queryVector = $this->embedQuery($query);

        // 2. Vector search
        $vectorResults = $this->vectorSearch($queryVector, $projectId, $category);

        // 3. Keyword (FTS) search
        $ftsResults = $this->ftsSearch($query, $projectId, $category);

        // 4. Reciprocal Rank Fusion
        $fused = $this->reciprocalRankFusion($vectorResults, $ftsResults);

        // 5. Graph expansion (KAG)
        if ($this->expandGraph && ! empty($fused)) {
            $fused = $this->expandGraph($fused, $projectId);
        }

        // 6. Sort, limit, hydrate, highlight
        $fused = $this->sortAndLimit($fused);

        return $this->hydrate($fused, $query);
    }

    /**
     * @return array<float>
     */
    private function embedQuery(string $query): array
    {
        $response = Embeddings::for([$query])->generate('local-embedder');

        return $response->embeddings[0];
    }

    /**
     * @param  array<float>  $queryVector
     * @return array<string, array{score: float, rank: int}>
     */
    private function vectorSearch(array $queryVector, ?string $projectId, ?string $category): array
    {
        $vectorStr = '['.implode(',', $queryVector).']';

        // Use raw query for pgvector cosine distance
        $sql = 'SELECT entry_id, 1 - (embedding <=> ?::vector) as score
                FROM chunk_embeddings
                WHERE 1 - (embedding <=> ?::vector) >= ?';

        $bindings = [$vectorStr, $vectorStr, $this->minScore];

        if ($projectId) {
            $sql .= ' AND project_id = ?';
            $bindings[] = $projectId;
        }

        if ($category) {
            $sql .= ' AND entry_id IN (SELECT id FROM knowledge_entries WHERE category = ?)';
            $bindings[] = $category;
        }

        $sql .= ' ORDER BY embedding <=> ?::vector LIMIT ?';
        $bindings[] = $vectorStr;
        $bindings[] = $this->vectorTopK;

        $results = DB::select($sql, $bindings);

        // Aggregate by entry_id (max score).
        // Filter out non-finite scores: pgvector returns NaN for cosine
        // distance against a zero-norm vector, and Postgres treats NaN as
        // greater than any number (so it slips through the >= minScore filter).
        // PDO returns the score as the string "NaN", which PHP's (float) cast
        // silently converts to 0.0, so we must inspect the raw value.
        $aggregated = [];
        $rank = 0;
        foreach ($results as $row) {
            $rawScore = is_string($row->score) ? strtoupper($row->score) : (string) $row->score;
            if ($rawScore === 'NAN' || $rawScore === 'INF' || $rawScore === '-INF') {
                continue;
            }

            $score = (float) $row->score;
            if (! is_finite($score)) {
                continue;
            }

            if (! isset($aggregated[$row->entry_id])) {
                $aggregated[$row->entry_id] = ['score' => $score, 'rank' => $rank++];
            } else {
                $aggregated[$row->entry_id]['score'] = max($aggregated[$row->entry_id]['score'], $score);
            }
        }

        return $aggregated;
    }

    /**
     * @return array<string, array{score: float, rank: int}>
     */
    private function ftsSearch(string $query, ?string $projectId, ?string $category): array
    {
        $sql = "SELECT id as entry_id, ts_rank(search_vector, plainto_tsquery('english', ?)) as score
                FROM knowledge_entries
                WHERE status = 'approved'
                AND search_vector @@ plainto_tsquery('english', ?)";

        $bindings = [$query, $query];

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
     * @param  array<string, array{score: float, rank: int}>  $vector
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
    private function hydrate(array $fused, string $query): array
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

            $snippet = $this->highlight($entry->content, $query);

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

    private function highlight(string $content, string $query): string
    {
        $result = DB::selectOne(
            "SELECT ts_headline('english', ?, plainto_tsquery('english', ?)) as headline",
            [$content, $query]
        );

        if ($result === null) {
            return mb_substr($content, 0, 200);
        }

        return $result->headline;
    }
}
