<?php

namespace App\Martis\Dashboards;

use App\Models\ChunkEmbedding;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use Illuminate\Http\Request;
use Martis\Dashboards\Dashboard;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;

class MainDashboard extends Dashboard
{
    public function __construct(?string $name = null, ?string $uriKey = null)
    {
        parent::__construct($name ?? 'Main', $uriKey ?? 'main');
    }

    /**
     * Dashboard cards — one ValueMetric per headline stat.
     *
     * Martis' ValueMetric is abstract and requires a calculate() method,
     * so each card is an anonymous subclass that returns a ValueResult
     * with the current count. No date-range comparison is needed for
     * these totals, so we skip the parent aggregate() helpers and
     * return a plain result.
     *
     * @return array<int, ValueMetric>
     */
    public function cards(Request $request): array
    {
        return [
            new class('Projects', 'projects-count') extends ValueMetric
            {
                public function calculate(Request $request): ValueResult
                {
                    return new ValueResult(Project::count());
                }
            },

            new class('Total Entries', 'entries-count') extends ValueMetric
            {
                public function calculate(Request $request): ValueResult
                {
                    return new ValueResult(KnowledgeEntry::count());
                }
            },

            new class('Pending Approvals', 'pending-approvals') extends ValueMetric
            {
                public function calculate(Request $request): ValueResult
                {
                    return new ValueResult(
                        KnowledgeEntry::where('status', 'pending')->count()
                    );
                }
            },

            new class('Chunk Embeddings', 'chunk-embeddings') extends ValueMetric
            {
                public function calculate(Request $request): ValueResult
                {
                    return new ValueResult(ChunkEmbedding::count());
                }
            },
        ];
    }

    public function showRefreshButton(): bool
    {
        return true;
    }
}