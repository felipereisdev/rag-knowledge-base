# Design — Condensação de conhecimento out-of-band (padrão claude-mem)

**Data:** 2026-07-10
**Status:** Aprovado (aguardando revisão do spec escrito)

## 1. Objetivo

Substituir o mecanismo atual de condensação **in-band** (o Stop hook injeta uma
instrução `decision:block` que faz o Claude da própria sessão chamar
`rag_store_knowledge`) por um mecanismo **out-of-band**, no espírito do
[claude-mem](https://github.com/thedotmack/claude-mem):

- O Stop hook vira **fire-and-forget**: só faz um POST curto e sai. Nenhuma
  instrução visível, nenhum `"Ran 1 stop hook"`, sessão nunca é bloqueada.
- A extração de conhecimento acontece **no worker**, de forma assíncrona: um job
  lê o transcript da sessão, chama um LLM para extrair conhecimento durável e
  grava as entradas em `pending` para revisão no Martis.

### Não-objetivos (YAGNI)

- **Sem captura por `PostToolUse`.** A captura é só no `Stop` (uma extração por
  sessão). Observações por ferramenta em streaming gerariam ruído na fila de
  revisão e muitas chamadas de LLM. Decisão explícita.
- **Sem manter o modo in-band como fallback.** O nudge in-band é removido por
  completo.

## 2. Contexto atual (o que já existe)

- Worker HTTP em `http://localhost:8080` com rotas `/hooks/{ensure-project,digest,search}`
  (`app/Http/Controllers/HookController.php`, `routes/hooks.php`), protegidas por
  `VerifyHookToken`.
- Hooks-cliente em `.claude/hooks/` são shell scripts finos, "burros": toda a
  lógica vive no servidor. Gerados/bakeados por `php artisan rag:install`
  (`RagInstallCommand` / `ClientInstaller`).
  - `stop.sh` — hoje monta `rag_condense_instruction` e devolve
    `{"decision":"block","reason": ...}` (in-band). **Será reescrito.**
  - `session-start.sh`, `user-prompt.sh` — injetam digest/hits (inalterados).
  - `lib/rag-core.sh` — core compartilhado (`_rag_post`, `rag_search`, etc.).
  - `config.sh` — flags bakeadas; `RAG_HOOK_CONDENSE` liga/desliga o hook.
- Fila `database` já configurada (`config/queue.php`), com precedente
  `app/Jobs/IndexEntryJob.php`.
- `Laravel\Ai` já instalado; `config/ai.php` tem provider **anthropic** (e openai,
  gemini, openrouter, etc.). Hoje só usado para *embeddings*
  (`EntryIndexer`, `HybridSearcher`), mas suporta geração de texto.
- `KnowledgeEntry` tem `status` (`pending`/`approved`); `KnowledgeEntryObserver`
  dispara indexação. `HybridSearcher` já faz busca semântica (base do dedup).
- Martis é o admin engine; convenções em `CLAUDE.md` (enums → `Select::options()`,
  i18n obrigatório, generators, etc.).

## 3. Arquitetura

### 3.1 Fluxo end-to-end

```
Sessão termina
 └─ Claude Code roda Stop hook → stop.sh
      └─ lê JSON do stdin (transcript_path, session_id, cwd)
      └─ POST fire-and-forget /hooks/condense  (--max-time 3s, exit 0)
            └─ HookController::condense()
                 → resolve project_id (ResolvesProjectId, via cwd)
                 → dispatch CondenseSessionJob(projectId, transcriptPath, sessionId)
                 → responde 202 imediatamente
                      └─ [worker] CondenseSessionJob
                            1. carrega CondenseSetting; se !enabled → return
                            2. session_id já em condense_runs? → return (idempotência)
                            3. TranscriptParser lê o JSONL (só user/assistant, corta tool noise)
                            4. trunca p/ max_transcript_chars
                            5. KnowledgeExtractor.extract() → array de candidatos
                            6. dedup via HybridSearcher (≥ min_dedup_score → skip)
                            7. cria KnowledgeEntry status=pending (+entities/relations/tags)
                                 └─ Observer → IndexEntryJob (embeddings)
                            8. registra condense_runs(session_id, contadores)
      Você revê os pending no Martis (fluxo de aprovação existente)
```

### 3.2 Componentes

**Cliente (shell hooks)**

- `stop.sh` — reescrito para fire-and-forget. Lê `transcript_path`, `session_id`,
  `cwd` do JSON do stdin e chama `rag_condense_post`. Sempre `exit 0`, nunca
  `decision:block`. O loop-guard `stop_hook_active` deixa de ser necessário
  (idempotência migra pro servidor).
- `lib/rag-core.sh` — nova função `rag_condense_post` (POST para
  `/hooks/condense` com `{cwd, session_id, transcript_path}`). Remove
  `rag_condense_instruction`.
- `config.sh` + `RagInstallCommand`/`ClientInstaller` — atualizar o template
  bakeado do `stop.sh`. `RAG_HOOK_CONDENSE` permanece como liga/desliga do hook
  no cliente; modelo/driver/provider passam a viver no servidor (config Martis).

**Servidor**

- `routes/hooks.php` — `Route::post('condense', [HookController::class, 'condense'])`.
- `HookController::condense(Request)` — resolve `project_id` a partir do `cwd`
  (trait `ResolvesProjectId`), despacha `CondenseSessionJob`, responde 202
  (fire-and-forget; não espera o LLM).
- `App\Jobs\CondenseSessionJob` — orquestra os passos 1–8. Injeta
  `TranscriptParser`, resolve o `KnowledgeExtractor` conforme `CondenseSetting`,
  usa `HybridSearcher` p/ dedup. Falhas logadas, nunca quebram a sessão.
- `App\Services\Condense\TranscriptParser` — lê o JSONL do transcript, filtra
  para texto de user/assistant (descarta chamadas/resultados de ferramentas),
  concatena e trunca para `max_transcript_chars`.
- `App\Services\Condense\KnowledgeExtractor` (interface) —
  `extract(string $transcript, int $projectId): array` (lista de candidatos
  `{title, content(markdown), category, entities[], relations[]}`; `[]` quando
  não há nada durável). Dois drivers:
  - `App\Services\Condense\ClaudeSdkExtractor` — analogia PHP do Agent SDK:
    `Symfony\Component\Process\Process` roda
    `claude -p --model <model> --output-format json`, reaproveitando a
    **assinatura/login** do Claude Code no host (sem API key). Caminho
    "subscription" do claude-mem. Faz parse do JSON de saída; se o binário
    `claude` não existir, loga aviso claro e retorna `[]`.
  - `App\Services\Condense\ApiExtractor` — usa `Laravel\Ai` (geração de texto)
    com provider configurável (`anthropic`|`openai`|`gemini`|`openrouter`) e
    `model` de `CondenseSetting`, credenciais de `config/ai.php`.
  - Ambos compartilham o mesmo *prompt builder* (`ExtractionPrompt`) e o mesmo
    pós-processamento (dedup + insert), diferindo só no transporte.
- `App\Enums\ExtractorDriver` (`claude_sdk` | `api`) e
  `App\Enums\ExtractorProvider` (`anthropic`|`openai`|`gemini`|`openrouter`) —
  alimentam os Selects (regra CLAUDE.md: enum → `Select::options()`).

**Persistência**

- Migração + `App\Models\CondenseSetting` — **linha única** (singleton) com:
  `enabled` (bool), `driver` (string/enum), `provider` (string/enum, nullable),
  `model` (string), `min_dedup_score` (float), `max_transcript_chars` (int),
  `system_prompt_override` (text, nullable). Seed com defaults.
- Migração + `App\Models\CondenseRun` — idempotência/observabilidade:
  `session_id` (único), `project_id`, `entries_created` (int),
  `status` (`done`/`skipped`/`failed`), timestamps.

**Config no Martis**

- `App\Martis\Resources\CondenseSettingResource` — edita a **linha única**
  (padrão singleton: index redireciona para o edit do único registro; detalhe do
  singleton a resolver na fase de plano consultando os docs Martis via MCP).
  Campos: `Boolean enabled`; `Select driver`; `Select provider` (visível só
  quando `driver=api`); `Text/Select model`; `Number min_dedup_score`;
  `Number max_transcript_chars`; `Code system_prompt_override` (opcional).
  Todos os `->rules([...])`. Strings via `__()` em `lang/{en,pt_PT,pt_BR}`.

## 4. Contratos de dados

### 4.1 POST `/hooks/condense` (request)

```json
{ "cwd": "/abs/path", "session_id": "uuid", "transcript_path": "/abs/path.jsonl" }
```

Resposta: `202 Accepted` (corpo vazio/curto). O hook ignora o corpo.

### 4.2 Saída do extractor (contrato interno)

```json
[
  {
    "title": "…",
    "content": "# markdown …",
    "category": "decision|rule|architecture|fix|convention|…",
    "entities": ["…"],
    "relations": [{"from":"…","type":"…","to":"…"}]
  }
]
```

Array vazio = nada durável → nenhum pending criado.

## 5. Edge cases e decisões

1. **Driver default:** `claude_sdk` (subscription), modelo
   `claude-haiku-4-5-20251001` — barato e paridade com claude-mem.
2. **Worker containerizado:** `claude_sdk` exige binário `claude` + auth no host
   do worker. Sem isso, usar `api`. O job loga aviso claro se `claude` não
   existir; nunca lança erro fatal.
3. **Transcript vai como path** (POST pequeno); o job lê/parseia no servidor —
   mantém o hook fino, coerente com o design atual. Se o path não for legível
   (ex.: worker remoto), loga e marca `condense_runs.status=failed`.
4. **Idempotência** por `session_id` (`condense_runs`) substitui o loop-guard
   `stop_hook_active`. Stop pode disparar mais de uma vez por sessão; só a
   primeira processa.
5. **Extração retorna `[]`** → nenhum pending; registra `status=skipped`.
6. **Dedup** reusa `HybridSearcher` (`min_dedup_score`) contra entradas
   existentes (approved+pending) antes de inserir.
7. **Segurança/robustez:** toda falha de rede/LLM/parse é engolida e logada — um
   hook nunca pode quebrar a sessão do usuário (princípio já vigente no
   `rag-core.sh`).

## 6. Impacto / arquivos

- **Novos:** `CondenseSessionJob`, `TranscriptParser`, `KnowledgeExtractor` +
  2 drivers, `ExtractionPrompt`, `ExtractorDriver`/`ExtractorProvider` enums,
  `CondenseSetting` + migração, `CondenseRun` + migração,
  `CondenseSettingResource`, lang keys, testes.
- **Alterados:** `routes/hooks.php`, `HookController` (+`condense`),
  `stop.sh` (template bakeado), `lib/rag-core.sh`, `config.sh`,
  `RagInstallCommand`/`ClientInstaller` (template do stop.sh).
- **Removido:** caminho `rag_condense_instruction` / `decision:block` do Stop.

## 7. Testes (estratégia)

- `TranscriptParser`: JSONL fixture → texto filtrado + truncagem.
- `CondenseSessionJob`: fake do `KnowledgeExtractor`; assert cria `pending`,
  respeita dedup, idempotência por `session_id`, `[]` → skip.
- `HookController::condense`: 202 + job despachado (`Queue::fake`).
- `ApiExtractor`: `Laravel\Ai` fakeado → parse do JSON.
- `ClaudeSdkExtractor`: `Process` fakeado (binário ausente → `[]` + log).
- `CondenseSettingResource`: singleton edita a linha única; validação dos campos.
```
