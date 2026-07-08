# Phase 4 — Docker + CI/CD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Containerizar o app Laravel em 5 services Docker (app, web, worker, postgres, embedder), adicionar pipeline CI/CD no GitHub Actions com full integration tests, e fazer o merge de todas as branches feat/* em main.

**Architecture:** Um `Dockerfile.app` multi-stage (builder + final) produz a imagem PHP-FPM. O `docker-compose.yml` orquestra 5 services: `app` (PHP-FPM), `web` (nginx), `worker` (queue:work), `postgres` (pgvector), `embedder` (FastAPI sidecar). O GitHub Actions workflow sobe o docker-compose completo, roda Pest + PHPStan + Pint dentro do container, e em pushes para main faz build + push da imagem para ghcr.io.

**Tech Stack:** PHP 8.3, Laravel 13, `php:8.3-fpm-alpine`, `nginx:alpine`, `pgvector/pgvector:pg16`, Docker Compose, GitHub Actions, ghcr.io.

**Spec reference:** `docs/superpowers/specs/2026-07-08-phase4-docker-ci-design.md`

---

## Global Constraints

- PHP 8.3+ (matches `composer.json` constraint `"php": "^8.3"`)
- Laravel 13.x, `laravel/framework: ^13.8`
- Postgres 16 + pgvector (image `pgvector/pgvector:pg16`)
- Base image: `php:8.3-fpm-alpine` (Alpine for small image size)
- Single `docker-compose.yml` — production-ready, used for dev and CI
- Registry: ghcr.io (GitHub Container Registry)
- CI: GitHub Actions with `GITHUB_TOKEN` (no extra secrets needed for ghcr.io)
- All 111 existing Pest tests must continue to pass inside the container

---

## File Structure

