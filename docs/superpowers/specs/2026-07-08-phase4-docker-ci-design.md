# Phase 4 — Docker + CI/CD Design

## Context

Phases 1-3 delivered the Laravel + Martis app, hybrid search with KAG, and the MCP server + Artisan commands. The app currently runs on the host via `php artisan serve`, with only Postgres and the embedder sidecar containerized. Phase 4 containerizes the full stack, adds a GitHub Actions CI/CD pipeline, and merges all feature branches into main.

## Decisions

| Decision | Choice |
|---|---|
| Scope | Docker (5 services) + GitHub Actions CI/CD + branch merge/cleanup |
| Container architecture | Separate app (PHP-FPM), web (nginx), worker (queue) + postgres + embedder |
| Dockerfile approach | Multi-stage build with `php:8.3-fpm-alpine` base |
| CI/CD platform | GitHub Actions (`.github/workflows/ci.yml`) |
| CI test strategy | Full integration — `docker compose up` + run Pest inside container |
| Docker registry | ghcr.io (GitHub Container Registry) |
| Compose files | Single `docker-compose.yml` — production-ready, used for dev and CI |
| Branch strategy | Merge all feat/* branches into main before Phase 4 work |

## Architecture

### Docker services (5)

```
┌─────────────────────────────────────────────────────────────┐
│  docker-compose.yml                                         │
│                                                             │
│  ┌──────────┐    ┌──────────┐    ┌──────────┐             │
│  │  web     │───▶│  app     │    │  worker  │             │
│  │  nginx   │    │  PHP-FPM │    │  queue    │             │
│  │  :8080   │    │  :9000   │    │  work     │             │
│  └──────────┘    └──────────┘    └──────────┘             │
│       │              │                │                     │
│       │              ▼                ▼                     │
│       │         ┌──────────┐    ┌──────────┐             │
│       │         │ postgres │    │ embedder │             │
│       │         │ pgvector │    │  FastAPI │             │
│       │         │  :5432   │    │  :8000   │             │
│       │         └──────────┘    └──────────┘             │
│       │                                              │
│       └─ /var/www/html/storage (volume compartilhado)  │
└─────────────────────────────────────────────────────────────┘
```

### Dockerfile.app (multi-stage)

**Stage 1 — builder:**
- Base: `php:8.3-fpm-alpine`
- Install: composer, build deps (autoconf, gcc), PHP extensions source
- Run: `composer install --no-dev --optimize-autoloader`
- Output: `/var/www/html` with vendor/ and app code

**Stage 2 — final:**
- Base: `php:8.3-fpm-alpine`
- Install only runtime extensions: `pdo_pgsql`, `pgsql`, `bcmath`, `gd`, `opcache`, `zip`
- Copy: `/var/www/html` from builder (includes vendor/)
- Copy: `docker/php/php.ini` with opcache + production settings
- Entrypoint: `docker/entrypoint-app.sh` (runs migrations, then starts PHP-FPM)
- No composer, no build tools in final image

The `worker` service reuses the same image but overrides the entrypoint with `docker/entrypoint-worker.sh` (waits for DB, then runs `php artisan queue:work`).

### nginx

- Image: `nginx:alpine`
- Config: `docker/nginx/app.conf`
  - `root /var/www/html/public;`
  - `fastcgi_pass app:9000;`
  - Standard Laravel location blocks (try_files $uri /index.php?$query_string)
- Port: `8080:80` (host:container)
- Health: `curl -f http://localhost/up` (Laravel health endpoint)

### worker

- Image: same as `app`
- Entrypoint: `docker/entrypoint-worker.sh`
  - Wait for postgres to be healthy
  - Run `php artisan queue:work --tries=3 --sleep=3 --max-time=3600`
- Supervisor config: `docker/worker/supervisord.conf` (auto-restart on failure)
- Shares `storage` and `bootstrap/cache` volumes with app

### Shared volumes

- `storage` — logs, uploads, framework cache (mounted in app, worker, web)
- `bootstrap-cache` — config/route cache (mounted in app, worker)
- `rag-pgdata` — postgres data (already exists)

### Health checks

| Service | Check |
|---|---|
| postgres | `pg_isready -U rag -d rag` (already exists) |
| embedder | `curl -f http://localhost:8000/health` (already exists) |
| app | PHP script: `php -r "new PDO('pgsql:host=postgres;dbname=rag', 'rag', 'secret');"` |
| web | `curl -f http://localhost/up` |
| worker | Supervisor process check: `supervisorctl status queue:work` |

### docker-compose.yml (final shape)

```yaml
services:
  postgres:
    image: pgvector/pgvector:pg16
    # ... (existing config, healthcheck, volume)

  embedder:
    build: services/embedder
    # ... (existing config, healthcheck)

  app:
    build:
      context: .
      dockerfile: Dockerfile.app
    volumes:
      - storage:/var/www/html/storage
      - bootstrap-cache:/var/www/html/bootstrap/cache
    environment:
      - DB_HOST=postgres
      - DB_PORT=5432
      - RAG_EMBED_URL=http://embedder:8000/v1
      - QUEUE_CONNECTION=database
    depends_on:
      postgres: { condition: service_healthy }
      embedder: { condition: service_healthy }
    healthcheck:
      test: ["CMD-SHELL", "php -r \"new PDO('pgsql:host=postgres;dbname=rag','rag','secret');\""]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: unless-stopped

  web:
    image: nginx:alpine
    volumes:
      - ./docker/nginx/app.conf:/etc/nginx/conf.d/default.conf:ro
      - storage:/var/www/html/storage:ro
    ports:
      - "8080:80"
    depends_on:
      app: { condition: service_healthy }
    healthcheck:
      test: ["CMD-SHELL", "curl -f http://localhost/up || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: unless-stopped

  worker:
    build:
      context: .
      dockerfile: Dockerfile.app
    command: ["docker/entrypoint-worker.sh"]
    volumes:
      - storage:/var/www/html/storage
      - bootstrap-cache:/var/www/html/bootstrap/cache
    environment:
      - DB_HOST=postgres
      - DB_PORT=5432
      - RAG_EMBED_URL=http://embedder:8000/v1
      - QUEUE_CONNECTION=database
    depends_on:
      postgres: { condition: service_healthy }
      embedder: { condition: service_healthy }
    restart: unless-stopped

volumes:
  storage:
  bootstrap-cache:
  rag-pgdata:
```

## CI/CD Pipeline (GitHub Actions)

### Workflow: `.github/workflows/ci.yml`

**Triggers:** push to `main` or `feat/*`, pull requests.

**Job: test (full integration)**
1. `docker compose up -d --build` — sobe os 5 services
2. Wait for health checks to pass
3. `docker compose exec app php artisan migrate --force` — roda migrations
4. `docker compose exec -T app vendor/bin/pest` — Pest test suite (usa Embeddings::fake)
5. `docker compose down -v` — cleanup

**Job: lint (paralelo ao test)**
1. `docker compose run --no-deps --rm app vendor/bin/pint --test` — verifica formatação
2. `docker compose run --no-deps --rm app vendor/bin/phpstan analyse --level=6 --memory-limit=2G`

**Job: build (apenas em push para main)**
1. `docker build -f Dockerfile.app -t ghcr.io/${{ github.repository_owner }}/rag-app:${{ github.sha }} .`
2. `docker tag ... ghcr.io/.../rag-app:latest`
3. Login to ghcr.io via `${{ secrets.GITHUB_TOKEN }}`
4. Push both tags
5. Permissions: `packages: write`, `contents: read`

**Caching:**
- Docker layer cache via `docker/build-push-action` with `cache-from`/`cache-to` type=gha
- Composer cache via named volume `composer-cache` mounted at `/root/.composer/cache`

### `.env.ci`

Gerado pelo workflow com valores para rodar dentro do Docker:
```
APP_ENV=testing
APP_KEY=base64:...  # generated in workflow
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=rag
DB_USERNAME=rag
DB_PASSWORD=secret
RAG_EMBED_URL=http://embedder:8000/v1
RAG_EMBED_KEY=rag-local
QUEUE_CONNECTION=database
```

## Branch Merge + Cleanup

### Merge order

```
main
 └─ feat/admin-panel        (Phase 1 — Martis CRUD)
     └─ feat/multi-repo-paths  (Phase 1 — ProjectPath model)
         └─ feat/vector-search     (Phase 2 — HybridSearcher, embedder sidecar)
             └─ feat/phase3-mcp-cli    (Phase 3 — MCP, Artisan, graph explorer)
```

Cada branch mergeada com `git merge --no-ff` para preservar histórico. Após cada merge, rodar `php artisan test` para validar.

### Cleanup

- Deletar branches locais: `feat/admin-panel`, `feat/multi-repo-paths`, `feat/vector-search`, `feat/phase3-mcp-cli`
- Deletar branches remotas via `git push origin --delete`
- Adicionar `.pytest_cache/` ao `.gitignore` (resquício Python)
- Revisar `.dockerignore` para cobrir: `vendor/`, `node_modules/`, `.git/`, `storage/logs/`, `storage/framework/cache/`, `tests/`, `docs/`, `.worktrees/`

### Docs updates

- `README.md` — Quick start atualizado para `docker compose up -d`; nova seção "Docker" explicando os 5 services, ports, e comandos comuns (`docker compose exec app php artisan migrate`, `docker compose exec app php artisan test`)
- `README.md` — seção "CI/CD" explicando o pipeline do GitHub Actions
- `.env.example` — `DB_HOST` comentado explicando que dentro do Docker é `postgres` e fora é `127.0.0.1`

## File Structure

### Create

| File | Purpose |
|---|---|
| `Dockerfile.app` | Multi-stage build (builder + final PHP-FPM) |
| `docker/nginx/app.conf` | nginx config (fastcgi_pass app:9000) |
| `docker/worker/supervisord.conf` | Supervisor config for queue worker |
| `docker/php/php.ini` | opcache + production PHP settings |
| `docker/entrypoint-app.sh` | Runs migrations + starts PHP-FPM |
| `docker/entrypoint-worker.sh` | Waits for DB + starts queue:work |
| `.env.ci` | CI env (DB host=postgres, embed URL=http://embedder:8000/v1) |
| `.github/workflows/ci.yml` | CI/CD pipeline (test, lint, build+push) |

### Modify

| File | Changes |
|---|---|
| `docker-compose.yml` | Add app, web, worker services; shared volumes; health checks |
| `.dockerignore` | Ensure vendor, node_modules, .git, storage/logs covered |
| `.env.example` | Comment DB_HOST for Docker vs host; adjust defaults |
| `.gitignore` | Add .pytest_cache/ if missing |
| `README.md` | Docker section + updated Quick start + CI/CD section |

### No changes needed

- `services/embedder/` — already complete
- `app/`, `routes/`, `config/`, `database/` — application code unchanged
- `bootstrap/app.php` — app runs identically inside or outside container

## Testing

- Existing 111 Pest tests continue to pass inside the container
- `docker compose up -d --build` succeeds with zero manual steps
- `docker compose exec app php artisan migrate` runs cleanly
- `docker compose exec app php artisan test` — all 111 tests pass
- GitHub Actions workflow runs green on push
- `docker compose exec app vendor/bin/phpstan analyse --level=6` — 0 errors
- `docker compose exec app vendor/bin/pint --test` — clean

## Risks

| Risk | Mitigation |
|---|---|
| Embedder container is heavy (~2GB with model) | Already built and cached; CI uses `docker compose up` which reuses layers |
| Worker needs storage volume sync with app | Shared named volume `storage` mounted in both |
| pgvector extension must be enabled in CI | postgres image is `pgvector/pgvector:pg16` which includes it |
| MCP server (stdio) doesn't work inside container | Expected — MCP is for dev host use, not containerized production |
| nginx needs access to public/ assets | Mount app code as volume or copy in Dockerfile (copy is preferred for prod) |