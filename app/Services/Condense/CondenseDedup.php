<?php

namespace App\Services\Condense;

use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

class CondenseDedup
{
    private const string PROVIDER = 'local-embedder';

    public function isDuplicate(string $projectId, string $title, string $content, float $threshold): bool
    {
        $vector = $this->embed($title."\n".$content);
        $vectorStr = '['.implode(',', $vector).']';

        $row = DB::selectOne(
            'SELECT 1 - (embedding <=> ?::vector) AS score
             FROM chunk_embeddings
             WHERE project_id = ?
             ORDER BY embedding <=> ?::vector
             LIMIT 1',
            [$vectorStr, $projectId, $vectorStr],
        );

        if ($row === null) {
            return false;
        }

        // Guard against pgvector NaN (zero-norm vectors) which PHP casts to 0.0.
        $raw = is_string($row->score) ? strtoupper($row->score) : (string) $row->score;
        if ($raw === 'NAN' || $raw === 'INF' || $raw === '-INF') {
            return false;
        }

        $score = (float) $row->score;

        return is_finite($score) && $score >= $threshold;
    }

    /**
     * Seam for testing; generates the query embedding.
     *
     * @return list<float>
     */
    protected function embed(string $text): array
    {
        return Embeddings::for([$text])->generate((string) config('rag.embeddings.provider', self::PROVIDER))->embeddings[0];
    }
}
