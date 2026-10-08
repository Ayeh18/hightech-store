# PC Custom Store

A PC-hardware e-commerce platform with a compatibility-checked **PC Builder**, built with Laravel 13 (REST API),
Vue 3 and PostgreSQL. Portfolio project, built phase by phase.

> **Status:** Phases 1-2 complete: Docker environment, authentication, role-based access control, audit logging,
> and the product catalog (categories, brands, DB-driven specifications, inventory, images, search and filters, shop UI).
> The PC builder, cart, checkout and payments follow in later phases.

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

## Catalog API (Phase 2)

Amounts are always **integer sen** (`RM 4,602.00` = `460200`). Reads are public; writes need the permission shown.

| Endpoint | Auth | Notes |
|---|---|---|
| `GET /api/products` | public | `q`, `category`, `brands[]`, `price_min`, `price_max` (sen), `in_stock`, `specs[key][]`, `specs[key][min]`, `sort` (`relevance\|popular\|newest\|price_asc\|price_desc`; `relevance` is the default while `q` is set), `per_page` (max 48), `page` |
| `GET /api/products/{id\|slug}` | public | Full product with specifications. Drafts return 404 for customers |
| `GET /api/categories` · `GET /api/brands` | public | With published product counts |
| `GET /api/categories/{slug}/filters` | public | Brands, price range and spec filter options with counts, built from the database |
| `GET /api/admin/products` | `products.manage` | Includes drafts and archived; `status` filter |
| `POST/PUT/DELETE /api/products[/{id}]` | `products.manage` | Specs are validated against the category's definitions; price changes are audit-logged |
| `POST/DELETE /api/products/{id}/images[/{image}]` | `products.manage` | JPG/PNG/WebP, max 3 MB, re-encoded to WebP, max 8 per product |
| `POST /api/products/{id}/inventory/adjustments` | `inventory.manage` | Locks the row, never below zero or reserved, writes a movement and an audit entry |
| `POST/PUT/DELETE /api/categories` · `/api/brands` | `categories.manage` / `brands.manage` | Cannot delete while products exist |
| `POST /api/categories/{slug}/specs` · `PUT/DELETE /api/spec-definitions/{id}` | `categories.manage` | Add specs to a category with no code change |

## PC builder API (Phase 3)

| Endpoint | Auth | Notes |
|---|---|---|
| `GET /api/pc-builder/components` | public | The slots (CPU, motherboard, ...) in screen order, whether each is required, its max quantity and the category that supplies it |
| `POST /api/pc-builder/validate` | public (throttled) | Body `{items: [{slot, product_id, quantity}]}`. Returns the build **recalculated from database prices and stock**: line totals, subtotal (sen), missing required slots and issues (`out_of_stock`, `insufficient_stock`, `product_unavailable`). Client prices are ignored. Products that are not on sale are never revealed |
| `GET/POST /api/builds` | customer | List (with totals) / save a build. Incomplete builds can be saved. Max 25 per customer |
| `GET/PUT/DELETE /api/builds/{id}` | owner only | Anyone else gets `404`. `PUT` can rename and/or replace the parts |
| `POST /api/builds/{id}/duplicate` | owner only | Creates "Copy of ..." |

A build stores only *which* products and how many. Prices are always read live, so a saved build never shows a stale price.

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
- **Flexible specifications**: `spec_definitions` describes each category's specs (label, type, unit, options, filterable) and `product_specifications` stores one typed value per product. The shop filters, product tables and validation are all generated from these rows, so a new category needs data, not code.
- **Search and filters** live in `ProductSearch` (ILIKE with trigram indexes, filters on the *effective* price, stable sorting). Lazy loading is disabled outside production, so a missing eager load fails a test instead of silently causing N+1 queries.
- **Inventory**: `stock`, `reserved` and `available = stock - reserved`, a database `CHECK (reserved <= stock)`, and an append-only `inventory_movements` ledger. Manual adjustments lock the row (`SELECT ... FOR UPDATE`).
- **Tests never touch development data**: `phpunit.xml` forces the `pc_store_testing` database.
