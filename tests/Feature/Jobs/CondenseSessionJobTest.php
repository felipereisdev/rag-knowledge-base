<?php

use App\Enums\ImportanceClassifierMode;
use App\Enums\KnowledgeSource;
use App\Enums\KnowledgeStatus;
use App\Jobs\ClassifyKnowledgeEntryJob;
use App\Jobs\CondenseSessionJob;
use App\Models\CondenseRun;
use App\Models\CondenseSetting;
use App\Models\ImportanceClassifierSetting;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Condense\CondenseDedup;
use App\Services\Condense\KnowledgeExtractor;
use App\Services\Condense\KnowledgeExtractorFactory;
use App\Services\Condense\TranscriptParser;
use App\Services\Knowledge\KnowledgeWriter;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Keep the suite hermetic: don't let created entries synchronously index
    // (which would call the real embedder) under the sync queue.
    Queue::fake();
    Project::create(['id' => 'p1', 'name' => 'p1', 'root_path' => '/tmp/p1']);
    CondenseSetting::current();

    // Stub the parser to return fixed text regardless of path.
    app()->bind(TranscriptParser::class, fn () => new class extends TranscriptParser
    {
        public function parse(string $path, int $maxChars): string
        {
            return 'USER: hi';
        }
    });

    // Stub the extractor factory to return one candidate.
    app()->bind(KnowledgeExtractorFactory::class, fn () => new class extends KnowledgeExtractorFactory
    {
        public function __construct() {}

        public function make($setting): KnowledgeExtractor
        {
            return new class implements KnowledgeExtractor
            {
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
    app()->bind(CondenseDedup::class, fn () => new class extends CondenseDedup
    {
        public function isDuplicate(string $p, string $t, string $c, float $th): bool
        {
            return false;
        }
    });
});

it('creates a classifying entry, dispatches classification, and records a done run', function () {
    // The seeded classifier mode is `shadow`, so a condensed entry is captured
    // for classification rather than going straight to a human.
    $path = tempnam(sys_get_temp_dir(), 'tr').'.jsonl';
    file_put_contents($path, "{}\n");

    (new CondenseSessionJob('p1', $path, 'sess-1'))->handle(
        app(TranscriptParser::class), app(KnowledgeExtractorFactory::class),
        app(CondenseDedup::class), app(KnowledgeWriter::class),
    );

    $entry = KnowledgeEntry::where('project_id', 'p1')->firstOrFail();
    expect($entry->source)->toBe(KnowledgeSource::Condense->value)
        ->and($entry->status)->toBe(KnowledgeStatus::Classifying->value);
    Queue::assertPushed(
        ClassifyKnowledgeEntryJob::class,
        fn (ClassifyKnowledgeEntryJob $job): bool => $job->entryId === (int) $entry->id,
    );

    $run = CondenseRun::where('session_id', 'sess-1')->first();
    expect($run->status)->toBe('done');
    expect($run->entries_created)->toBe(1);
});

it('creates a pending entry and dispatches nothing when the classifier is off', function () {
    ImportanceClassifierSetting::query()->findOrFail(1)->update([
        'mode' => ImportanceClassifierMode::Off->value,
    ]);

    $path = tempnam(sys_get_temp_dir(), 'tr').'.jsonl';
    file_put_contents($path, "{}\n");

    (new CondenseSessionJob('p1', $path, 'sess-off'))->handle(
        app(TranscriptParser::class), app(KnowledgeExtractorFactory::class),
        app(CondenseDedup::class), app(KnowledgeWriter::class),
    );

    expect(KnowledgeEntry::where('project_id', 'p1')->where('status', KnowledgeStatus::Pending->value)->count())->toBe(1);
    Queue::assertNotPushed(ClassifyKnowledgeEntryJob::class);
    expect(CondenseRun::where('session_id', 'sess-off')->first()->status)->toBe('done');
});

it('is idempotent for the same session_id', function () {
    CondenseRun::create(['session_id' => 'sess-1', 'project_id' => 'p1', 'status' => 'done']);

    (new CondenseSessionJob('p1', '/tmp/whatever.jsonl', 'sess-1'))->handle(
        app(TranscriptParser::class), app(KnowledgeExtractorFactory::class),
        app(CondenseDedup::class), app(KnowledgeWriter::class),
    );

    expect(KnowledgeEntry::where('project_id', 'p1')->count())->toBe(0);
});

it('skips creation when a candidate is a duplicate', function () {
    app()->bind(CondenseDedup::class, fn () => new class extends CondenseDedup
    {
        public function isDuplicate(string $p, string $t, string $c, float $th): bool
        {
            return true;
        }
    });

    $path = tempnam(sys_get_temp_dir(), 'tr').'.jsonl';
    file_put_contents($path, "{}\n");

    (new CondenseSessionJob('p1', $path, 'sess-2'))->handle(
        app(TranscriptParser::class), app(KnowledgeExtractorFactory::class),
        app(CondenseDedup::class), app(KnowledgeWriter::class),
    );

    expect(KnowledgeEntry::where('project_id', 'p1')->count())->toBe(0);
    expect(CondenseRun::where('session_id', 'sess-2')->first()->status)->toBe('skipped');
});

it('marks the run failed for an unreadable transcript', function () {
    (new CondenseSessionJob('p1', '/no/such/dir/nope.jsonl', 'sess-unreadable'))->handle(
        app(TranscriptParser::class), app(KnowledgeExtractorFactory::class),
        app(CondenseDedup::class), app(KnowledgeWriter::class),
    );

    $run = CondenseRun::where('session_id', 'sess-unreadable')->first();
    expect($run->status)->toBe('failed');
    expect(KnowledgeEntry::where('project_id', 'p1')->count())->toBe(0);
});

it('returns early without creating a run when disabled', function () {
    CondenseSetting::current()->update(['enabled' => false]);

    (new CondenseSessionJob('p1', '/tmp/whatever.jsonl', 'sess-disabled'))->handle(
        app(TranscriptParser::class), app(KnowledgeExtractorFactory::class),
        app(CondenseDedup::class), app(KnowledgeWriter::class),
    );

    expect(CondenseRun::where('session_id', 'sess-disabled')->exists())->toBeFalse();
    expect(KnowledgeEntry::where('project_id', 'p1')->count())->toBe(0);
});

it('records failed when a collaborator throws', function () {
    app()->bind(TranscriptParser::class, fn () => new class extends TranscriptParser
    {
        public function parse(string $path, int $maxChars): string
        {
            throw new RuntimeException('boom');
        }
    });

    $path = tempnam(sys_get_temp_dir(), 'tr').'.jsonl';
    file_put_contents($path, "{}\n");

    (new CondenseSessionJob('p1', $path, 'sess-throws'))->handle(
        app(TranscriptParser::class), app(KnowledgeExtractorFactory::class),
        app(CondenseDedup::class), app(KnowledgeWriter::class),
    );

    $run = CondenseRun::where('session_id', 'sess-throws')->first();
    expect($run->status)->toBe('failed');
    expect(KnowledgeEntry::where('project_id', 'p1')->count())->toBe(0);
});

it('records skipped when the transcript has no durable text', function () {
    app()->bind(TranscriptParser::class, fn () => new class extends TranscriptParser
    {
        public function parse(string $path, int $maxChars): string
        {
            return '';
        }
    });

    $path = tempnam(sys_get_temp_dir(), 'tr').'.jsonl';
    file_put_contents($path, "{}\n");

    (new CondenseSessionJob('p1', $path, 'sess-empty'))->handle(
        app(TranscriptParser::class), app(KnowledgeExtractorFactory::class),
        app(CondenseDedup::class), app(KnowledgeWriter::class),
    );

    $run = CondenseRun::where('session_id', 'sess-empty')->first();
    expect($run->status)->toBe('skipped');
    expect(KnowledgeEntry::where('project_id', 'p1')->count())->toBe(0);
});
