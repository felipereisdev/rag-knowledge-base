# Design — Colocação do worker de condensação dirigida pelo Martis

**Data:** 2026-07-10
**Status:** Aprovado (aguardando revisão do spec escrito)

## 1. Objetivo

Fazer o **modo do extrator** (`claude_sdk` vs `api`), configurado no **Martis**
(`CondenseSetting.driver`), determinar automaticamente **onde** o worker da fila
roda:

- `claude_sdk` → worker roda **localmente no host** (`php artisan queue:work`),
  reaproveitando o `claude` autenticado da máquina — modelo do claude-mem, sem
  API key.
- `api` → worker roda **no Docker** (serviço `rag-worker`), usando a API key do
  provider.

Fonte da verdade única: o campo `driver` no Martis. **Sem env var novo** para o
driver — o helper deriva a localização dele.

### Não-objetivos (YAGNI)
- Nenhuma env var para escolher o modo (decisão do usuário: fica no Martis).
- Não migrar o worker para SQLite nem mudar a fila (continua Postgres/`database`).
- Não auto-detectar/alternar em runtime; a troca é manual no Martis + reiniciar o
  worker pelo helper.

## 2. Contexto atual

- `CondenseSetting.driver` (singleton, editável no `CondenseSettingResource`)
  já existe; `KnowledgeExtractorFactory` lê `$setting->driver`. Default
  `claude_sdk`. (Feature anterior, já merjada.)
- `docker-compose.yml`: serviço `worker` (`rag-worker`) builda do estágio
  `production`, roda `entrypoint-worker.sh` (queue:work), `restart: unless-stopped`
  — hoje **sobe sempre** no `docker compose up`. Fila `QUEUE_CONNECTION=database`.
- Postgres e embedder expõem portas ao host (o worker local precisa alcançá-los).

## 3. Arquitetura

### 3.1 Componentes novos/alterados

- **`App\Console\Commands\CondenseDriverCommand`** (`rag:condense-driver`) — imprime
  **apenas** o valor do driver atual (`claude_sdk` ou `api`) em stdout, sem
  decoração, para o script shell capturar. Lê `CondenseSetting::current()->driver`.
- **`bin/condense-worker.sh`** — lê o driver via `php artisan rag:condense-driver`
  e inicia o worker no lugar certo:
  - `claude_sdk`: se `claude` não está no PATH → erro explicativo e `exit 1`;
    senão `exec php artisan queue:work --queue=default` (host).
  - `api`: `exec docker compose --profile condense up -d worker`.
  - valor desconhecido/vazio: erro pedindo para configurar no Martis, `exit 1`.
- **`docker-compose.yml`** — adicionar `profiles: ["condense"]` ao serviço
  `worker`, para ele **não** subir no `docker compose up` normal; só é iniciado
  pelo helper (modo `api`) ou por `--profile condense`.
- **README / tutorial** — seção "Rodando o worker de condensação" explicando o
  helper e o pré-requisito do modo local (host com `.env` apontando para o
  Postgres/embedder expostos + `claude` autenticado).

### 3.2 Fluxo

```
Você troca o driver no Martis (Condense Settings)
  └─ roda:  ./bin/condense-worker.sh
       └─ php artisan rag:condense-driver  → "claude_sdk" | "api"
            ├─ claude_sdk → (checa `claude` no PATH) → php artisan queue:work   [host]
            └─ api        → docker compose --profile condense up -d worker       [docker]
```

## 4. Edge cases / decisões

1. **Ler o driver exige o DB acessível.** O helper roda no host; o `php artisan`
   do host precisa conectar no Postgres (exposto). Pré-requisito documentado.
2. **Modo `claude_sdk` exige host configurado**: `.env` do host com
   `DB_HOST`/`RAG_EMBED_URL` apontando para as portas expostas, e `claude`
   autenticado. O helper valida só o `claude`; falhas de DB/embedder aparecem no
   `queue:work`.
3. **Breaking change consciente**: após o profile, `docker compose up` deixa de
   subir o worker. Documentar — o worker agora é opt-in via helper/profile.
4. **Idempotência do docker**: `up -d` é seguro de rodar repetido.
5. **Guarda-corpo `claude_sdk` sem binário**: erro claro antes de iniciar, em vez
   de um worker que loga "claude binary not found" a cada job (o
   `ClaudeSdkExtractor` já degrada para `[]`, mas o helper falha cedo e explica).

## 5. Impacto / arquivos

- **Novos:** `app/Console/Commands/CondenseDriverCommand.php`,
  `bin/condense-worker.sh`, teste do command.
- **Alterados:** `docker-compose.yml` (profile no `worker`), `README.md`
  (seção do helper).

## 6. Testes

- `CondenseDriverCommand`: Pest — seta `CondenseSetting` para `api`/`claude_sdk`
  e assere que o output é exatamente esse valor (sem ruído).
- `bin/condense-worker.sh`: teste de presença/conteúdo no estilo dos stub-tests
  existentes (contém os dois branches `queue:work` e `docker compose --profile
  condense`, e a checagem do `claude`); execução real das duas pontas é manual.
- `docker-compose.yml`: (opcional) assert simples de que `worker` tem
  `profiles: ["condense"]`.
