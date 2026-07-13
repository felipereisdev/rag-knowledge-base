<?php

namespace App\Services\Importance;

use App\Enums\ImportanceClassifierMode;
use App\Enums\KnowledgeSource;
use App\Enums\KnowledgeStatus;

final class KnowledgeIngestionPolicy
{
    public function shouldClassify(ImportanceClassifierMode $mode, KnowledgeSource|string $source): bool
    {
        if ($mode === ImportanceClassifierMode::Off) {
            return false;
        }

        return in_array($this->source($source), [
            KnowledgeSource::Condense,
            KnowledgeSource::Mcp,
            KnowledgeSource::Cli,
        ], true);
    }

    public function initialStatus(ImportanceClassifierMode $mode, KnowledgeSource|string $source): KnowledgeStatus
    {
        return $this->shouldClassify($mode, $source)
            ? KnowledgeStatus::Classifying
            : KnowledgeStatus::Pending;
    }

    private function source(KnowledgeSource|string $source): KnowledgeSource
    {
        return $source instanceof KnowledgeSource ? $source : KnowledgeSource::from($source);
    }
}
