# PC Custom Store

A PC-hardware e-commerce platform with a compatibility-checked **PC Builder**, built with Laravel 13 (REST API),
Vue 3 and PostgreSQL. Portfolio project, built phase by phase.

> **Status:** Phase 1 (foundation) complete: Docker environment, authentication, role-based access control,
> audit logging and the Vue app shell. Catalog, builder, cart, checkout and payments follow in later phases.

## Stack

| Layer | Technology |
|---|---|
| API | Laravel 13, PHP 8.5, Sanctum (SPA cookie auth), Pest, Pint |
| Frontend | Vue 3 (Composition API), Pinia, Vue Router, Axios, Tailwind CSS 4, Vite, Vitest |
| Database | PostgreSQL 17 |
| Dev environment | Docker Compose (app, queue worker, db, frontend, Mailpit) |

## Quick start

Requirements: Docker with Compose, and `npm` (only needed to install frontend packages).

```bash
./scripts/setup.sh                      # creates .env files with a random DB password, builds the image
(cd frontend && npm install)            # frontend dependencies
docker compose up -d                    # start everything
docker compose exec app php artisan migrate --seed
```

| Service | URL |
|---|---|
| Web app (Vue) | http://localhost:5173 |
| API (Laravel) | http://localhost:8000 (the web app proxies `/api` to it) |
| Mailpit (dev inbox) | http://localhost:8025 |
| PostgreSQL | `127.0.0.1:5434` (credentials in `.env`) |

### Demo accounts (local development only)

Created by `DemoUserSeeder`, which never runs outside the `local`/`testing` environments.

| Role | Email | Password |
|---|---|---|
| Admin | admin@example.com | Password123 |
| Staff | staff@example.com | Password123 |
| Customer | customer@example.com | Password123 |

## Tests and quality checks

```bash
docker compose run --rm app ./vendor/bin/pest          # backend tests (use the pc_store_testing database)
docker compose run --rm app ./vendor/bin/pint --test   # PHP code style
cd frontend && npm test && npm run lint && npm run build
```

## Architecture notes (Phase 1)

- **Money is stored as integer sen** (`RM 4,602.00` = `460200`) and handled by `App\Support\Money`. No floats, so totals never drift.
- **Auth** uses Sanctum's SPA mode: an httpOnly session cookie plus CSRF protection. The Vite dev server proxies `/api` and `/sanctum` so the browser sees one origin.
- **RBAC**: `roles` and `permissions` tables with string permission keys (e.g. `products.manage`). `Gate::before` grants any ability a user's roles hold, so routes just use `can:<permission>` and new permissions need no code changes.
- **Audit log**: `App\Services\AuditLogger` writes append-only rows and strips passwords, tokens and secrets.
- **Tests never touch development data**: `phpunit.xml` forces the `pc_store_testing` database.
