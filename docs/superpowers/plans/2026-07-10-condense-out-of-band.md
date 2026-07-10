# Condensação de conhecimento out-of-band (padrão claude-mem) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir a condensação in-band do Stop hook por um fluxo out-of-band: o hook faz um POST fire-and-forget e um job no worker lê o transcript, extrai conhecimento durável via LLM e grava em `pending` para revisão.

**Architecture:** Cliente (shell hook) manda `{cwd, session_id, transcript_path}` para `POST /hooks/condense`; o `HookController` resolve o projeto e despacha `CondenseSessionJob` (fila `database`), respondendo 202. O job lê o transcript (`TranscriptParser`), extrai candidatos via um `KnowledgeExtractor` (driver `claude_sdk` = spawn `claude -p`, ou `api` = `Laravel\Ai`), deduplica por similaridade vetorial (`CondenseDedup`) e persiste via `KnowledgeWriter`. Config do driver/modelo vive num `CondenseSetting` (linha única) editável por um resource Martis.

**Tech Stack:** Laravel 12, PHP 8.4, Pest 4, Postgres + pgvector, `Laravel\Ai`, Martis, Symfony Process, filas `database`.

## Global Constraints

- **NUNCA** adicionar `Co-Authored-By: Claude` (nem qualquer co-autoria) em mensagens de commit.
- Toda mensagem de commit termina com `Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4`.
- Testes em **Pest** (`it('...', function () { ... })`), rodar com `php artisan test` ou `./vendor/bin/pest`.
- Enumerações finitas → **PHP Enum** com `options(): array` alimentando `Select::make(...)->options(...)` (regra do CLAUDE.md; ver `App\Enums\ProjectTechnology`).
- Toda string visível de Martis via `__()`, com chaves iguais em `lang/{en,pt_PT,pt_BR}`.
- Nunca editar `vendor/**`. Código do host em `app/**`, hooks-cliente em `stubs/client/**` + cópias vivas em `.claude/hooks/**`.
- Hooks nunca podem quebrar a sessão: toda falha de rede/LLM/parse é engolida e logada.
- Trabalhar na branch `feat/condense-out-of-band` (já criada). Commits frequentes, um por task.

---

### Task 1: `KnowledgeWriter` — extrair a escrita transacional (DRY)

Hoje `RagStoreKnowledgeTool::handle` faz inline a criação de entrada + tags + entities + relations. Extrair para um serviço reusável pelo Tool e pelo futuro job.

**Files:**
- Create: `app/Services/Knowledge/KnowledgeWriter.php`
- Modify: `app/Mcp/Tools/RagStoreKnowledgeTool.php` (substituir o bloco `DB::transaction`)
- Test: `tests/Unit/Services/KnowledgeWriterTest.php`

**Interfaces:**
- Produces:
  ```php
  namespace App\Services\Knowledge;
  final class KnowledgeWriter {
      /**
       * @param list<array{name:string, type?:string}> $entities
       * @param list<array{subject:string, predicate:string, object:string}> $relations
       * @param list<string> $tags
       */
      public function store(
          string $projectId,
          string $title,
          string $content,
          string $category,
          string $source,
          array $tags = [],
          array $entities = [],
          array $relations = [],
      ): \App\Models\KnowledgeEntry;
  }
  ```
  Sempre cria com `status = 'pending'`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Services/KnowledgeWriterTest.php

use App\Models\Entity;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Models\Relation;
use App\Services\Knowledge\KnowledgeWriter;

beforeEach(function () {
    Project::create(['id' => 'p1', 'name' => 'p1', 'root_path' => '/tmp/p1']);
});

it('creates a pending entry with tags, entities and relations', function () {
    $writer = app(KnowledgeWriter::class);

    $entry = $writer->store(
        projectId: 'p1',
        title: 'Use pgvector for search',
        content: '# note',
        category: 'decision',
        source: 'condense',
        tags: ['search', 'db'],
        entities: [['name' => 'pgvector', 'type' => 'library'], ['name' => 'HybridSearcher', 'type' => 'class']],
        relations: [['subject' => 'HybridSearcher', 'predicate' => 'uses', 'object' => 'pgvector']],
    );

    expect($entry->status)->toBe('pending');
    expect($entry->source)->toBe('condense');
    expect($entry->tags()->count())->toBe(2);
    expect($entry->entities()->count())->toBe(2);
    expect(Relation::where('entry_id', $entry->id)->count())->toBe(1);
    expect(Entity::where('project_id', 'p1')->where('name', 'pgvector')->exists())->toBeTrue();
    expect(KnowledgeEntry::where('project_id', 'p1')->where('status', 'pending')->count())->toBe(1);
});

it('reuses an existing entity for relations referenced by name', function () {
    Entity::create(['project_id' => 'p1', 'name' => 'HybridSearcher', 'type' => 'class']);
    $writer = app(KnowledgeWriter::class);

    $writer->store('p1', 't', 'c', 'insight', 'condense',
        relations: [['subject' => 'HybridSearcher', 'predicate' => 'uses', 'object' => 'pgvector']]);

    // No duplicate typed entity created; a bare 'pgvector' placeholder is added.
    expect(Entity::where('project_id', 'p1')->where('name', 'HybridSearcher')->count())->toBe(1);
    expect(Entity::where('project_id', 'p1')->where('name', 'pgvector')->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Services/KnowledgeWriterTest.php`
Expected: FAIL (class `App\Services\Knowledge\KnowledgeWriter` not found).

- [ ] **Step 3: Write the implementation**

Move a lógica hoje inline no Tool (linhas ~45–97 de `RagStoreKnowledgeTool`, incluindo `findOrCreateNamedEntity`) para o serviço:

```php
<?php

namespace App\Services\Knowledge;

use App\Models\Entity;
use App\Models\KnowledgeEntry;
use App\Models\Relation;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

final class KnowledgeWriter
{
    /**
     * @param  list<string>  $tags
     * @param  list<array{name:string, type?:string}>  $entities
     * @param  list<array{subject:string, predicate:string, object:string}>  $relations
     */
    public function store(
        string $projectId,
        string $title,
        string $content,
        string $category,
        string $source,
        array $tags = [],
        array $entities = [],
        array $relations = [],
    ): KnowledgeEntry {
        return DB::transaction(function () use ($projectId, $title, $content, $category, $source, $tags, $entities, $relations) {
            $entry = KnowledgeEntry::create([
                'project_id' => $projectId,
                'title' => $title,
                'content' => $content,
                'category' => $category,
                'source' => $source,
                'status' => 'pending',
            ]);

            foreach ($tags as $tagName) {
                $tag = Tag::firstOrCreate(['project_id' => $projectId, 'name' => $tagName]);
                $entry->tags()->attach($tag->id);
            }

            foreach ($entities as $entityData) {
                $entity = Entity::firstOrCreate([
                    'project_id' => $projectId,
                    'name' => $entityData['name'],
                    'type' => $entityData['type'] ?? '',
                ]);
                $entry->entities()->attach($entity->id);
            }

            foreach ($relations as $relData) {
                $subject = $this->findOrCreateNamedEntity($projectId, $relData['subject']);
                $object = $this->findOrCreateNamedEntity($projectId, $relData['object']);
                Relation::create([
                    'project_id' => $projectId,
                    'subject_id' => $subject->id,
                    'predicate' => $relData['predicate'],
                    'object_id' => $object->id,
                    'entry_id' => $entry->id,
                ]);
            }

            return $entry;
        });
    }

    private function findOrCreateNamedEntity(string $projectId, string $name): Entity
    {
        $entity = Entity::where('project_id', $projectId)->where('name', $name)->first();

        return $entity ?? Entity::create(['project_id' => $projectId, 'name' => $name, 'type' => '']);
    }
}
```

- [ ] **Step 4: Refactor `RagStoreKnowledgeTool` to use the writer**

Em `app/Mcp/Tools/RagStoreKnowledgeTool.php`, substituir o bloco `$entry = DB::transaction(...)` (linhas ~45–97) por:

```php
$entry = app(\App\Services\Knowledge\KnowledgeWriter::class)->store(
    $pid, $title, $content, $category, 'mcp', $tags, $entities, $relations,
);
```

Remover o método privado `findOrCreateNamedEntity` do Tool (agora vive no writer) e os imports que ficarem sem uso (`DB`, `Entity`, `Relation`, `Tag`). Manter `coerceStringList` e `coerceGraphItems` (coerção de input) e os imports `KnowledgeEntry`, `Project`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Services/KnowledgeWriterTest.php tests/Feature/Mcp`
Expected: PASS (writer + os testes existentes do `rag_store_knowledge` continuam verdes).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Knowledge/KnowledgeWriter.php app/Mcp/Tools/RagStoreKnowledgeTool.php tests/Unit/Services/KnowledgeWriterTest.php
git commit -m "refactor: extract KnowledgeWriter from RagStoreKnowledgeTool

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 2: Indexar entradas `pending` (mudança no observer)

Para o dedup vetorial cobrir approved+pending, entradas `pending` precisam gerar `chunk_embeddings`. A busca normal continua filtrando `approved` (`HybridSearcher::hydrate`/`ftsSearch`), então pending não vaza.

**Files:**
- Modify: `app/Observers/KnowledgeEntryObserver.php`
- Test: `tests/Unit/Observers/KnowledgeEntryObserverTest.php`

**Interfaces:**
- Consumes: `App\Jobs\IndexEntryJob` (já existe).
- Produces: entradas `pending` e `approved` disparam `IndexEntryJob` no `created`/`updated`; `rejected` e `deleted` apagam `chunk_embeddings`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Observers/KnowledgeEntryObserverTest.php

use App\Jobs\IndexEntryJob;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Project::create(['id' => 'p1', 'name' => 'p1', 'root_path' => '/tmp/p1']);
});

it('indexes pending entries on create', function () {
    Queue::fake();

    $entry = KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'condense', 'status' => 'pending',
    ]);

    Queue::assertPushed(IndexEntryJob::class, fn ($job) => $job->entryId === (string) $entry->id);
});

