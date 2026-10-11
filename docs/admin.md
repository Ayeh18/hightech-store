# The admin area

> The exact request and response of every endpoint is in the API reference ([`/docs/api`](http://localhost:8000/docs/api) when the
> app is running, or [`backend/openapi.json`](../backend/openapi.json)), which is generated from the code and tested against
> real answers. This page explains how the feature works and why.

Sign in as a demo admin (local development only, see the demo accounts in the README) and open **/admin**. The menu shows only
what the signed-in person's permissions allow, and the API checks every request again, so hiding a link is a convenience,
never the protection.

| Page | Permission | What it is for |
|---|---|---|
| Dashboard | `reports.view` | Cards (sales, orders, pending payments, products, low stock, customers), sales and orders over time, top products, revenue by category, recent orders, low-stock products, recent and failed payments |
| Sales reports | `reports.view` | Any period up to a year, day by day, what sold, by category, and a **CSV download** |
| Insights | `reports.view` | Who is buying, where orders are lost, how payments go, whole PCs against single items, and how build suggestions are used ([below](#insights)) |
| Orders / Refunds | `orders.manage` / `refunds.manage` | Pack, ship, deliver, cancel and refund; the refund queue |
| Payments | `payments.view` | Every payment attempt and its **diary** (every step, never edited) |
| Reconciliation | `payments.view` | Our books against the payment company's ([details](payments-and-refunds.md#payment-reconciliation)) |
| Products | `products.manage` | Create and edit with the category's own specification boxes, photos, stock |
| Brands & categories | `brands.manage` / `categories.manage` | Including each category's specification definitions, which drive the shop's filters and the PC builder |
| Compatibility rules | `compatibility.manage` | Switch a rule off, make it a warning, change its numbers |
| Coupons | `coupons.manage` | Percentage or fixed, minimum order, cap, dates, limits |
| Users | `users.manage` | Roles, and deactivating an account |
| Audit log | `audit.view` | Who did what, filterable. Read-only |

Staff (`orders.manage`, catalog, stock, reports) cannot see payments, refunds, users, coupons or the audit log.

**What "sales" means.** *Net sales* = money received minus money refunded, each counted on the day it happened, in
**Malaysian time** (`SHOP_TIMEZONE`, default `Asia/Kuala_Lumpur`): an order paid at 1 am on the 20th belongs to the 20th,
even though it is still the 19th in UTC. A payment that arrived too late for its order is held for a refund and is not a sale.

### Insights

The sales report says how much was sold. Insights is for the questions behind it, over any period up to a year, in the
same Malaysian days. Every figure is a count or a sum; no customer is named.

| Section | What it counts | Worth knowing |
|---|---|---|
| Customers | The people behind the orders **paid** in the period: how many, how many were buying for the first time, how many had bought before | An order cancelled or refunded afterwards does not make someone a buyer |
| From order to payment | What became of the orders **placed** in the period: paid, still waiting, or cancelled unpaid; and how many paid orders used a coupon | An order paid and refunded later still counts as paid here: the customer did pay |
| Payments | Every **attempt** to pay that started in the period, how it ended, why the failed ones failed and how people paid | An order paid on the second try is two attempts. The success rate is of the attempts that have finished |
| PCs from the builder | PCs sold against items sold on their own, the products chosen most for each slot, and the processor and graphics card pairs sold most | Amounts are the items' own prices, before coupons and shipping |
| Build suggestions | How often "Suggest a build for me" was used, what for, with what budget, and how many suggestions were loaded into the builder | With an AI switched on: how often its build was used and, by kind of reason, why the store's rules took over |

A percentage that cannot be worked out (the share of returning buyers when nobody bought) is shown as a dash, not as 0%.
Insights does not yet follow a suggestion through to a purchase: "loaded into the builder" is as far as it sees.

**Typing money and times.** Admins type ringgit (`1,599.00`) and percentages (`12.5`); the browser turns them into whole sen
and basis points from the digits as text, never through floating-point, so `19.99` can never become `1998.9999`. Coupon
dates are entered in Malaysian time and sent as exact moments.

### Guard rails

- Nobody can change **their own roles** or deactivate themselves, and the **last active admin** can never lose the role or be deactivated (so the shop cannot lock itself out).
- Deactivating a user **signs them out immediately** (their sessions are removed, and a middleware that runs *before* authentication refuses their next request even if a session is still open).
- A coupon that has been used on an order **cannot be deleted**, only switched off, because past orders refer to it.
- A refund request must be decided before any smaller refund is made on the same payment.
- Specification keys and types cannot change once created, because products already hold values under them.

### Adding a product (and its photos)

**Products -> New product.** Choose the category first: the specification boxes below it come from that category's definitions.
Save, then add photos (JPG/PNG/WebP, up to 8, re-saved as safe WebP) and check the stock on the same page.

**Where photos are kept** is one setting, `IMAGE_DISK`. `public` (the default) is the server's own disk
(`storage/app/public`), which suits development. `s3` is any S3-compatible store (Cloudflare R2, Backblaze B2, Supabase
Storage, AWS S3) configured with the usual `AWS_*` variables; customers then load photos straight from the bucket's public
address (`AWS_URL`), with a one-year cache header since a photo's random name is never reused. The database holds only each
photo's path, so moving is a copy: `php artisan shop:copy-images public s3` (it never deletes, and can be re-run). A failed
upload returns 503 and records nothing; a photo's file is removed only after its row is gone. `shop:install` checks the
store at start-up (missing settings stop the deploy; an unreachable store only warns).

### Notifications

Customers are emailed when an order is **placed**, **paid**, **shipped**, **delivered**, when a payment **fails**, and when a
refund is **approved or declined**. People who hold `inventory.manage` are emailed when a product **drops to its alert level**
(only the moment it crosses, not on every sale). All are queued and sent after the database change is safely committed.

| Endpoint (all under `/api/admin`) | Permission |
|---|---|
| `GET dashboard?days=7\|30\|90` | `reports.view` |
| `GET reports/sales?from&to` · `GET reports/sales/export?from&to` (CSV) | `reports.view` |
| `GET reports/insights?from&to` | `reports.view` |
| `GET payments` · `GET payments/{id}` | `payments.view` |
| `GET reconciliation?from&to` · `POST payments/{id}/sync` | `payments.view` |
| `GET users` · `GET users/{id}` · `PUT users/{id}/roles` · `PUT users/{id}/status` | `users.manage` |
| `GET audit-logs` · `GET audit-logs/actions` | `audit.view` |
| `GET/POST coupons` · `PUT/DELETE coupons/{id}` | `coupons.manage` |