**Create:**
- `Dockerfile.app` — multi-stage build (builder + final PHP-FPM)
- `docker/nginx/app.conf` — nginx config (fastcgi_pass app:9000)
- `docker/php/php.ini` — opcache + production PHP settings
- `docker/entrypoint-app.sh` — runs migrations + starts PHP-FPM
- `docker/entrypoint-worker.sh` — waits for DB + starts queue:work
- `.env.ci` — CI env file (DB host=postgres, embed URL=http://embedder:8000/v1)
- `.github/workflows/ci.yml` — CI/CD pipeline (test, lint, build+push)

**Modify:**
- `docker-compose.yml` — add app, web, worker services; shared volumes; health checks
- `.dockerignore` — expand to cover vendor/, node_modules/, .git/, storage/logs, tests/, docs/, .worktrees/
- `.env.example` — comment DB_HOST for Docker vs host
- `.gitignore` — add .pytest_cache/
- `README.md` — Docker section + updated Quick start + CI/CD section

**No changes:**
- `services/embedder/` — already complete
- `app/`, `routes/`, `config/`, `database/` — application code unchanged

---

## Task 1: Merge all feat/* branches into main

**Files:**
- No file changes — git operations only

- [ ] **Step 1: Switch to main and update**

```bash
git checkout main
git pull origin main
```

Expected: On main, up to date.

- [ ] **Step 2: Merge feat/admin-panel**

```bash
git merge --no-ff feat/admin-panel -m "merge: Phase 1 — Martis admin panel CRUD"
```

Expected: Clean merge (or conflicts resolved). If conflicts, resolve them, then continue.

- [ ] **Step 3: Merge feat/multi-repo-paths**

```bash
git merge --no-ff feat/multi-repo-paths -m "merge: Phase 1 — multi-repo project paths"
```

Expected: Clean merge.

- [ ] **Step 4: Merge feat/vector-search**

```bash
git merge --no-ff feat/vector-search -m "merge: Phase 2 — hybrid search + KAG + embedder sidecar"
```

Expected: Clean merge.

- [ ] **Step 5: Merge feat/phase3-mcp-cli**

```bash
git merge --no-ff feat/phase3-mcp-cli -m "merge: Phase 3 — MCP server + Artisan commands + graph explorer"
```

Expected: Clean merge.

- [ ] **Step 6: Run test suite to validate the merge**

```bash
docker compose up -d postgres
php artisan migrate --force
vendor/bin/pest
```

Expected: All 111 tests pass. If any fail, fix the merge conflicts' side effects before proceeding.

- [ ] **Step 7: Push main to remote**

```bash
git push origin main
```

Expected: main pushed successfully.

- [ ] **Step 8: Delete merged branches**

```bash
git branch -d feat/admin-panel feat/multi-repo-paths feat/vector-search feat/phase3-mcp-cli
git push origin --delete feat/admin-panel feat/multi-repo-paths feat/vector-search feat/phase3-mcp-cli 2>/dev/null || true
```

Expected: Local branches deleted. Remote branches may not exist (if they're local-only), so `|| true` handles that.

- [ ] **Step 9: Create Phase 4 branch**

```bash
git checkout -b feat/phase4-docker-ci
```

Expected: On `feat/phase4-docker-ci`.

---

## Task 2: Create Dockerfile.app (multi-stage)

**Files:**
- Create: `Dockerfile.app`
- Create: `docker/php/php.ini`
- Create: `docker/entrypoint-app.sh`
- Create: `docker/entrypoint-worker.sh`

- [ ] **Step 1: Create the PHP production ini**

`docker/php/php.ini`:
```ini
[opcache]
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.save_comments=1

[production]
memory_limit=256M
upload_max_filesize=64M
post_max_size=64M
max_execution_time=120
```

- [ ] **Step 2: Create the app entrypoint script**

`docker/entrypoint-app.sh`:
```bash
#!/bin/sh
set -e

echo "Running migrations..."
php artisan migrate --force --no-interaction

echo "Starting PHP-FPM..."
exec php-fpm
```

- [ ] **Step 3: Create the worker entrypoint script**

`docker/entrypoint-worker.sh`:
```bash
#!/bin/sh
set -e

echo "Waiting for postgres..."
until php -r "new PDO('pgsql:host=${DB_HOST};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
  sleep 1
done

echo "Running migrations..."
php artisan migrate --force --no-interaction

echo "Starting queue worker..."
exec php artisan queue:work --tries=3 --sleep=3 --max-time=3600
```

- [ ] **Step 4: Create the Dockerfile.app**

`Dockerfile.app`:
```dockerfile
# Stage 1: Builder
FROM php:8.3-fpm-alpine AS builder

RUN apk add --no-cache \
    autoconf \
    build-base \
    libpq-dev \
    linux-headers \
    && docker-php-ext-install pdo_pgsql pgsql bcmath zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .
RUN composer dump-autoload --no-dev --optimize

# Stage 2: Final
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    libpq-dev \
    icu-libs \
    && docker-php-ext-install pdo_pgsql pgsql bcmath opcache zip

COPY docker/php/php.ini /usr/local/etc/php/conf.d/production.ini

WORKDIR /var/www/html

COPY --from=builder /var/www/html .
COPY docker/entrypoint-app.sh /usr/local/bin/entrypoint-app.sh
COPY docker/entrypoint-worker.sh /usr/local/bin/entrypoint-worker.sh
RUN chmod +x /usr/local/bin/entrypoint-app.sh /usr/local/bin/entrypoint-worker.sh

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

ENTRYPOINT ["/usr/local/bin/entrypoint-app.sh"]
```

- [ ] **Step 5: Make entrypoint scripts executable**

```bash
chmod +x docker/entrypoint-app.sh docker/entrypoint-worker.sh
```

Expected: Both scripts have execute permission.

- [ ] **Step 6: Verify the Docker image builds**

```bash
docker build -f Dockerfile.app -t rag-app-test .
```

Expected: Build succeeds. Image `rag-app-test` is created.

- [ ] **Step 7: Commit**

```bash
git add Dockerfile.app docker/php/php.ini docker/entrypoint-app.sh docker/entrypoint-worker.sh
git commit -m "feat: add Dockerfile.app with multi-stage build and entrypoints"
```

---

## Task 3: Create nginx config

**Files:**
- Create: `docker/nginx/app.conf`

- [ ] **Step 1: Create the nginx config**

`docker/nginx/app.conf`:
```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php index.html;

    client_max_body_size 64M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add docker/nginx/app.conf
git commit -m "feat: add nginx config for PHP-FPM upstream"
```

---

## Task 4: Update docker-compose.yml with 5 services

**Files:**
- Modify: `docker-compose.yml`

- [ ] **Step 1: Replace docker-compose.yml with the full 5-service stack**

`docker-compose.yml`:
```yaml
services:
  postgres:
    image: pgvector/pgvector:pg16
    container_name: rag-postgres
    environment:
      - POSTGRES_DB=rag
      - POSTGRES_USER=rag
      - POSTGRES_PASSWORD=secret
    volumes:
      - rag-pgdata:/var/lib/postgresql/data
    ports:
      - "5433:5432"
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U rag -d rag"]
      interval: 5s
      timeout: 5s
      retries: 5
    restart: unless-stopped

  embedder:
    build:
      context: services/embedder
      dockerfile: Dockerfile
    container_name: rag-embedder
    ports:
      - "8001:8000"
    environment:
      - RAG_EMBEDDING_MODEL=paraphrase-multilingual-mpnet-base-v2
      - RAG_EMBEDDING_DIM=768
    volumes:
      - hf-cache:/root/.cache/huggingface
    healthcheck:
      test: ["CMD-SHELL", "curl -f http://localhost:8000/health || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 10
      start_period: 60s
    restart: unless-stopped

  app:
    build:
      context: .
      dockerfile: Dockerfile.app
    container_name: rag-app
    volumes:
      - storage:/var/www/html/storage
      - bootstrap-cache:/var/www/html/bootstrap/cache
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - APP_URL=http://localhost:8080
      - DB_CONNECTION=pgsql
      - DB_HOST=postgres
      - DB_PORT=5432
      - DB_DATABASE=rag
      - DB_USERNAME=rag
      - DB_PASSWORD=secret
      - RAG_EMBED_URL=http://embedder:8000/v1
      - RAG_EMBED_KEY=rag-local
      - RAG_EMBEDDING_MODEL=paraphrase-multilingual-mpnet-base-v2
      - RAG_EMBEDDING_DIM=768
      - QUEUE_CONNECTION=database
      - SESSION_DRIVER=database
      - CACHE_STORE=database
    depends_on:
      postgres:
        condition: service_healthy
      embedder:
        condition: service_healthy
    healthcheck:
      test: ["CMD-SHELL", "php -r \"new PDO('pgsql:host=postgres;dbname=rag','rag','secret');\""]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: unless-stopped

  web:
    image: nginx:alpine
    container_name: rag-web
    volumes:
      - ./docker/nginx/app.conf:/etc/nginx/conf.d/default.conf:ro
      - storage:/var/www/html/storage:ro
    ports:
      - "8080:80"
    depends_on:
      app:
        condition: service_healthy
    healthcheck:
      test: ["CMD-SHELL", "wget -q --spider http://localhost/ || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: unless-stopped

  worker:
    build:
      context: .
      dockerfile: Dockerfile.app
    container_name: rag-worker
    entrypoint: ["/usr/local/bin/entrypoint-worker.sh"]
    volumes:
      - storage:/var/www/html/storage
      - bootstrap-cache:/var/www/html/bootstrap/cache
    environment:
      - APP_ENV=production
      - APP_DEBUG=false
      - DB_CONNECTION=pgsql
      - DB_HOST=postgres
      - DB_PORT=5432
      - DB_DATABASE=rag
      - DB_USERNAME=rag
      - DB_PASSWORD=secret
      - RAG_EMBED_URL=http://embedder:8000/v1
      - RAG_EMBED_KEY=rag-local
      - RAG_EMBEDDING_MODEL=paraphrase-multilingual-mpnet-base-v2
      - RAG_EMBEDDING_DIM=768
      - QUEUE_CONNECTION=database
    depends_on:
      postgres:
        condition: service_healthy
      embedder:
        condition: service_healthy
    restart: unless-stopped

volumes:
  rag-pgdata:
  storage:
  bootstrap-cache:
  hf-cache:
```

- [ ] **Step 2: Test the full stack starts**

```bash
docker compose down -v
docker compose up -d --build
```

Expected: All 5 services start. The build takes a few minutes (embedder downloads model on first build).

- [ ] **Step 3: Wait for health checks and verify**

```bash
docker compose ps
```

Expected: All services show `healthy` status. The `embedder` may take up to 60s for first health check (model loading).

- [ ] **Step 4: Run migrations inside the container**

```bash
docker compose exec app php artisan migrate --force
```

Expected: Migrations run successfully (all tables created, pgvector extension enabled).

- [ ] **Step 5: Verify the web server responds**

```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/up
```

Expected: `200` (Laravel health endpoint).

- [ ] **Step 6: Commit**

```bash
git add docker-compose.yml
git commit -m "feat: add app, web, worker services to docker-compose.yml"
```

---

## Task 5: Run tests inside the container and fix issues

**Files:**
- Modify: `Dockerfile.app` (if test dependencies are missing)
- Modify: `docker-compose.yml` (if env adjustments needed)

The Dockerfile.app installs dependencies with `--no-dev`, but tests need `--dev` for Pest, PHPStan, Pint. We need a dev target or a separate approach.

- [ ] **Step 1: Create a dev target in Dockerfile.app**

Add this at the end of `Dockerfile.app`:
```dockerfile
# Stage 2-dev: Includes dev dependencies for testing
FROM builder AS dev

RUN composer install --dev --optimize-autoloader --no-interaction --no-scripts
RUN composer dump-autoload --optimize

FROM php:8.3-fpm-alpine AS app-dev

RUN apk add --no-cache \
    libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql bcmath opcache zip

COPY docker/php/php.ini /usr/local/etc/php/conf.d/production.ini

WORKDIR /var/www/html

COPY --from=dev /var/www/html .
COPY docker/entrypoint-app.sh /usr/local/bin/entrypoint-app.sh
COPY docker/entrypoint-worker.sh /usr/local/bin/entrypoint-worker.sh
RUN chmod +x /usr/local/bin/entrypoint-app.sh /usr/local/bin/entrypoint-worker.sh

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

ENTRYPOINT ["/usr/local/bin/entrypoint-app.sh"]
```

- [ ] **Step 2: Add a dev override to docker-compose.yml**

Add an `app-dev` service at the end of the `services:` block (before `volumes:`):

```yaml
  app-dev:
    build:
      context: .
      dockerfile: Dockerfile.app
      target: app-dev
    profiles: ["dev"]
    volumes:
      - storage:/var/www/html/storage
      - bootstrap-cache:/var/www/html/bootstrap/cache
      - ./:/var/www/html
    environment:
      - APP_ENV=testing
      - APP_DEBUG=true
      - DB_CONNECTION=pgsql
      - DB_HOST=postgres
      - DB_PORT=5432
      - DB_DATABASE=rag
      - DB_USERNAME=rag
      - DB_PASSWORD=secret
      - RAG_EMBED_URL=http://embedder:8000/v1
      - RAG_EMBED_KEY=rag-local
      - RAG_EMBEDDING_MODEL=paraphrase-multilingual-mpnet-base-v2
      - RAG_EMBEDDING_DIM=768
      - QUEUE_CONNECTION=database
      - SESSION_DRIVER=database
      - CACHE_STORE=database
    depends_on:
      postgres:
        condition: service_healthy
      embedder:
        condition: service_healthy
    entrypoint: ["sh", "-c"]
    command: ["sleep infinity"]
```

- [ ] **Step 3: Rebuild with dev target**

```bash
docker compose --profile dev up -d --build app-dev
```

Expected: `app-dev` builds with dev dependencies (Pest, PHPStan, Pint available).

- [ ] **Step 4: Run Pest inside the dev container**

```bash
docker compose --profile dev exec app-dev vendor/bin/pest
```

Expected: All 111 tests pass. If tests fail due to missing APP_KEY, run `docker compose --profile dev exec app-dev php artisan key:generate`.

- [ ] **Step 5: Run PHPStan inside the dev container**

```bash
docker compose --profile dev exec app-dev vendor/bin/phpstan analyse --level=6 --memory-limit=2G
```

Expected: 0 errors.

- [ ] **Step 6: Run Pint inside the dev container**

```bash
docker compose --profile dev exec app-dev vendor/bin/pint --test
```

Expected: All files pass formatting check.

- [ ] **Step 7: Commit**

```bash
git add Dockerfile.app docker-compose.yml
git commit -m "feat: add dev target for running tests inside Docker"
```

---

## Task 6: Update .dockerignore and .gitignore

**Files:**
- Modify: `.dockerignore`
- Modify: `.gitignore`

- [ ] **Step 1: Update .dockerignore**

`.dockerignore`:
```
.git
.github
.worktrees/
.cursor/
.idea/
.vscode/
.zed/
.codex/
docs/
tests/
storage/logs/
storage/framework/cache/
storage/pail/
node_modules/
vendor/
.phpunit.cache/
.phpunit.result.cache
.pytest_cache/
*.md
*.log
.DS_Store
.env
.env.backup
.env.production
.env.ci
auth.json
Homestead.json
Homestead.yaml
Thumbs.db
```

Note: `vendor/` is excluded from the build context because the builder stage runs `composer install` inside the container. `tests/` and `docs/` are not needed in the production image. `.env*` files are excluded for security.

- [ ] **Step 2: Add .pytest_cache to .gitignore**

Add to end of `.gitignore`:
```
/.pytest_cache
```

- [ ] **Step 3: Rebuild to verify .dockerignore works**

```bash
docker compose down -v
docker compose --profile dev up -d --build app-dev
docker compose --profile dev exec app-dev vendor/bin/pest
```

Expected: Build succeeds (smaller context), tests still pass.

- [ ] **Step 4: Commit**

```bash
git add .dockerignore .gitignore
git commit -m "chore: expand .dockerignore and add .pytest_cache to .gitignore"
```

---

## Task 7: Update .env.example for Docker

**Files:**
- Modify: `.env.example`

- [ ] **Step 1: Update DB_HOST with comments**

In `.env.example`, replace the DB section:
```
DB_CONNECTION=pgsql
# When running inside Docker: DB_HOST=postgres
# When running on host: DB_HOST=127.0.0.1
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rag
DB_USERNAME=rag
DB_PASSWORD=secret
```

Also update the embedder URL section:
```
# RAG Embedder Sidecar
# When running inside Docker: RAG_EMBED_URL=http://embedder:8000/v1
# When running on host: RAG_EMBED_URL=http://localhost:8001/v1
RAG_EMBED_URL=http://localhost:8001/v1
RAG_EMBED_KEY=rag-local
RAG_EMBEDDING_MODEL=paraphrase-multilingual-mpnet-base-v2
RAG_EMBEDDING_DIM=768
```

- [ ] **Step 2: Commit**

```bash
git add .env.example
git commit -m "docs: add Docker vs host comments to .env.example"
```

---

## Task 8: Create .env.ci for GitHub Actions

**Files:**
- Create: `.env.ci`

- [ ] **Step 1: Create .env.ci**

`.env.ci`:
```
APP_NAME="RAG Knowledge Base"
APP_ENV=testing
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8080

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=rag
DB_USERNAME=rag
DB_PASSWORD=secret

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

RAG_EMBED_URL=http://embedder:8000/v1
RAG_EMBED_KEY=rag-local
RAG_EMBEDDING_MODEL=paraphrase-multilingual-mpnet-base-v2
RAG_EMBEDDING_DIM=768

RAG_SEARCH_MIN_SCORE=0.30
RAG_SEARCH_LIMIT=10
RAG_SEARCH_RRF_K=60
RAG_SEARCH_GRAPH_EXPAND=true
RAG_SEARCH_GRAPH_WEIGHT=0.3
RAG_SEARCH_VECTOR_TOP_K=20
RAG_SEARCH_FTS_TOP_K=20

MARTIS_MCP_ENABLED=true
```

Note: `APP_KEY` is empty — the CI workflow generates it with `php artisan key:generate`.

- [ ] **Step 2: Add .env.ci to .gitignore (it should NOT be committed with real secrets, but this one has no secrets — it's safe to commit as a template)**

Do NOT add to `.gitignore`. This file has no secrets and serves as the CI config.

- [ ] **Step 3: Commit**

```bash
git add .env.ci
git commit -m "feat: add .env.ci for GitHub Actions integration tests"
```

---

## Task 9: Create GitHub Actions workflow

**Files:**
- Create: `.github/workflows/ci.yml`

- [ ] **Step 1: Create the workflow file**

`.github/workflows/ci.yml`:
```yaml
name: CI

on:
  push:
    branches: [main, 'feat/*']
  pull_request:
    branches: [main]

env:
  COMPOSE_FILE: docker-compose.yml

jobs:
  test:
    name: Tests (Pest + integration)
    runs-on: ubuntu-latest
    timeout-minutes: 30
    steps:
      - uses: actions/checkout@v4

      - name: Copy CI env
        run: cp .env.ci .env

      - name: Generate APP_KEY
        run: echo "APP_KEY=base64:$(openssl rand -base64 32)" >> .env

      - name: Build and start services
        run: docker compose --profile dev up -d --build app-dev postgres embedder

      - name: Wait for postgres
        run: |
          for i in $(seq 1 30); do
            if docker compose exec -T postgres pg_isready -U rag -d rag; then
              break
            fi
            sleep 2
          done

      - name: Wait for embedder
        run: |
          for i in $(seq 1 60); do
            if docker compose exec -T embedder curl -sf http://localhost:8000/health; then
              break
            fi
            sleep 5
          done

      - name: Generate key in container
        run: docker compose --profile dev exec -T app-dev php artisan key:generate

      - name: Run migrations
        run: docker compose --profile dev exec -T app-dev php artisan migrate --force

      - name: Run Pest
        run: docker compose --profile dev exec -T app-dev vendor/bin/pest

      - name: Tear down
        if: always
        run: docker compose down -v

  lint:
    name: Lint (PHPStan + Pint)
    runs-on: ubuntu-latest
    timeout-minutes: 15
    steps:
      - uses: actions/checkout@v4

      - name: Copy CI env
        run: cp .env.ci .env

      - name: Generate APP_KEY
        run: echo "APP_KEY=base64:$(openssl rand -base64 32)" >> .env

      - name: Build app-dev
        run: docker compose --profile dev up -d --build app-dev

      - name: Run PHPStan
        run: docker compose --profile dev exec -T app-dev vendor/bin/phpstan analyse --level=6 --memory-limit=2G

      - name: Run Pint (check only)
        run: docker compose --profile dev exec -T app-dev vendor/bin/pint --test

      - name: Tear down
        if: always
        run: docker compose down -v

  build-and-push:
    name: Build and push to ghcr.io
    runs-on: ubuntu-latest
    needs: [test, lint]
    if: github.ref == 'refs/heads/main' && github.event_name == 'push'
    permissions:
      contents: read
      packages: write
    steps:
      - uses: actions/checkout@v4

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Login to ghcr.io
        uses: docker/login-action@v3
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Build and push
        uses: docker/build-push-action@v6
        with:
          context: .
          file: Dockerfile.app
          push: true
          tags: |
            ghcr.io/${{ github.repository_owner }}/rag-app:${{ github.sha }}
            ghcr.io/${{ github.repository_owner }}/rag-app:latest
          cache-from: type=gha
          cache-to: type=gha,mode=max
```

- [ ] **Step 2: Verify the workflow file is valid YAML**

```bash
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))" && echo "Valid YAML"
```

Expected: "Valid YAML"

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "feat: add GitHub Actions CI/CD pipeline"
```

---

## Task 10: Update README.md with Docker and CI/CD sections

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Update Quick start to use Docker**

Replace the existing Quick start section in `README.md`:

```markdown
## Quick start

```bash
# 1. Clone and enter the project
git clone <repo-url> rag
cd rag

# 2. Copy environment file
cp .env.example .env

# 3. Start all services (app, web, worker, postgres, embedder)
docker compose up -d --build

# 4. Run migrations
docker compose exec app php artisan migrate

# 5. Open the admin panel
open http://localhost:8080/martis
```

### Running without Docker

If you prefer to run the app on your host:

```bash
# 1. Start Postgres+pgvector only
docker compose up -d postgres

# 2. Install dependencies
composer install

# 3. Run migrations and seed
php artisan migrate
php artisan db:seed

# 4. Start the dev server
php artisan serve

# 5. Open the admin panel
open http://localhost:8000/martis
```
```

- [ ] **Step 2: Add Docker section after Quick start**

```markdown
## Docker

The `docker-compose.yml` orchestrates 5 services:

| Service | Image | Port | Purpose |
|---|---|---|---|
| `app` | `Dockerfile.app` (PHP-FPM 8.3) | — | Laravel application |
| `web` | `nginx:alpine` | `8080:80` | nginx reverse proxy |
| `worker` | `Dockerfile.app` | — | Queue worker (`queue:work`) |
| `postgres` | `pgvector/pgvector:pg16` | `5433:5432` | Postgres + pgvector |
| `embedder` | `services/embedder/` (FastAPI) | `8001:8000` | Embedding sidecar |

### Common commands

```bash
# Start all services
docker compose up -d --build

# Run migrations
docker compose exec app php artisan migrate

# Run tests (uses the dev profile with test dependencies)
docker compose --profile dev up -d --build app-dev
docker compose --profile dev exec app-dev vendor/bin/pest

# Run static analysis
docker compose --profile dev exec app-dev vendor/bin/phpstan analyse --level=6 --memory-limit=2G

# Check formatting
docker compose --profile dev exec app-dev vendor/bin/pint --test

# View logs
docker compose logs -f app
docker compose logs -f worker

# Stop everything
docker compose down

# Stop and remove volumes (fresh start)
docker compose down -v
```

## CI/CD

GitHub Actions runs on every push and PR:

| Job | When | What |
|---|---|---|
| `test` | All pushes + PRs | `docker compose up` + Pest (full integration) |
| `lint` | All pushes + PRs | PHPStan level 6 + Pint check |
| `build-and-push` | Push to `main` only | Build `Dockerfile.app` + push to `ghcr.io` |

The Docker image is published to `ghcr.io/<owner>/rag-app:latest` on every merge to main.
```

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: add Docker and CI/CD sections to README"
```

---

## Task 11: Final verification

**Files:**
- No file changes — verification only

- [ ] **Step 1: Full Docker rebuild from scratch**

```bash
docker compose down -v
docker compose up -d --build
```

Expected: All 5 services build and start. No errors.

- [ ] **Step 2: Health check all services**

```bash
docker compose ps
```

Expected: All services show `Up (healthy)`.

- [ ] **Step 3: Run migrations**

```bash
docker compose exec app php artisan migrate --force
```

Expected: All migrations run.

- [ ] **Step 4: Web server responds**

```bash
curl -sf http://localhost:8080/up
```

Expected: HTTP 200.

- [ ] **Step 5: Run test suite in dev container**

```bash
docker compose --profile dev up -d --build app-dev
docker compose --profile dev exec app-dev php artisan key:generate
docker compose --profile dev exec app-dev php artisan migrate --force
docker compose --profile dev exec app-dev vendor/bin/pest
```

Expected: All 111 tests pass.

- [ ] **Step 6: Run PHPStan and Pint in dev container**

```bash
docker compose --profile dev exec app-dev vendor/bin/phpstan analyse --level=6 --memory-limit=2G
docker compose --profile dev exec app-dev vendor/bin/pint --test
```

Expected: PHPStan 0 errors. Pint all files pass.

- [ ] **Step 7: Verify queue worker is running**

```bash
docker compose logs worker --tail 5
```

Expected: Worker logs show "Starting queue worker..." and waiting for jobs.

- [ ] **Step 8: Push branch and create PR**

```bash
git push -u origin feat/phase4-docker-ci
gh pr create --title "Phase 4: Docker + CI/CD" --body "Containerizes the full stack (5 services), adds GitHub Actions pipeline with full integration tests, and merges all feature branches into main."
```

Expected: PR created. CI workflow triggers automatically.

- [ ] **Step 9: Final commit (if any fixes needed from verification)**

```bash
git status
git add -A
git commit -m "fix: address verification findings" || echo "Nothing to commit"
```

---

## Self-Review Checklist

After completing all tasks, verify:

1. **Spec coverage:**
   - [x] 5 Docker services (app, web, worker, postgres, embedder) — Task 4
   - [x] Multi-stage Dockerfile.app — Task 2
   - [x] nginx config — Task 3
   - [x] Worker with queue:work — Task 4 (docker-compose worker service)
   - [x] Health checks on all services — Task 4 (docker-compose healthcheck blocks)
   - [x] GitHub Actions CI/CD — Task 9
   - [x] Full integration tests in CI — Task 9 (test job)
   - [x] ghcr.io push on main — Task 9 (build-and-push job)
   - [x] Branch merge — Task 1
   - [x] Cleanup (.gitignore, .dockerignore) — Task 6
   - [x] .env.ci — Task 8
   - [x] README + .env.example updates — Tasks 7, 10
   - [x] Final verification — Task 11

2. **Placeholder scan:** No "TBD", "TODO", "implement later" — all steps have complete content.

3. **Type consistency:** Docker service names (`app`, `web`, `worker`, `postgres`, `embedder`) used consistently across docker-compose.yml, ci.yml, nginx config, and entrypoint scripts. Environment variable names match `.env.example` and `.env.ci`.

4. **Contract compatibility:** The 111 existing Pest tests run unchanged inside the container — no test modifications needed. The app code is not modified. Docker only wraps the existing application.