it('still indexes approved entries on create', function () {
    Queue::fake();

    KnowledgeEntry::create([
        'project_id' => 'p1', 'title' => 't', 'content' => 'c',
        'category' => 'insight', 'source' => 'manual', 'status' => 'approved',
    ]);

    Queue::assertPushed(IndexEntryJob::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Observers/KnowledgeEntryObserverTest.php`
Expected: FAIL no primeiro caso ("pending on create") — hoje o observer só indexa `approved`.

- [ ] **Step 3: Implement**

Editar `app/Observers/KnowledgeEntryObserver.php`:

```php
public function created(KnowledgeEntry $entry): void
{
    if (in_array($entry->status, ['approved', 'pending'], true)) {
        IndexEntryJob::dispatch($entry->id);
    }
}

public function updated(KnowledgeEntry $entry): void
{
    if (in_array($entry->status, ['approved', 'pending'], true)) {
        IndexEntryJob::dispatch($entry->id);
    } elseif ($entry->status === 'rejected') {
        DB::table('chunk_embeddings')->where('entry_id', $entry->id)->delete();
    }
}
```

`deleted()` fica inalterado.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Observers/KnowledgeEntryObserverTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Observers/KnowledgeEntryObserver.php tests/Unit/Observers/KnowledgeEntryObserverTest.php
git commit -m "feat: index pending knowledge entries for dedup coverage

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 3: Enums `ExtractorDriver` e `ExtractorProvider`

**Files:**
- Create: `app/Enums/ExtractorDriver.php`, `app/Enums/ExtractorProvider.php`
- Test: `tests/Unit/Enums/ExtractorEnumsTest.php`

**Interfaces:**
- Produces:
  ```php
  App\Enums\ExtractorDriver: cases ClaudeSdk='claude_sdk', Api='api'; static options(): array<string,string>
  App\Enums\ExtractorProvider: cases Anthropic='anthropic', Openai='openai', Gemini='gemini', Openrouter='openrouter'; static options(): array<string,string>
  ```

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Enums/ExtractorEnumsTest.php

use App\Enums\ExtractorDriver;
use App\Enums\ExtractorProvider;

it('exposes driver options keyed by value', function () {
    expect(ExtractorDriver::options())->toHaveKeys(['claude_sdk', 'api']);
    expect(ExtractorDriver::from('claude_sdk'))->toBe(ExtractorDriver::ClaudeSdk);
});

it('exposes provider options keyed by value', function () {
    expect(ExtractorProvider::options())->toHaveKeys(['anthropic', 'openai', 'gemini', 'openrouter']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Enums/ExtractorEnumsTest.php`
Expected: FAIL (enums não existem).

- [ ] **Step 3: Implement**

```php
<?php
// app/Enums/ExtractorDriver.php
namespace App\Enums;

enum ExtractorDriver: string
{
    case ClaudeSdk = 'claude_sdk';
    case Api = 'api';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::ClaudeSdk->value => 'Claude SDK (subscription)',
            self::Api->value => 'API provider',
        ];
    }
}
```

```php
<?php
// app/Enums/ExtractorProvider.php
namespace App\Enums;

enum ExtractorProvider: string
{
    case Anthropic = 'anthropic';
    case Openai = 'openai';
    case Gemini = 'gemini';
    case Openrouter = 'openrouter';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Anthropic->value => 'Anthropic',
            self::Openai->value => 'OpenAI',
            self::Gemini->value => 'Gemini',
            self::Openrouter->value => 'OpenRouter',
        ];
    }
}
```

- [ ] **Step 4: Run + Commit**

Run: `./vendor/bin/pest tests/Unit/Enums/ExtractorEnumsTest.php` → PASS

```bash
git add app/Enums/ExtractorDriver.php app/Enums/ExtractorProvider.php tests/Unit/Enums/ExtractorEnumsTest.php
git commit -m "feat: add ExtractorDriver and ExtractorProvider enums

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 4: `CondenseSetting` model + migração + seed

**Files:**
- Create: `database/migrations/2026_07_10_000001_create_condense_settings_table.php`
- Create: `app/Models/CondenseSetting.php`
- Test: `tests/Unit/Models/CondenseSettingTest.php`

**Interfaces:**
- Produces:
  ```php
  App\Models\CondenseSetting
    columns: enabled(bool), driver(string), provider(?string), model(string),
             min_dedup_score(float), max_transcript_chars(int), system_prompt_override(?string)
    static current(): self   // a linha única, criada com defaults se ausente
  ```

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Models/CondenseSettingTest.php

use App\Models\CondenseSetting;

it('returns a singleton row with sane defaults', function () {
    $s = CondenseSetting::current();

    expect($s->enabled)->toBeTrue();
    expect($s->driver)->toBe('claude_sdk');
    expect($s->provider)->toBeNull();
    expect($s->model)->toBe('claude-haiku-4-5-20251001');
    expect($s->min_dedup_score)->toBe(0.85);
    expect($s->max_transcript_chars)->toBe(24000);

    // idempotent: no second row is created
    CondenseSetting::current();
    expect(CondenseSetting::count())->toBe(1);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Models/CondenseSettingTest.php`
Expected: FAIL (tabela/model ausentes).

- [ ] **Step 3: Migration**

```php
<?php
// database/migrations/2026_07_10_000001_create_condense_settings_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('condense_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->string('driver')->default('claude_sdk');
            $table->string('provider')->nullable();
            $table->string('model')->default('claude-haiku-4-5-20251001');
            $table->float('min_dedup_score')->default(0.85);
            $table->integer('max_transcript_chars')->default(24000);
            $table->text('system_prompt_override')->nullable();
            $table->timestamps();
        });

        DB::table('condense_settings')->insert([
            'enabled' => true,
            'driver' => 'claude_sdk',
            'model' => 'claude-haiku-4-5-20251001',
            'min_dedup_score' => 0.85,
            'max_transcript_chars' => 24000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('condense_settings');
    }
};
```

- [ ] **Step 4: Model**

```php
<?php
// app/Models/CondenseSetting.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CondenseSetting extends Model
{
    protected $fillable = [
        'enabled', 'driver', 'provider', 'model',
        'min_dedup_score', 'max_transcript_chars', 'system_prompt_override',
    ];

    protected $attributes = [
        'enabled' => true,
        'driver' => 'claude_sdk',
        'provider' => null,
        'model' => 'claude-haiku-4-5-20251001',
        'min_dedup_score' => 0.85,
        'max_transcript_chars' => 24000,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'min_dedup_score' => 'float',
            'max_transcript_chars' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::create([]);
    }
}
```

- [ ] **Step 5: Run + Commit**

Run: `php artisan migrate && ./vendor/bin/pest tests/Unit/Models/CondenseSettingTest.php` → PASS

```bash
git add database/migrations/2026_07_10_000001_create_condense_settings_table.php app/Models/CondenseSetting.php tests/Unit/Models/CondenseSettingTest.php
git commit -m "feat: add CondenseSetting singleton model + migration

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 5: `CondenseRun` model + migração (idempotência)

**Files:**
- Create: `database/migrations/2026_07_10_000002_create_condense_runs_table.php`
- Create: `app/Models/CondenseRun.php`
- Test: `tests/Unit/Models/CondenseRunTest.php`

**Interfaces:**
- Produces:
  ```php
  App\Models\CondenseRun
    columns: session_id(string, unique), project_id(string),
             status(string: running|done|skipped|failed), entries_created(int)
  ```

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Models/CondenseRunTest.php

use App\Models\CondenseRun;
use Illuminate\Database\QueryException;

it('enforces a unique session_id', function () {
    CondenseRun::create(['session_id' => 's1', 'project_id' => 'p1', 'status' => 'running']);

    expect(fn () => CondenseRun::create(['session_id' => 's1', 'project_id' => 'p1', 'status' => 'running']))
        ->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Models/CondenseRunTest.php`
Expected: FAIL (tabela/model ausentes).

- [ ] **Step 3: Migration + Model**

```php
<?php
// database/migrations/2026_07_10_000002_create_condense_runs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('condense_runs', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique();
            $table->string('project_id');
            $table->string('status')->default('running');
            $table->integer('entries_created')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('condense_runs');
    }
};
```

```php
<?php
// app/Models/CondenseRun.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CondenseRun extends Model
{
    protected $fillable = ['session_id', 'project_id', 'status', 'entries_created'];

    protected function casts(): array
    {
        return ['entries_created' => 'integer'];
    }
}
```

- [ ] **Step 4: Run + Commit**

Run: `php artisan migrate && ./vendor/bin/pest tests/Unit/Models/CondenseRunTest.php` → PASS

```bash
git add database/migrations/2026_07_10_000002_create_condense_runs_table.php app/Models/CondenseRun.php tests/Unit/Models/CondenseRunTest.php
git commit -m "feat: add CondenseRun model + migration for idempotency

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 6: `TranscriptParser`

Lê o JSONL do transcript do Claude Code, extrai só o texto de mensagens `user`/`assistant` (descartando `tool_use`/`tool_result`) e trunca mantendo o **final** (conclusões recentes).

**Files:**
- Create: `app/Services/Condense/TranscriptParser.php`
- Test: `tests/Unit/Services/TranscriptParserTest.php`

**Interfaces:**
- Produces: `parse(string $path, int $maxChars): string` — texto concatenado como `USER: ...` / `ASSISTANT: ...`; `''` se o arquivo não existir ou não houver texto.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Services/TranscriptParserTest.php

use App\Services\Condense\TranscriptParser;

function writeTranscript(array $lines): string
{
    $path = tempnam(sys_get_temp_dir(), 'tr').'.jsonl';
    file_put_contents($path, implode("\n", array_map('json_encode', $lines))."\n");

    return $path;
}

it('extracts user and assistant text, skipping tool noise', function () {
    $path = writeTranscript([
        ['type' => 'user', 'message' => ['role' => 'user', 'content' => 'Fix the bug']],
        ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [
            ['type' => 'text', 'text' => 'I will use pgvector.'],
            ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => 'ls']],
        ]]],
        ['type' => 'user', 'message' => ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'content' => 'file.txt'],
        ]]],
    ]);

    $out = app(TranscriptParser::class)->parse($path, 10000);

    expect($out)->toContain('USER: Fix the bug');
    expect($out)->toContain('ASSISTANT: I will use pgvector.');
    expect($out)->not->toContain('ls');
    expect($out)->not->toContain('file.txt');

    @unlink($path);
});

it('returns empty string for a missing file', function () {
    expect(app(TranscriptParser::class)->parse('/no/such/file.jsonl', 100))->toBe('');
});

it('keeps the tail when truncating', function () {
    $path = writeTranscript([
        ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => str_repeat('A', 500)]],
        ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => 'TAIL_MARKER']],
    ]);

    $out = app(TranscriptParser::class)->parse($path, 50);

    expect(mb_strlen($out))->toBeLessThanOrEqual(50);
    expect($out)->toContain('TAIL_MARKER');

    @unlink($path);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Services/TranscriptParserTest.php`
Expected: FAIL (classe ausente).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Condense;

final class TranscriptParser
{
    public function parse(string $path, int $maxChars): string
    {
        if (! is_readable($path)) {
            return '';
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return '';
        }

        $parts = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }
            $type = $event['type'] ?? null;
            if ($type !== 'user' && $type !== 'assistant') {
                continue;
            }
            $text = $this->extractText($event['message']['content'] ?? null);
            if ($text === '') {
                continue;
            }
            $parts[] = strtoupper($type).': '.$text;
        }
        fclose($handle);

        $joined = implode("\n\n", $parts);

        if (mb_strlen($joined) > $maxChars) {
            $joined = mb_substr($joined, -$maxChars);
        }

        return $joined;
    }

    private function extractText(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (! is_array($content)) {
            return '';
        }

        $texts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $texts[] = $block['text'];
            }
        }

        return trim(implode("\n", $texts));
    }
}
```

- [ ] **Step 4: Run + Commit**

Run: `./vendor/bin/pest tests/Unit/Services/TranscriptParserTest.php` → PASS

```bash
git add app/Services/Condense/TranscriptParser.php tests/Unit/Services/TranscriptParserTest.php
git commit -m "feat: add TranscriptParser for condense sessions

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 7: `ExtractionPrompt` + `CandidateParser`

