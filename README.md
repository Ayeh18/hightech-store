# PC Custom Store

A PC-hardware e-commerce platform with a compatibility-checked **PC Builder**, built with Laravel 13 (REST API),
Vue 3 and PostgreSQL. Portfolio project, built phase by phase.

> **Status:** Phases 1-6 complete: Docker environment, authentication and RBAC, audit logging, the product catalog,
> the PC builder with saved builds, the compatibility engine, the shopping cart, and checkout (addresses, coupons,
> shipping, orders with stock reservation). Payments, order fulfilment and the admin screens follow in later phases.

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

## Compatibility engine (Phase 4)

A build is checked by small **rule classes** in `backend/app/Services/Compatibility/Rules/`, one per check, listed in
`config/compatibility.php`. `CompatibilityService` runs the enabled rules and returns a report: `compatible`, `warning`
(only warnings) or `incompatible` (at least one error). **Errors block buying a build; warnings never do.**

| Rule | Default | Checks |
|---|---|---|
| `cpu_socket_matches_motherboard` | error | AM5 CPU needs an AM5 board |
| `ram_type_matches_motherboard` | error | DDR4 vs DDR5 |
| `ram_modules_fit_motherboard` / `ram_capacity_within_motherboard_limit` | error | sticks <= slots, total GB <= board maximum |
| `motherboard_fits_case` | error | ATX / Micro ATX / Mini ITX support |
| `gpu_fits_case` | error | card length <= case maximum |
| `cooler_fits_case` | error | air cooler height <= case maximum (liquid coolers skipped) |
| `cooler_supports_cpu_socket` | error | mounting kit supports the CPU socket |
| `cooler_cools_cpu` | warning | cooler rating >= CPU TDP |
| `psu_covers_estimated_load` | error | PSU >= CPU + GPU + 80 W |
| `psu_has_headroom` | warning | PSU >= estimate + 30%, rounded up to 50 W (e.g. 420 W -> 550 W) |
| `psu_meets_gpu_recommendation` | warning | PSU >= the GPU maker's recommendation |
| `psu_fits_case` | error | ATX PSU in an SFX-only case |
| `storage_fits_m2_slots` | error | M.2 drives <= board M.2 slots |
| `cpu_needs_graphics_card` / `cpu_cooler_required` | warning | CPU without iGPU / without a bundled cooler, and the slot is empty |

- **Rules are data-tolerant:** a rule that is missing a part or a spec stays silent instead of guessing. A contract test
  fails if a rule reads a spec key that the catalog does not define, so renaming a spec cannot quietly disable a rule.
- **Adding a rule:** write a class (extend `AbstractRule`), add it to `config/compatibility.php`, run
  `php artisan db:seed --class=CompatibilityRuleSeeder`. Rules are plain PHP objects and need no database to unit-test.
- **Admin control without a deployment:** the `compatibility_rules` table stores, per rule, on/off, severity and tunable
  numbers (`base_system_watts`, `headroom_percent`). Missing rows fall back to the defaults, so a forgotten seed never disables checks.

| Endpoint | Auth | Notes |
|---|---|---|
| `POST /api/pc-builder/validate` | public | Now also returns `compatibility: {status, issues[{rule, severity, message, slots}], metrics{estimated_power_watts, recommended_psu_watts}}` and a `purchasable` flag that includes compatibility |
| `GET /api/pc-builder/parts` | public | Smart filtering: `slot`, the usual product filters, and the build so far as `parts[cpu][id]=5&parts[ram][id]=12&parts[ram][qty]=2`. Parts come back **compatible, then warning, then incompatible**, each with its `compatibility` verdict. Incompatible parts are hidden unless `include_incompatible=1` |
| `GET /api/compatibility-rules` | `compatibility.manage` (admin) | Every rule with its settings and defaults |
| `PUT /api/compatibility-rules/{key}` | `compatibility.manage` | `is_enabled`, `severity`, `params`. Validated per rule, audit-logged |

Smart filtering judges only the candidate: a problem that already exists between two other parts is not blamed on every
candidate, and "missing part" rules (no iGPU, no cooler) are skipped because a single part cannot be blamed for a slot that is still empty.

## Cart (Phase 5)

One cart per customer (guests are sent to log in). A cart has two kinds of line:

- **Product line**: one product and a quantity. Adding the same product again increases the quantity (a database
  index guarantees one line per product, even under concurrent requests).
- **Build line**: a whole PC. When it is added, the server stores a **snapshot** of its parts and their prices
  (`cart_item_components`), so the line keeps meaning what it meant even if the saved build is edited or deleted.

**The browser is never trusted for money, stock or compatibility.** `CartEvaluator` re-prices and re-validates the
whole cart from the database on every read, and the cart responses always return the complete recalculated cart:

