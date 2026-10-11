# The catalog, the PC builder and the compatibility engine

> The exact request and response of every endpoint is in the API reference ([`/docs/api`](http://localhost:8000/docs/api) when the
> app is running, or [`backend/openapi.json`](../backend/openapi.json)), which is generated from the code and tested against
> real answers. This page explains how the feature works and why.

## Catalog

Every category describes its own specifications as **data** (`spec_definitions`: key, label, type, unit, allowed options,
whether it is a filter, whether it shows on product cards), and each product stores one typed value per specification
(`product_specifications`). The shop's filters, the product page's table, the admin form and the validation of a product
are all generated from those rows. A new kind of part, or a new specification, needs rows, not code.

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

## PC builder

| Endpoint | Auth | Notes |
|---|---|---|
| `GET /api/pc-builder/components` | public | The slots (CPU, motherboard, ...) in screen order, whether each is required, its max quantity and the category that supplies it |
| `POST /api/pc-builder/validate` | public (throttled) | Body `{items: [{slot, product_id, quantity}]}`. Returns the build **recalculated from database prices and stock**: line totals, subtotal (sen), missing required slots and issues (`out_of_stock`, `insufficient_stock`, `product_unavailable`). Client prices are ignored. Products that are not on sale are never revealed |
| `GET/POST /api/builds` | customer | List (with totals) / save a build. Incomplete builds can be saved. Max 25 per customer |
| `GET/PUT/DELETE /api/builds/{id}` | owner only | Anyone else gets `404`. `PUT` can rename and/or replace the parts |
| `POST /api/builds/{id}/duplicate` | owner only | Creates "Copy of ..." |

A build stores only *which* products and how many. Prices are always read live, so a saved build never shows a stale price.

## Compatibility engine

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
| `POST /api/pc-builder/validate` | public | Also returns `compatibility: {status, issues[{rule, severity, message, slots}], metrics{estimated_power_watts, recommended_psu_watts}}` and a `purchasable` flag that includes compatibility |
| `GET /api/pc-builder/parts` | public | Smart filtering: `slot`, the usual product filters, and the build so far as `parts[cpu][id]=5&parts[ram][id]=12&parts[ram][qty]=2`. Parts come back **compatible, then warning, then incompatible**, each with its `compatibility` verdict. Incompatible parts are hidden unless `include_incompatible=1` |
| `GET /api/compatibility-rules` | `compatibility.manage` (admin) | Every rule with its settings and defaults |
| `PUT /api/compatibility-rules/{key}` | `compatibility.manage` | `is_enabled`, `severity`, `params`. Validated per rule, audit-logged |

Smart filtering judges only the candidate: a problem that already exists between two other parts is not blamed on every
candidate, and "missing part" rules (no iGPU, no cooler) are skipped because a single part cannot be blamed for a slot that is still empty.