Peças compartilhadas pelos dois drivers: a instrução (com override opcional) e o parser do texto do LLM → lista validada de candidatos.

**Files:**
- Create: `app/Services/Condense/ExtractionPrompt.php`
- Create: `app/Services/Condense/CandidateParser.php`
- Test: `tests/Unit/Services/CandidateParserTest.php`

**Interfaces:**
- Produces:
  ```php
  App\Services\Condense\ExtractionPrompt
    instructions(?string $override): string
  App\Services\Condense\CandidateParser
    /** @return list<array{title:string, content:string, category:string,
     *   entities:list<array{name:string,type:string}>,
     *   relations:list<array{subject:string,predicate:string,object:string}>}> */
    parse(string $raw): array
  ```

- [ ] **Step 1: Write the failing test** (parser é a peça com lógica)

```php
<?php
// tests/Unit/Services/CandidateParserTest.php

use App\Services\Condense\CandidateParser;

it('parses a JSON array, tolerating code fences and surrounding prose', function () {
    $raw = "Here you go:\n```json\n".json_encode([
        ['title' => 'A', 'content' => '# a', 'category' => 'decision',
         'entities' => [['name' => 'X', 'type' => 'class']],
         'relations' => [['subject' => 'X', 'predicate' => 'uses', 'object' => 'Y']]],
        ['title' => '', 'content' => 'dropped: no title'],
    ])."\n```";

    $out = app(CandidateParser::class)->parse($raw);

    expect($out)->toHaveCount(1);
    expect($out[0]['title'])->toBe('A');
    expect($out[0]['category'])->toBe('decision');
    expect($out[0]['entities'][0]['name'])->toBe('X');
    expect($out[0]['relations'][0]['predicate'])->toBe('uses');
});

it('defaults category to insight and drops malformed graph items', function () {
    $raw = json_encode([
        ['title' => 'T', 'content' => 'c',
         'entities' => [['type' => 'class']], // no name -> dropped
         'relations' => [['subject' => 'X', 'object' => 'Y']]], // no predicate -> dropped
    ]);

    $out = app(CandidateParser::class)->parse($raw);

    expect($out[0]['category'])->toBe('insight');
    expect($out[0]['entities'])->toBe([]);
    expect($out[0]['relations'])->toBe([]);
});

