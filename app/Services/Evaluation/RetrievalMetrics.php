<?php

namespace App\Services\Evaluation;

use InvalidArgumentException;
use Normalizer;

final class RetrievalMetrics
{
    /**
     * @param  list<string>  $rankedTitles
     * @param  list<string>  $expectedTitles
     * @return array{recall: float, reciprocalRank: float, ndcg: float, zeroResults: bool}
     */
    public function calculate(array $rankedTitles, array $expectedTitles, int $k): array
    {
        $normalizedExpected = array_values(array_filter(
            array_map($this->normalize(...), $expectedTitles),
            static fn (string $title): bool => $title !== '',
        ));

        if ($normalizedExpected === []) {
            throw new InvalidArgumentException(__('rag.evaluation.expected_titles_required'));
        }

        if ($k < 1) {
            throw new InvalidArgumentException(__('rag.evaluation.k_invalid'));
        }

        $expected = array_fill_keys($normalizedExpected, true);
        $ranked = array_slice(array_map($this->normalize(...), $rankedTitles), 0, $k);

        $hits = 0;
        $reciprocalRank = 0.0;
        $dcg = 0.0;
        $seenRelevant = [];

        foreach ($ranked as $index => $title) {
            if (! isset($expected[$title]) || isset($seenRelevant[$title])) {
                continue;
            }

            $seenRelevant[$title] = true;
            $hits++;
            $rank = $index + 1;
            $reciprocalRank = $reciprocalRank === 0.0 ? (float) (1 / $rank) : $reciprocalRank;
            $dcg += 1 / log($rank + 1, 2);
        }

        $idealHits = min(count($expected), $k);
        $idealDcg = 0.0;
        for ($rank = 1; $rank <= $idealHits; $rank++) {
            $idealDcg += 1 / log($rank + 1, 2);
        }

        return [
            'recall' => (float) ($hits / count($expected)),
            'reciprocalRank' => $reciprocalRank,
            'ndcg' => $idealDcg > 0.0 ? (float) ($dcg / $idealDcg) : 0.0,
            'zeroResults' => $ranked === [],
        ];
    }

    private function normalize(string $title): string
    {
        $normalized = Normalizer::normalize($title, Normalizer::FORM_C);
        $normalized = $normalized === false ? $title : $normalized;
        $trimmed = preg_replace('/^\s+|\s+$/u', '', $normalized);

        return mb_strtolower($trimmed ?? trim($normalized));
    }
}
