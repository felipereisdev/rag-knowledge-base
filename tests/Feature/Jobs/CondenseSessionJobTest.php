<?php

use App\Jobs\CondenseSessionJob;
use App\Models\CondenseRun;
use App\Models\CondenseSetting;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Condense\CondenseDedup;
use App\Services\Condense\KnowledgeExtractor;
use App\Services\Condense\KnowledgeExtractorFactory;
use App\Services\Condense\TranscriptParser;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Keep the suite hermetic: don't let created entries synchronously index
    // (which would call the real embedder) under the sync queue.
    Queue::fake();
    Project::create(['id' => 'p1', 'name' => 'p1', 'root_path' => '/tmp/p1']);
    CondenseSetting::current();

    // Stub the parser to return fixed text regardless of path.
    app()->bind(TranscriptParser::class, fn () => new class extends TranscriptParser {
        public function parse(string $path, int $maxChars): string { return 'USER: hi'; }
    });

    // Stub the extractor factory to return one candidate.
    app()->bind(KnowledgeExtractorFactory::class, fn () => new class extends KnowledgeExtractorFactory {
        public function __construct() {}
        public function make($setting): KnowledgeExtractor
        {
            return new class implements KnowledgeExtractor {
                public function extract(string $transcript): array
                {
                    return [[
                        'title' => 'Use database queue', 'content' => '# c', 'category' => 'design-decision',
                        'entities' => [], 'relations' => [],
                    ]];
                }
            };
        }
    });

    // Stub dedup to "not duplicate".
    app()->bind(CondenseDedup::class, fn () => new class extends CondenseDedup {
        public function isDuplicate(string $p, string $t, string $c, float $th): bool { return false; }
    });
});

it('creates a pending entry and records a done run', function () {
    (new CondenseSessionJob('p1', '/tmp/whatever.jsonl', 'sess-1'))->handle(
        app(TranscriptParser::class), app(KnowledgeExtractorFactory::class),
        app(CondenseDedup::class), app(\App\Services\Knowledge\KnowledgeWriter::class),
    );

    expect(KnowledgeEntry::where('project_id', 'p1')->where('status', 'pending')->count())->toBe(1);
    $run = CondenseRun::where('session_id', 'sess-1')->first();
    expect($run->status)->toBe('done');
    expect($run->entries_created)->toBe(1);
});

it('is idempotent for the same session_id', function () {
    CondenseRun::create(['session_id' => 'sess-1', 'project_id' => 'p1', 'status' => 'done']);

    (new CondenseSessionJob('p1', '/tmp/whatever.jsonl', 'sess-1'))->handle(
        app(TranscriptParser::class), app(KnowledgeExtractorFactory::class),
        app(CondenseDedup::class), app(\App\Services\Knowledge\KnowledgeWriter::class),
    );

    expect(KnowledgeEntry::where('project_id', 'p1')->count())->toBe(0);
});

it('skips creation when a candidate is a duplicate', function () {
    app()->bind(CondenseDedup::class, fn () => new class extends CondenseDedup {
        public function isDuplicate(string $p, string $t, string $c, float $th): bool { return true; }
    });

    (new CondenseSessionJob('p1', '/tmp/whatever.jsonl', 'sess-2'))->handle(
        app(TranscriptParser::class), app(KnowledgeExtractorFactory::class),
        app(CondenseDedup::class), app(\App\Services\Knowledge\KnowledgeWriter::class),
    );

    expect(KnowledgeEntry::where('project_id', 'p1')->count())->toBe(0);
    expect(CondenseRun::where('session_id', 'sess-2')->first()->status)->toBe('skipped');
});