it('returns empty array for non-JSON or empty output', function () {
    expect(app(CandidateParser::class)->parse('nothing here'))->toBe([]);
    expect(app(CandidateParser::class)->parse('[]'))->toBe([]);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Services/CandidateParserTest.php`
Expected: FAIL (classe ausente).

- [ ] **Step 3: Implement `CandidateParser`**

```php
<?php

namespace App\Services\Condense;

final class CandidateParser
{
    /**
     * @return list<array{title:string, content:string, category:string,
     *   entities:list<array{name:string,type:string}>,
     *   relations:list<array{subject:string,predicate:string,object:string}>}>
     */
    public function parse(string $raw): array
    {
        $json = $this->extractJsonArray($raw);
        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            $content = trim((string) ($item['content'] ?? ''));
            if ($title === '' || $content === '') {
                continue;
            }
            $category = trim((string) ($item['category'] ?? '')) ?: 'insight';

            $out[] = [
                'title' => $title,
                'content' => $content,
                'category' => $category,
                'entities' => $this->cleanEntities($item['entities'] ?? []),
                'relations' => $this->cleanRelations($item['relations'] ?? []),
            ];
        }

        return $out;
    }

    private function extractJsonArray(string $raw): ?string
    {
        $start = strpos($raw, '[');
        $end = strrpos($raw, ']');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        return substr($raw, $start, $end - $start + 1);
    }

    /** @return list<array{name:string,type:string}> */
    private function cleanEntities(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $e) {
            $name = is_array($e) ? trim((string) ($e['name'] ?? '')) : '';
            if ($name === '') {
                continue;
            }
            $out[] = ['name' => $name, 'type' => trim((string) ($e['type'] ?? ''))];
        }

        return $out;
    }

    /** @return list<array{subject:string,predicate:string,object:string}> */
    private function cleanRelations(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $r) {
            if (! is_array($r)) {
                continue;
            }
            $s = trim((string) ($r['subject'] ?? ''));
            $p = trim((string) ($r['predicate'] ?? ''));
            $o = trim((string) ($r['object'] ?? ''));
            if ($s === '' || $p === '' || $o === '') {
                continue;
            }
            $out[] = ['subject' => $s, 'predicate' => $p, 'object' => $o];
        }

        return $out;
    }
}
```

- [ ] **Step 4: Implement `ExtractionPrompt`**

```php
<?php

namespace App\Services\Condense;

final class ExtractionPrompt
{
    public function instructions(?string $override): string
    {
        $override = $override !== null ? trim($override) : '';
        if ($override !== '') {
            return $override;
        }

        return <<<'PROMPT'
        You condense a coding-session transcript into durable project knowledge.

        Extract ONLY durable knowledge: decisions, rules, architecture notes,
        non-obvious fixes, and conventions. Ignore ephemeral chatter, transient
        debugging steps, and anything trivially derivable from reading the code.

        Output ONLY a JSON array (no prose, no code fences). Each item:
        {
          "title": "short descriptive title",
          "content": "Markdown explaining the knowledge",
          "category": "one of: decision, rule, architecture, fix, convention, insight",
          "entities": [{"name": "...", "type": "..."}],
          "relations": [{"subject": "...", "predicate": "...", "object": "..."}]
        }

        If there is nothing durable, output exactly: []
        PROMPT;
    }
}
```

- [ ] **Step 5: Run + Commit**

Run: `./vendor/bin/pest tests/Unit/Services/CandidateParserTest.php` → PASS

```bash
git add app/Services/Condense/ExtractionPrompt.php app/Services/Condense/CandidateParser.php tests/Unit/Services/CandidateParserTest.php
git commit -m "feat: add ExtractionPrompt and CandidateParser for condensation

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 8: `KnowledgeExtractor` interface + `ApiExtractor`

**Files:**
- Create: `app/Services/Condense/KnowledgeExtractor.php` (interface)
- Create: `app/Services/Condense/ApiExtractor.php`
- Test: `tests/Unit/Services/ApiExtractorTest.php`

**Interfaces:**
- Consumes: `ExtractionPrompt`, `CandidateParser` (Task 7); `Laravel\Ai\AnonymousAgent` (vendor).
- Produces:
  ```php
  interface KnowledgeExtractor { public function extract(string $transcript): array; }
  final class ApiExtractor implements KnowledgeExtractor {
      public function __construct(ExtractionPrompt $prompt, CandidateParser $parser,
          string $provider, string $model, ?string $override);
  }
  ```
  Uso do vendor: `(new AnonymousAgent($instructions, [], []))->prompt($transcript, [], $provider, $model)` → `AgentResponse` com `->text` (ver `vendor/laravel/ai/src/AnonymousAgent.php` + `Promptable::prompt`).

- [ ] **Step 1: Write the failing test** (faking o provider de texto do Laravel\Ai)

> Nota p/ o implementador: o `Laravel\Ai` resolve o provider de texto via `AiManager::textProviderFor()`. Para testar sem rede, faça bind de um fake do `TextProvider` no container **ou** teste `ApiExtractor` injetando um dublê. A abordagem mais simples e estável: extrair a chamada ao agente para um método protegido `respond()` e sobrescrevê-lo numa subclasse anônima de teste. Implementar assim:

```php
<?php
// tests/Unit/Services/ApiExtractorTest.php

use App\Services\Condense\ApiExtractor;
use App\Services\Condense\CandidateParser;
use App\Services\Condense\ExtractionPrompt;

it('maps the model text response into candidates', function () {
    $json = json_encode([[
        'title' => 'Use queue database driver',
        'content' => '# note', 'category' => 'decision',
        'entities' => [], 'relations' => [],
    ]]);

    $extractor = new class(app(ExtractionPrompt::class), app(CandidateParser::class), 'anthropic', 'claude-haiku-4-5-20251001', null) extends ApiExtractor {
        public string $captured = '';
        protected function respond(string $instructions, string $transcript): string
        {
            $this->captured = $transcript;

            return '```json'."\n".$GLOBALS['__api_json']."\n".'```';
        }
    };
    $GLOBALS['__api_json'] = $json;

    $out = $extractor->extract('USER: hi');

    expect($out)->toHaveCount(1);
    expect($out[0]['title'])->toBe('Use queue database driver');
    expect($extractor->captured)->toBe('USER: hi');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Services/ApiExtractorTest.php`
Expected: FAIL (interface/classe ausentes).

- [ ] **Step 3: Implement interface + `ApiExtractor`**

```php
<?php
// app/Services/Condense/KnowledgeExtractor.php
namespace App\Services\Condense;

interface KnowledgeExtractor
{
    /**
     * @return list<array{title:string, content:string, category:string,
     *   entities:list<array{name:string,type:string}>,
     *   relations:list<array{subject:string,predicate:string,object:string}>}>
     */
    public function extract(string $transcript): array;
}
```

```php
<?php
// app/Services/Condense/ApiExtractor.php
namespace App\Services\Condense;

use Laravel\Ai\AnonymousAgent;

class ApiExtractor implements KnowledgeExtractor
{
    public function __construct(
        private readonly ExtractionPrompt $prompt,
        private readonly CandidateParser $parser,
        private readonly string $provider,
        private readonly string $model,
        private readonly ?string $override,
    ) {}

    public function extract(string $transcript): array
    {
        $instructions = $this->prompt->instructions($this->override);
        $text = $this->respond($instructions, $transcript);

        return $this->parser->parse($text);
    }

    /** Seam for testing; issues the real LLM call. */
    protected function respond(string $instructions, string $transcript): string
    {
        $agent = new AnonymousAgent($instructions, [], []);

        return $agent->prompt($transcript, [], $this->provider, $this->model)->text;
    }
}
```

- [ ] **Step 4: Run + Commit**

Run: `./vendor/bin/pest tests/Unit/Services/ApiExtractorTest.php` → PASS

