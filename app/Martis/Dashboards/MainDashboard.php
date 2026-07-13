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
        parent::__construct($name ?? __('rag.dashboard.main'), $uriKey ?? 'main');
        $this->withIcon('chart-line-up');
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
            new class(__('rag.dashboard.projects'), 'projects-count') extends ValueMetric
            {
                public function calculate(Request $request): ValueResult
                {
                    return new ValueResult(Project::count());
                }
            },

            new class(__('rag.dashboard.total_entries'), 'entries-count') extends ValueMetric
            {
                public function calculate(Request $request): ValueResult
                {
                    return new ValueResult(KnowledgeEntry::count());
                }
            },

            new class(__('rag.dashboard.pending_approvals'), 'pending-approvals') extends ValueMetric
            {
                public function calculate(Request $request): ValueResult
                {
                    return new ValueResult(
                        KnowledgeEntry::where('status', 'pending')->count()
                    );
                }
            },

            new class(__('rag.dashboard.chunk_embeddings'), 'chunk-embeddings') extends ValueMetric
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