| Check | Result |
|---|---|
| Price | Always the **current** price. The price stored at add time is only used to warn "the price went up from X to Y". A customer can accept the new prices; they never pay a stale one |
| Stock | Counted across **all lines**, including parts inside builds, so one graphics card cannot be sold twice. Not reserved by the cart (reservation happens at checkout, under a row lock) |
| Withdrawn / out-of-stock products | A red error on the line, excluded from the total, `checkout_ready = false` |
| Builds | Must be **complete, in stock and free of compatibility errors** to be added, and are checked again every time the cart is read (specs or rule settings may have changed). Warnings never block |
| Limits | 10 of one product, 5 copies of one PC, 30 lines |

| Endpoint | Notes |
|---|---|
| `GET /api/cart` | The priced, validated cart: lines, `subtotal`, `item_count`, `checkout_ready`, `has_price_changes` |
| `POST /api/cart/products` | `{product_id, quantity}` |
| `POST /api/cart/builds` | Either `{build_id}` (a saved build; others' builds are a 404) or `{items: [{slot, product_id, quantity}], name}` (the parts in the builder). Refused with the reasons (`errors.build[]`) if incomplete, out of stock or incompatible |
| `PUT /api/cart/items/{id}` | `{quantity}`, re-checked against stock |
| `DELETE /api/cart/items/{id}` · `DELETE /api/cart` | Remove a line / clear |
| `POST /api/cart/accept-prices` | Re-snapshot prices so the warnings stop |

A cart line belongs to one customer: anyone else gets a `404`.

## Checkout and orders (Phase 6)

`CheckoutService` turns the cart into an order **inside one database transaction**: either the whole order exists, or
nothing changed. The browser only chooses (a saved address, a coupon code, an optional note). There is deliberately no
field for a price: everything is calculated by the server from the cart as it is at that moment.

1. Lock the customer's cart row (a second click waits, then finds the first order).
2. Re-evaluate the cart: current prices, stock across all lines, withdrawn products, PC compatibility.
3. Validate the coupon again (locking the coupon row so its last redemption cannot be used twice).
4. Calculate shipping from the delivery address (West Malaysia RM 15, Sabah/Sarawak/Labuan RM 35, free from RM 3,000 after discounts; see `config/shop.php`).
5. **Reserve stock** (`inventories.reserved`) under `SELECT ... FOR UPDATE`, locking rows in product-id order so two checkouts cannot deadlock. Not enough of anything refuses the whole order. A `CHECK (reserved <= stock)` is the last line of defence.
6. Write the order and one line per product, with the **name, SKU and price as they were**. The parts of a PC share a `build_group`.
7. Empty the cart.

| Rule | How it is enforced |
|---|---|
| Totals add up | Money is integer sen; a database `CHECK` refuses an order where `total != subtotal - discount + shipping` |
| No double orders | `POST /api/checkout` accepts an `Idempotency-Key` header; the same key returns the same order (`200`, not `201`) |
| No overselling | Row locks plus the `reserved <= stock` constraint; the cart does not hold stock, the order does |
| Orders are private | Anyone but the owner gets a `404` |
| Two lifecycles | `orders.status` (what is happening to the goods) and `orders.payment_status` (the money) are separate columns; `OrderStatus::nextStatuses()` lists the only allowed moves |
| Unpaid orders do not hold stock forever | They expire after 30 minutes. The `scheduler` container runs `orders:expire-unpaid` every minute, which cancels them and gives the stock and the coupon back, exactly once |
| Order numbers | `ORD-2026-00001`, one atomic counter per year (no repeats, no gaps) |

| Endpoint | Notes |
|---|---|
| `GET/POST /api/addresses` · `PUT/DELETE /api/addresses/{id}` | Address book (max 10, exactly one default, Malaysian states and 5-digit postcodes) |
| `POST /api/checkout/preview` | `{address_id?, coupon_code?}`: what the order would cost. Saves nothing |
| `POST /api/checkout` | `{address_id, coupon_code?, notes?}` + `Idempotency-Key` header. Refusals list their reasons (`errors.cart[]`, `errors.coupon_code`) |
| `GET /api/orders` · `GET /api/orders/{id}` | My orders; one order with its lines grouped (a PC is one line with its parts) |
| `POST /api/orders/{id}/cancel` | Only while unpaid. Releases stock and the coupon |

Demo coupons (local only): `WELCOME10` (10% off orders over RM 1,000, once per customer) and `SAVE50` (RM 50 off orders over RM 500).
Payment itself arrives in the next phase: until then an order waits for payment and is cancelled automatically when its window ends.

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