```bash
git add app/Services/Condense/KnowledgeExtractor.php app/Services/Condense/ApiExtractor.php tests/Unit/Services/ApiExtractorTest.php
git commit -m "feat: add KnowledgeExtractor interface and ApiExtractor (Laravel\\Ai)

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 9: `ClaudeSdkExtractor` (spawn `claude -p`)

Analogia PHP do Agent SDK: roda o binário `claude` em modo print reaproveitando a assinatura/login do host. Se o binário não existe, loga aviso e retorna `[]` (nunca lança).

**Files:**
- Create: `app/Services/Condense/ClaudeSdkExtractor.php`
- Test: `tests/Unit/Services/ClaudeSdkExtractorTest.php`

**Interfaces:**
- Consumes: `ExtractionPrompt`, `CandidateParser`; `Symfony\Component\Process\{Process,ExecutableFinder}`.
- Produces:
  ```php
  final class ClaudeSdkExtractor implements KnowledgeExtractor {
      public function __construct(ExtractionPrompt $prompt, CandidateParser $parser,
          string $model, ?string $override, string $binary = 'claude');
  }
  ```
  Comando: `claude -p <full-prompt> --output-format json --model <model>`; a saída é um JSON com campo `result` (o texto do modelo).

- [ ] **Step 1: Write the failing test** (caminho binário-ausente, determinístico)

```php
<?php
// tests/Unit/Services/ClaudeSdkExtractorTest.php

use App\Services\Condense\CandidateParser;
use App\Services\Condense\ClaudeSdkExtractor;
use App\Services\Condense\ExtractionPrompt;
use Illuminate\Support\Facades\Log;

