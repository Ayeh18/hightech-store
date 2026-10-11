# The cart, checkout and orders

> The exact request and response of every endpoint is in the API reference ([`/docs/api`](http://localhost:8000/docs/api) when the
> app is running, or [`backend/openapi.json`](../backend/openapi.json)), which is generated from the code and tested against
> real answers. This page explains how the feature works and why.

## Cart

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

## Checkout and orders

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
An order waits for payment ([payments and refunds](payments-and-refunds.md)) and is cancelled automatically when its 30-minute window ends.

## Proved under real concurrency

The locks above are not only reasoned about. `backend/tests/Concurrency` starts real, separate PHP processes and releases
them at the same instant: eight customers checking out a product with three left produce exactly three orders and five
clean "out of stock" refusals; one customer's checkout sent five times at once produces one order. Removing the inventory
row lock, or the cart lock, makes a test fail.
