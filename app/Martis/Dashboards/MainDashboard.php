<?php

namespace App\Martis\Dashboards;

use App\Enums\KnowledgeStatus;
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

            // The human queue. `classifying` entries are deliberately NOT counted
            // here: nobody can act on them yet, so folding them into the approval
            // queue would inflate a number an administrator is meant to work down.
            new class(__('rag.dashboard.pending_approvals'), 'pending-approvals') extends ValueMetric
            {
                public function calculate(Request $request): ValueResult
                {
                    return new ValueResult(
                        KnowledgeEntry::where('status', KnowledgeStatus::Pending->value)->count()
                    );
                }
            },

            // The classifier's own backlog: entries in flight. A number that stops
            // falling is how a stalled classification queue becomes visible.
            new class(__('importance.dashboard.classifying'), 'classifying-count') extends ValueMetric
            {
                public function calculate(Request $request): ValueResult
                {
                    return new ValueResult(
                        KnowledgeEntry::where('status', KnowledgeStatus::Classifying->value)->count()
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