it('returns empty and logs when the claude binary is missing', function () {
    Log::spy();

    $extractor = new ClaudeSdkExtractor(
        app(ExtractionPrompt::class), app(CandidateParser::class),
        'claude-haiku-4-5-20251001', null,
        binary: 'claude-binary-that-does-not-exist-xyz',
    );

    expect($extractor->extract('USER: hi'))->toBe([]);
    Log::shouldHaveReceived('warning')->once();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Services/ClaudeSdkExtractorTest.php`
Expected: FAIL (classe ausente).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Condense;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class ClaudeSdkExtractor implements KnowledgeExtractor
{
    public function __construct(
        private readonly ExtractionPrompt $prompt,
        private readonly CandidateParser $parser,
        private readonly string $model,
        private readonly ?string $override,
        private readonly string $binary = 'claude',
    ) {}

    public function extract(string $transcript): array
    {
        $bin = (new ExecutableFinder)->find($this->binary);
        if ($bin === null) {
            Log::warning('ClaudeSdkExtractor: claude binary not found; skipping condense', [
                'binary' => $this->binary,
            ]);

            return [];
        }

        $fullPrompt = $this->prompt->instructions($this->override)
            ."\n\n---TRANSCRIPT---\n".$transcript;

        $process = new Process([$bin, '-p', $fullPrompt, '--output-format', 'json', '--model', $this->model]);
        $process->setTimeout(180);

        try {
            $process->run();
        } catch (Throwable $e) {
            Log::warning('ClaudeSdkExtractor: process failed', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $process->isSuccessful()) {
            Log::warning('ClaudeSdkExtractor: non-zero exit', [
                'exit' => $process->getExitCode(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 500),
            ]);

            return [];
        }

        $decoded = json_decode($process->getOutput(), true);
        $text = is_array($decoded) ? (string) ($decoded['result'] ?? '') : '';

        return $this->parser->parse($text);
    }
}
```

- [ ] **Step 4: Run + Commit**

Run: `./vendor/bin/pest tests/Unit/Services/ClaudeSdkExtractorTest.php` → PASS

```bash
git add app/Services/Condense/ClaudeSdkExtractor.php tests/Unit/Services/ClaudeSdkExtractorTest.php
git commit -m "feat: add ClaudeSdkExtractor (subscription via claude -p)

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 10: `KnowledgeExtractorFactory`

Instancia o driver certo a partir de `CondenseSetting`.

**Files:**
- Create: `app/Services/Condense/KnowledgeExtractorFactory.php`
- Test: `tests/Unit/Services/KnowledgeExtractorFactoryTest.php`

**Interfaces:**
- Consumes: `ExtractionPrompt`, `CandidateParser`, `App\Enums\ExtractorDriver`, `App\Models\CondenseSetting`.
- Produces: `make(CondenseSetting $setting): KnowledgeExtractor`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Services/KnowledgeExtractorFactoryTest.php

use App\Models\CondenseSetting;
use App\Services\Condense\ApiExtractor;
use App\Services\Condense\ClaudeSdkExtractor;
use App\Services\Condense\KnowledgeExtractorFactory;

it('builds a ClaudeSdkExtractor for driver claude_sdk', function () {
    $s = new CondenseSetting(['driver' => 'claude_sdk', 'model' => 'claude-haiku-4-5-20251001']);
    expect(app(KnowledgeExtractorFactory::class)->make($s))->toBeInstanceOf(ClaudeSdkExtractor::class);
});

it('builds an ApiExtractor for driver api', function () {
    $s = new CondenseSetting(['driver' => 'api', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001']);
    expect(app(KnowledgeExtractorFactory::class)->make($s))->toBeInstanceOf(ApiExtractor::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Services/KnowledgeExtractorFactoryTest.php`
Expected: FAIL (classe ausente).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Condense;

use App\Enums\ExtractorDriver;
use App\Models\CondenseSetting;

final class KnowledgeExtractorFactory
{
    public function __construct(
        private readonly ExtractionPrompt $prompt,
        private readonly CandidateParser $parser,
    ) {}

    public function make(CondenseSetting $setting): KnowledgeExtractor
    {
        return match (ExtractorDriver::from($setting->driver)) {
            ExtractorDriver::Api => new ApiExtractor(
                $this->prompt, $this->parser,
                $setting->provider ?: 'anthropic', $setting->model, $setting->system_prompt_override,
            ),
            ExtractorDriver::ClaudeSdk => new ClaudeSdkExtractor(
                $this->prompt, $this->parser, $setting->model, $setting->system_prompt_override,
            ),
        };
    }
}
```

- [ ] **Step 4: Run + Commit**

Run: `./vendor/bin/pest tests/Unit/Services/KnowledgeExtractorFactoryTest.php` → PASS

```bash
git add app/Services/Condense/KnowledgeExtractorFactory.php tests/Unit/Services/KnowledgeExtractorFactoryTest.php
git commit -m "feat: add KnowledgeExtractorFactory

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 11: `CondenseDedup` (similaridade vetorial approved+pending)

**Files:**
- Create: `app/Services/Condense/CondenseDedup.php`
- Test: `tests/Unit/Services/CondenseDedupTest.php`

**Interfaces:**
- Consumes: `chunk_embeddings` (colunas `entry_id`, `project_id`, `embedding vector(768)`); `Laravel\Ai\Embeddings`.
- Produces: `isDuplicate(string $projectId, string $title, string $content, float $threshold): bool`. O método `embed()` é protegido (seam de teste).

- [ ] **Step 1: Write the failing test** (sobrescrevendo `embed()`, inserindo um chunk cru)

```php
<?php
// tests/Unit/Services/CondenseDedupTest.php

use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Condense\CondenseDedup;
use Illuminate\Support\Facades\DB;

function insertChunk(string $entryId, string $projectId, array $vec): void
{
    // chunk_embeddings columns: entry_id(bigint), project_id, chunk_index,
    // content, embedding(vector 768), created_at (defaults to now()).
    DB::table('chunk_embeddings')->insert([
        'entry_id' => $entryId,
        'project_id' => $projectId,
        'chunk_index' => 0,
        'content' => 'x',
        'embedding' => '['.implode(',', $vec).']',
    ]);
}

function dedupWith(array $queryVec): CondenseDedup
{
    return new class($queryVec) extends CondenseDedup {
        public function __construct(private array $qv) {}
        protected function embed(string $text): array { return $this->qv; }
    };
}

beforeEach(function () {
    Project::create(['id' => 'p1', 'name' => 'p1', 'root_path' => '/tmp/p1']);
});

it('flags a near-identical vector as duplicate', function () {
    $vec = array_fill(0, 768, 0.0); $vec[0] = 1.0;
    $entry = KnowledgeEntry::create(['project_id' => 'p1', 'title' => 't', 'content' => 'c', 'category' => 'insight', 'source' => 'manual', 'status' => 'pending']);
    insertChunk((string) $entry->id, 'p1', $vec);

    expect(dedupWith($vec)->isDuplicate('p1', 'title', 'content', 0.85))->toBeTrue();
});

it('does not flag an orthogonal vector', function () {
    $stored = array_fill(0, 768, 0.0); $stored[0] = 1.0;
    $query = array_fill(0, 768, 0.0); $query[1] = 1.0;
    $entry = KnowledgeEntry::create(['project_id' => 'p1', 'title' => 't', 'content' => 'c', 'category' => 'insight', 'source' => 'manual', 'status' => 'pending']);
    insertChunk((string) $entry->id, 'p1', $stored);

    expect(dedupWith($query)->isDuplicate('p1', 'title', 'content', 0.85))->toBeFalse();
});

it('returns false when the project has no chunks', function () {
    $vec = array_fill(0, 768, 0.0); $vec[0] = 1.0;
    expect(dedupWith($vec)->isDuplicate('p1', 'title', 'content', 0.85))->toBeFalse();
});
```

> Nota: o `embedding` é `vector(768)` `NOT NULL`; por isso os vetores de teste têm 768 posições. `entry_id` é `bigInteger` com FK para `knowledge_entries`, então crie a entrada antes de inserir o chunk.

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Services/CondenseDedupTest.php`
Expected: FAIL (classe ausente).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Condense;

use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

class CondenseDedup
{
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

    /** Seam for testing; generates the query embedding. */
    protected function embed(string $text): array
    {
        return Embeddings::for([$text])->generate('local-embedder')->embeddings[0];
    }
}
```

- [ ] **Step 4: Run + Commit**

Run: `./vendor/bin/pest tests/Unit/Services/CondenseDedupTest.php` → PASS

```bash
git add app/Services/Condense/CondenseDedup.php tests/Unit/Services/CondenseDedupTest.php
git commit -m "feat: add CondenseDedup (vector similarity over approved+pending)

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 12: `CondenseSessionJob` (orquestração)

**Files:**
- Create: `app/Jobs/CondenseSessionJob.php`
- Test: `tests/Feature/Jobs/CondenseSessionJobTest.php`

**Interfaces:**
- Consumes: `TranscriptParser`, `KnowledgeExtractorFactory`, `CondenseDedup`, `KnowledgeWriter`, `CondenseSetting`, `CondenseRun`.
- Produces:
  ```php
  final class CondenseSessionJob implements ShouldQueue {
      public int $tries = 1;
      public function __construct(string $projectId, string $transcriptPath, string $sessionId);
      public function handle(TranscriptParser, KnowledgeExtractorFactory, CondenseDedup, KnowledgeWriter): void;
  }
  ```
- Comportamento:
  1. `CondenseSetting::current()`; se `!enabled` → return.
  2. Idempotência: tenta `CondenseRun::create([session_id, project_id, status=running])`; se `QueryException` (unique) → return.
  3. Se transcript não legível → run `failed`; return.
  4. `parse` → se vazio → run `skipped`; return.
  5. `factory->make($setting)->extract($text)` (try/catch → `failed`).
  6. Para cada candidato: se `dedup->isDuplicate` → pula; senão `writer->store(..., 'condense', ...)`; conta.
  7. Atualiza a run: `done` se criou ≥1, senão `skipped`; `entries_created`.

- [ ] **Step 1: Write the failing test** (fakes injetados via container)

```php
<?php
// tests/Feature/Jobs/CondenseSessionJobTest.php

use App\Jobs\CondenseSessionJob;
use App\Models\CondenseRun;
use App\Models\CondenseSetting;
use App\Models\KnowledgeEntry;
use App\Models\Project;
use App\Services\Condense\CondenseDedup;
use App\Services\Condense\KnowledgeExtractor;
use App\Services\Condense\KnowledgeExtractorFactory;
use App\Services\Condense\TranscriptParser;

beforeEach(function () {
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
                        'title' => 'Use database queue', 'content' => '# c', 'category' => 'decision',
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
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Jobs/CondenseSessionJobTest.php`
Expected: FAIL (job ausente).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Jobs;

use App\Models\CondenseRun;
use App\Models\CondenseSetting;
use App\Services\Condense\CondenseDedup;
use App\Services\Condense\KnowledgeExtractorFactory;
use App\Services\Condense\TranscriptParser;
use App\Services\Knowledge\KnowledgeWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CondenseSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $projectId,
        public readonly string $transcriptPath,
        public readonly string $sessionId,
    ) {}

    public function handle(
        TranscriptParser $parser,
        KnowledgeExtractorFactory $factory,
        CondenseDedup $dedup,
        KnowledgeWriter $writer,
    ): void {
        $setting = CondenseSetting::current();
        if (! $setting->enabled) {
            return;
        }

        // Idempotency guard: the unique session_id makes a second job no-op.
        try {
            $run = CondenseRun::create([
                'session_id' => $this->sessionId,
                'project_id' => $this->projectId,
                'status' => 'running',
            ]);
        } catch (QueryException) {
            return;
        }

        if (! is_readable($this->transcriptPath)) {
            $run->update(['status' => 'failed']);
            Log::warning('CondenseSessionJob: transcript not readable', ['path' => $this->transcriptPath]);

            return;
        }

        $text = $parser->parse($this->transcriptPath, $setting->max_transcript_chars);
        if (trim($text) === '') {
            $run->update(['status' => 'skipped']);

            return;
        }

        try {
            $candidates = $factory->make($setting)->extract($text);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed']);
            Log::warning('CondenseSessionJob: extraction failed', ['error' => $e->getMessage()]);

            return;
        }

        $created = 0;
        foreach ($candidates as $c) {
            if ($dedup->isDuplicate($this->projectId, $c['title'], $c['content'], $setting->min_dedup_score)) {
                continue;
            }
            $writer->store(
                $this->projectId, $c['title'], $c['content'], $c['category'],
                'condense', [], $c['entities'], $c['relations'],
            );
            $created++;
        }

        $run->update([
            'status' => $created > 0 ? 'done' : 'skipped',
            'entries_created' => $created,
        ]);
    }
}
```

- [ ] **Step 4: Run + Commit**

Run: `./vendor/bin/pest tests/Feature/Jobs/CondenseSessionJobTest.php` → PASS

```bash
git add app/Jobs/CondenseSessionJob.php tests/Feature/Jobs/CondenseSessionJobTest.php
git commit -m "feat: add CondenseSessionJob orchestrating out-of-band condensation

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 13: `HookController::condense` + rota

**Files:**
- Modify: `app/Http/Controllers/HookController.php` (novo método `condense`)
- Modify: `routes/hooks.php` (nova rota)
- Test: `tests/Feature/Hooks/CondenseEndpointTest.php`

**Interfaces:**
- Consumes: `ResolvesProjectId` (já usado no controller), `CondenseSessionJob`.
- Produces: `POST /hooks/condense` `{cwd, session_id, transcript_path}` → `202`, despacha `CondenseSessionJob`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Hooks/CondenseEndpointTest.php

use App\Jobs\CondenseSessionJob;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;

it('dispatches a condense job and returns 202', function () {
    Queue::fake();

    $res = $this->postJson('/hooks/condense', [
        'cwd' => '/tmp/acme-app',
        'session_id' => 'sess-9',
        'transcript_path' => '/tmp/acme-app/transcript.jsonl',
    ]);

    $res->assertStatus(202);
    expect(Project::where('id', 'acme-app')->exists())->toBeTrue();
    Queue::assertPushed(CondenseSessionJob::class, fn ($job) => $job->sessionId === 'sess-9'
        && $job->projectId === 'acme-app'
        && $job->transcriptPath === '/tmp/acme-app/transcript.jsonl');
});

it('returns 202 without dispatching when required fields are missing', function () {
    Queue::fake();

    $this->postJson('/hooks/condense', ['cwd' => '/tmp/acme-app'])->assertStatus(202);

    Queue::assertNothingPushed();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Hooks/CondenseEndpointTest.php`
Expected: FAIL (rota inexistente → 404).

- [ ] **Step 3: Implement controller method**

Adicionar em `app/Http/Controllers/HookController.php` (importar `use App\Exceptions\ProjectNotIdentifiedException;` e `use App\Jobs\CondenseSessionJob;`):

```php
public function condense(Request $request): Response
{
    $cwd = (string) $request->input('cwd', '');
    $sessionId = (string) $request->input('session_id', '');
    $transcriptPath = (string) $request->input('transcript_path', '');

    if ($sessionId === '' || $transcriptPath === '') {
        return response('', 202);
    }

    try {
        $pid = $this->ensureProject(null, $cwd !== '' ? $cwd : null);
    } catch (ProjectNotIdentifiedException) {
        return response('', 202);
    }

    CondenseSessionJob::dispatch($pid, $transcriptPath, $sessionId);

    return response('', 202);
}
```

- [ ] **Step 4: Add the route**

Em `routes/hooks.php`, dentro do grupo:

```php
Route::post('condense', [HookController::class, 'condense']);
```

- [ ] **Step 5: Run + Commit**

Run: `./vendor/bin/pest tests/Feature/Hooks/CondenseEndpointTest.php` → PASS

```bash
git add app/Http/Controllers/HookController.php routes/hooks.php tests/Feature/Hooks/CondenseEndpointTest.php
git commit -m "feat: add POST /hooks/condense fire-and-forget endpoint

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 14: `CondenseSettingResource` (config no Martis) + i18n

Resource singleton: edita a linha única, sem criar nem deletar. Provider só aparece no driver `api`.

**Files:**
- Create: `app/Martis/Resources/CondenseSettingResource.php`
- Create/Modify: `lang/en/condense.php`, `lang/pt_PT/condense.php`, `lang/pt_BR/condense.php`
- Test: `tests/Feature/Martis/CondenseSettingResourceTest.php`

**Interfaces:**
- Consumes: `App\Models\CondenseSetting`, `App\Enums\{ExtractorDriver,ExtractorProvider}`, `Martis\Fields\*` (ver `app/Martis/Resources/ProjectResource.php` para o padrão).
- Produces: um resource Martis com `authorizedToCreate`/`authorizedToDelete` → `false`.

> Antes de codar, confirmar os campos disponíveis via MCP dos docs Martis: `martis_doc_read('fields')` (Boolean, Select, Number, Text, Textarea) e `martis_doc_read('authorization')` (assinatura `authorizedTo*` recebe só `Request`). NÃO ler `vendor/martis/**/docs` direto.

- [ ] **Step 1: Write the failing test** (nível de resource: autorização e model)

```php
<?php
// tests/Feature/Martis/CondenseSettingResourceTest.php

use App\Martis\Resources\CondenseSettingResource;
use App\Models\CondenseSetting;
use Illuminate\Http\Request;

it('targets the CondenseSetting model and is a singleton (no create/delete)', function () {
    expect(CondenseSettingResource::model())->toBe(CondenseSetting::class);

    $resource = new CondenseSettingResource(new CondenseSetting);
    $req = Request::create('/martis/resources/condense-settings', 'GET');

    expect($resource->authorizedToCreate($req))->toBeFalse();
    expect($resource->authorizedToDelete($req))->toBeFalse();
});

it('exposes the settings fields', function () {
    $resource = new CondenseSettingResource(new CondenseSetting);
    $names = collect($resource->fields(Request::create('/', 'GET')))
        ->map(fn ($f) => $f->attribute ?? null)->filter()->all();

    expect($names)->toContain('enabled', 'driver', 'provider', 'model', 'min_dedup_score', 'max_transcript_chars');
});
```

> Nota: se o construtor de `Martis\Resource` exigir outra assinatura, ajustar a instanciação conforme `ProjectResource`. O acesso `->attribute` pode variar entre versões de field; se necessário, asserir via reflection do array retornado.

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Martis/CondenseSettingResourceTest.php`
Expected: FAIL (resource ausente).

- [ ] **Step 3: Implement the resource**

```php
<?php

namespace App\Martis\Resources;

use App\Enums\ExtractorDriver;
use App\Enums\ExtractorProvider;
use App\Models\CondenseSetting;
use Illuminate\Http\Request;
use Martis\Fields\Boolean;
use Martis\Fields\Id;
use Martis\Fields\Number;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Resource;

class CondenseSettingResource extends Resource
{
    public static function model(): string
    {
        return CondenseSetting::class;
    }

    public function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    public function fields(Request $request): array
    {
        return [
            Id::make('id'),

            Boolean::make('enabled', __('condense.fields.enabled'))
                ->help(__('condense.fields.enabled_help')),

            Select::make('driver', __('condense.fields.driver'))
                ->options(ExtractorDriver::options())
                ->rules(['required', 'in:'.implode(',', array_keys(ExtractorDriver::options()))])
                ->help(__('condense.fields.driver_help')),

            Select::make('provider', __('condense.fields.provider'))
                ->options(ExtractorProvider::options())
                ->rules(['nullable', 'in:'.implode(',', array_keys(ExtractorProvider::options()))])
                ->help(__('condense.fields.provider_help')),

            Text::make('model', __('condense.fields.model'))
                ->rules(['required', 'string', 'max:255'])
                ->help(__('condense.fields.model_help')),

            Number::make('min_dedup_score', __('condense.fields.min_dedup_score'))
                ->rules(['required', 'numeric', 'between:0,1'])
                ->help(__('condense.fields.min_dedup_score_help')),

            Number::make('max_transcript_chars', __('condense.fields.max_transcript_chars'))
                ->rules(['required', 'integer', 'min:1000'])
                ->help(__('condense.fields.max_transcript_chars_help')),

            Textarea::make('system_prompt_override', __('condense.fields.system_prompt_override'))
                ->rules(['nullable', 'string'])
                ->help(__('condense.fields.system_prompt_override_help')),
        ];
    }
}
```

- [ ] **Step 4: Lang files**

`lang/en/condense.php`:

```php
<?php

return [
    'fields' => [
        'enabled' => 'Enabled',
        'enabled_help' => 'Extract knowledge from sessions on Stop.',
        'driver' => 'Extractor driver',
        'driver_help' => 'Claude SDK reuses your Claude subscription; API uses a provider key.',
        'provider' => 'API provider',
        'provider_help' => 'Only used when the driver is "API provider".',
        'model' => 'Model',
        'model_help' => 'e.g. claude-haiku-4-5-20251001',
        'min_dedup_score' => 'Dedup threshold',
        'min_dedup_score_help' => 'Skip a candidate when a stored entry is at least this similar (0-1).',
        'max_transcript_chars' => 'Max transcript characters',
        'max_transcript_chars_help' => 'Longer transcripts are truncated to the most recent part.',
        'system_prompt_override' => 'System prompt override',
        'system_prompt_override_help' => 'Optional: replace the default extraction instructions.',
    ],
];
```

`lang/pt_PT/condense.php` e `lang/pt_BR/condense.php` — mesmas chaves, traduzidas (pt_PT e pt_BR podem ser idênticos aqui):

```php
<?php

return [
    'fields' => [
        'enabled' => 'Ativado',
        'enabled_help' => 'Extrair conhecimento das sessões ao terminar (Stop).',
        'driver' => 'Driver de extração',
        'driver_help' => 'Claude SDK reaproveita a tua subscrição Claude; API usa a chave de um provider.',
        'provider' => 'Provider de API',
        'provider_help' => 'Usado apenas quando o driver é "Provider de API".',
        'model' => 'Modelo',
        'model_help' => 'ex.: claude-haiku-4-5-20251001',
        'min_dedup_score' => 'Limiar de deduplicação',
        'min_dedup_score_help' => 'Ignora um candidato quando uma entrada guardada é pelo menos assim tão parecida (0-1).',
        'max_transcript_chars' => 'Máx. de caracteres do transcript',
        'max_transcript_chars_help' => 'Transcripts longos são truncados para a parte mais recente.',
        'system_prompt_override' => 'Override do system prompt',
        'system_prompt_override_help' => 'Opcional: substitui as instruções de extração por defeito.',
    ],
];
```

(No `pt_BR`, ajustar para pt-BR: "à tua"→"à sua", "por defeito"→"padrão", etc.)

- [ ] **Step 5: Run + Commit**

Run: `./vendor/bin/pest tests/Feature/Martis/CondenseSettingResourceTest.php` → PASS

```bash
git add app/Martis/Resources/CondenseSettingResource.php lang/en/condense.php lang/pt_PT/condense.php lang/pt_BR/condense.php tests/Feature/Martis/CondenseSettingResourceTest.php
git commit -m "feat: add Martis CondenseSettingResource + i18n

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 15: Reescrever os hooks-cliente (fire-and-forget) + testes de stub

Converter o Stop hook de in-band para fire-and-forget em todos os harnesses e trocar `rag_condense_instruction` por `rag_condense_post` no core compartilhado. Atualizar as cópias vivas em `.claude/hooks/` e os testes de stub.

**Files:**
- Modify: `stubs/client/hooks/lib/rag-core.sh`
- Modify: `stubs/client/claude/hooks/stop.sh`, `stubs/client/codex/hooks/stop.sh`, `stubs/client/cursor/hooks/stop.sh`
- Modify (cópias vivas): `.claude/hooks/lib/rag-core.sh`, `.claude/hooks/stop.sh`
- Modify: `tests/Feature/Stubs/ClaudeStubsTest.php`, `tests/Feature/Stubs/CodexStubsTest.php`, `tests/Feature/Stubs/CursorStubsTest.php`

**Interfaces:**
- Produces: `rag_condense_post <cwd> <session_id> <transcript_path>` posta em `/hooks/condense`. `rag_condense_instruction` deixa de existir.

- [ ] **Step 1: Update `rag-core.sh` (shared stub)**

Em `stubs/client/hooks/lib/rag-core.sh`, **remover** a função `rag_condense_instruction` inteira e **adicionar**:

```sh
# Fire-and-forget: ask the worker to condense a finished session.
rag_condense_post() {
  _cwd=$(_rag_json_escape "$1")
  _sid=$(_rag_json_escape "$2")
  _tp=$(_rag_json_escape "$3")
  _rag_post "condense" "{\"cwd\": ${_cwd}, \"session_id\": ${_sid}, \"transcript_path\": ${_tp}}"
}
```

- [ ] **Step 2: Rewrite `stubs/client/claude/hooks/stop.sh`**

```sh
#!/usr/bin/env sh
# Claude Code Stop: fire-and-forget condense request to the RAG worker.
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

[ "$RAG_HOOK_CONDENSE" != "true" ] && exit 0

INPUT=$(cat)
CWD=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("cwd",""))' 2>/dev/null)
SID=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("session_id",""))' 2>/dev/null)
TP=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("transcript_path",""))' 2>/dev/null)

# Nothing to condense without a transcript.
[ -z "$TP" ] && exit 0
[ -z "$CWD" ] && CWD="${CLAUDE_PROJECT_DIR:-$(pwd)}"

# Detach so the session is never blocked (fire-and-forget).
rag_condense_post "$CWD" "$SID" "$TP" >/dev/null 2>&1 &
exit 0
```

- [ ] **Step 3: Rewrite `stubs/client/codex/hooks/stop.sh`** (mesmo envelope do Claude)

Copiar exatamente o conteúdo do Step 2, trocando apenas a linha de comentário do topo para:

```sh
# Codex Stop: fire-and-forget condense request to the RAG worker.
```

- [ ] **Step 4: Rewrite `stubs/client/cursor/hooks/stop.sh`** (Cursor exige imprimir `{}`)

```sh
#!/usr/bin/env sh
# Cursor stop: fire-and-forget condense request to the RAG worker.
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RAG_HOOK_DIR="$DIR"
. "$DIR/lib/rag-core.sh" 2>/dev/null
rag_load_config

if [ "$RAG_HOOK_CONDENSE" != "true" ]; then echo '{}'; exit 0; fi

INPUT=$(cat)
CWD=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("cwd",""))' 2>/dev/null)
SID=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("session_id",""))' 2>/dev/null)
TP=$(printf '%s' "$INPUT" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("transcript_path",""))' 2>/dev/null)

if [ -n "$TP" ]; then
  [ -z "$CWD" ] && CWD="${CLAUDE_PROJECT_DIR:-$(pwd)}"
  rag_condense_post "$CWD" "$SID" "$TP" >/dev/null 2>&1 &
fi
echo '{}'
exit 0
```

- [ ] **Step 5: Sync the live copies**

```bash
cp stubs/client/hooks/lib/rag-core.sh .claude/hooks/lib/rag-core.sh
cp stubs/client/claude/hooks/stop.sh .claude/hooks/stop.sh
chmod 0755 .claude/hooks/stop.sh .claude/hooks/lib/rag-core.sh
```

- [ ] **Step 6: Update the stub tests**

Rodar os testes de stub para ver as asserções atuais:

Run: `./vendor/bin/pest tests/Feature/Stubs/ClaudeStubsTest.php tests/Feature/Stubs/CodexStubsTest.php tests/Feature/Stubs/CursorStubsTest.php`

Ajustar as asserções para o novo comportamento. Onde um teste hoje verifica a presença de `decision":"block"` / `rag_condense_instruction` / `followup_message`, trocar para verificar o novo contrato. Asserções alvo (adaptar à sintaxe usada no arquivo):

- claude/codex `stop.sh`: **contém** `rag_condense_post` e `transcript_path`; **não contém** `rag_condense_instruction` nem `decision":"block`.
- cursor `stop.sh`: **contém** `rag_condense_post`; **ainda imprime** `echo '{}'`; **não contém** `followup_message`.
- `rag-core.sh` (se houver teste): **contém** `rag_condense_post`; **não contém** `rag_condense_instruction`.

- [ ] **Step 7: Run the full stub + hooks suite**

Run: `./vendor/bin/pest tests/Feature/Stubs tests/Feature/Hooks`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add stubs/client/hooks/lib/rag-core.sh stubs/client/claude/hooks/stop.sh stubs/client/codex/hooks/stop.sh stubs/client/cursor/hooks/stop.sh .claude/hooks/lib/rag-core.sh .claude/hooks/stop.sh tests/Feature/Stubs
git commit -m "feat: rewrite Stop hooks to fire-and-forget condense POST

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

### Task 16: Verificação end-to-end + suíte completa

**Files:** nenhum novo (validação).

- [ ] **Step 1: Run the whole test suite**

Run: `php artisan test`
Expected: PASS (sem regressões).

- [ ] **Step 2: Manual smoke (driver claude_sdk)**

Com o worker de pé (`php artisan serve`/octane em :8080) e `claude` autenticado no host:

```bash
# Simula o POST do Stop hook com um transcript real de sessão
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/hooks/condense \
  -H 'Content-Type: application/json' \
  -d "{\"cwd\":\"$(pwd)\",\"session_id\":\"smoke-1\",\"transcript_path\":\"<caminho-de-um-transcript.jsonl>\"}"
```

Esperado: `202`. Depois processar a fila e conferir:

```bash
php artisan queue:work --once
```

Verificar em `condense_runs` (status `done`/`skipped`) e no Martis (`/martis/resources/knowledge-entries`) que entradas `pending` novas apareceram (ou que nada duplicou).

- [ ] **Step 3: Confirmar que o Stop hook não bloqueia mais**

Encerrar uma sessão do Claude Code neste projeto e confirmar que **não** aparece mais o `"Ran 1 stop hook"` / instrução de condensação — apenas o POST silencioso.

- [ ] **Step 4: Final commit (se algo foi ajustado)**

```bash
git add -A
git commit -m "chore: end-to-end verification for out-of-band condensation

Claude-Session: https://claude.ai/code/session_01VwTUSUv6UXDKht9pDBFVL4"
```

---

## Notas de escopo e riscos

- **Worker containerizado:** o driver `claude_sdk` exige `claude` + auth no host do worker; sem isso, `ClaudeSdkExtractor` loga e retorna `[]`. Para deploy remoto, usar driver `api`. O `transcript_path` também precisa ser legível pelo worker (Task 12 marca `failed` se não for).
- **Indexar pending** muda o comportamento global do observer (Task 2): todas as entradas pending passam a ter embedding. A busca continua filtrando `approved` (`hydrate`/`ftsSearch`), então não há vazamento — mas revisar se algum outro consumidor lê `chunk_embeddings` sem filtrar status.
- **`opencode`:** este plano cobre claude/codex/cursor. O harness opencode usa um hook `chat.message` diferente (ver git log) e fica fora deste escopo; se necessário, tratar em follow-up.
- **Laravel\Ai text API:** confirmar na implementação da Task 8 que `AnonymousAgent->prompt(...)->text` é o caminho correto na versão instalada; o método `respond()` isola essa dependência para teste.
