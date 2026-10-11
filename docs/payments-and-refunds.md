# Payments, webhooks, refunds and reconciliation

> The exact request and response of every endpoint is in the API reference ([`/docs/api`](http://localhost:8000/docs/api) when the
> app is running, or [`backend/openapi.json`](../backend/openapi.json)), which is generated from the code and tested against
> real answers. This page explains how the feature works and why.

## Payments

The shop only ever talks to a `PaymentGateway` **interface** (`createPayment`, `parseWebhook`, `fetchStatus`,
`refundPayment`, `fetchTransaction`). Each
payment company is one class that implements it, listed in `config/payments.php`; `PAYMENT_PROVIDER` picks which one is
used. The included gateway is **`fake`**, a local simulator (see below). No real gateway is included: adding one means
writing one class and one config line, and nothing else in the shop changes.

**The journey**

1. **Pay now** (`POST /api/orders/{id}/payments`, no body). The server creates a `payments` row for the order's **own
   total** and asks the gateway for a payment page. The browser never sends an amount. Asking again while a page is
   waiting returns the same one.
2. The customer pays on the gateway's page and is sent back to `/orders/{id}?payment=return`.
3. Meanwhile the gateway calls our **webhook** (`POST /api/webhooks/{provider}`). Only this can mark an order paid.
   Coming back to the site proves nothing, so the order page shows "Confirming your payment…" and asks the server every
   two seconds (for up to ~40 s) until the webhook has been processed.
4. When it is processed: the payment and the order become `PAID`, the **reserved stock becomes sold stock** (`stock` and
   `reserved` both go down, with a `sale` line in the stock history) and the customer gets an email.

**What keeps it safe**

| Risk | How it is handled |
|---|---|
| Someone forges a webhook | Every webhook carries `X-FakePay-Signature: t=<time>,v1=<HMAC-SHA256 of "time.body">` made with a shared secret (`PAYMENT_WEBHOOK_SECRET`). Wrong, missing or **older than 5 minutes** (replay) is refused with `401` before anything is read. Compared with `hash_equals` |
| The same webhook arrives twice (gateways retry) | Each event is written to `webhook_events` (**unique `provider + event_id`**) in the *same transaction* as its effects. The second delivery finds the row and does nothing (`200 {"status":"duplicate"}`) |
| Two events race, or arrive out of order | The order and then the payment are locked (always in that order, so no deadlock). Every move is checked against the payment's current state: a "failed" event after "paid" is ignored, a paid payment never goes back |
| The gateway reports a different amount | Not accepted. The payment stays unpaid, an `AMOUNT_MISMATCH` line and an audit entry are written |
| The processing crashes half way | The whole transaction rolls back, **including the "seen this event" row**, and the answer is `500`, so the gateway's retry is processed normally |
| Customer pays *after* the order was cancelled or expired | The money is recorded as paid and flagged `requires_refund`; the order is **not** revived (its stock may be sold to someone else) and stock is untouched. The customer is told it will be refunded (see refunds below) |
| Two payment pages for one order | A partial unique index allows only **one open payment per order** |
| History being rewritten | `payment_transactions` is the payment's diary; a **database trigger** refuses any `UPDATE` or `DELETE` on it |
| A webhook never arrives | `payments:reconcile` (every 5 minutes, run by the `scheduler`) asks the gateway about undecided payments, and recently closed ones, and feeds its answer through the same processor |
| Rate limits | Each limited route has its own named counter (`throttle:20,1,checkout`). Without a name Laravel shares one counter per customer across **all** such routes |

**The fake gateway ("FakePay")** plays the part of the payment company, locally and with no real money:

- `createPayment` stores a pretend payment and returns a link to a payment page inside the Vue app (`/fake-gateway/{reference}`), styled differently from the shop on purpose.
- The page offers **Pay successfully**, **Simulate a declined payment** and **Cancel and go back**.
- The choice is recorded by the "company" (`/api/fake-gateway/...`), and then a queued job (`DeliverFakeWebhook`, run by the `queue` container) **POSTs a signed webhook to the shop**, with retries and the same event id each time. So the whole flow is real HTTP, a real signature check and a real queue.
- It is refused (`404`) when `APP_ENV=production`, whatever the settings say.

| Setting (`backend/.env`) | Meaning |
|---|---|
| `PAYMENT_PROVIDER` | Which gateway new payments use (`fake`) |
| `PAYMENT_WEBHOOK_SECRET` | Shared secret for signing/checking webhooks. Any value for `fake`; a real gateway gives you one |
| `PAYMENT_FAKE_WEBHOOK_URL` | Where the simulator delivers (inside Docker: `http://app:8000/api/webhooks/fake`) |

Try it: place an order, press **Pay**, and choose an outcome. Emails appear in Mailpit (http://localhost:8025).

| Endpoint | Notes |
|---|---|
| `POST /api/orders/{id}/payments` | Start (or resume) paying. `201` new, `200` the one already waiting, `422` with the reason otherwise |
| `POST /api/webhooks/{provider}` | Called by the gateway. No login; the signature is the proof. Always `200` for anything that is not an error |
| `GET/POST /api/fake-gateway/payments/{reference}[/complete]` | The simulator's payment page (development only) |

## Order management and refunds

Who may do what is decided by permissions, not by hard-coded roles:

| Permission | Who has it | What it allows |
|---|---|---|
| `orders.manage` | staff and admins | See every order, move it through fulfilment, add the shipment |
| `refunds.manage` | **admins only** | Decide refunds, cancel a paid order, refund an amount. Moving money needs more trust than packing a box |
| `payments.view` | admins only | See the payment company's own reference for a payment |

**Getting an order to the customer.** Each step is strictly the next one: `PAID -> PROCESSING -> PACKED -> SHIPPED -> DELIVERED`.
Skipping a step, going back, or touching an order that is unpaid, cancelled or has an open refund request is refused (`422`).
Shipping records the carrier and tracking number and emails the customer. Every status change of any order, whoever or
whatever caused it, is written to `order_status_histories` by an **observer**, so no code path can forget. That is the
customer's timeline, and it lets a declined refund put the order back to *exactly* the status it had.

**Refunds, from the customer's side.** On a paid order the customer can ask for their money back (`POST /api/orders/{id}/refund-request`, a
reason is required). Nothing is paid out yet; the order shows "Refund requested" and fulfilment stops. An admin then approves
or declines it. A delivered order can be refunded for 14 days after delivery (`config/shop.php`).

| Endpoint | Permission | Notes |
|---|---|---|
| `GET /api/admin/orders` | `orders.manage` | Filters: `status`, `payment_status`, `q` (order number, email or name). 20 per page |
| `GET /api/admin/orders/{id}` | `orders.manage` | Everything: customer, every payment, refunds, shipment, timeline |
| `POST /api/admin/orders/{id}/status` | `orders.manage` | `{status: PROCESSING \| PACKED}` |
| `POST /api/admin/orders/{id}/shipment` | `orders.manage` | `{carrier, tracking_number}`: PACKED -> SHIPPED |
| `POST /api/admin/orders/{id}/shipment/delivered` | `orders.manage` | SHIPPED -> DELIVERED |
| `GET /api/admin/refunds?status=REQUESTED` | `refunds.manage` | The queue of customer requests |
| `POST /api/admin/refunds/{id}/approve` | `refunds.manage` | `{restock?, note?}` |
| `POST /api/admin/refunds/{id}/reject` | `refunds.manage` | `{note}` (the customer is told why) |
| `POST /api/admin/refunds/{id}/retry` | `refunds.manage` | For a refund the payment company did not complete |
| `POST /api/admin/orders/{id}/cancel-and-refund` | `refunds.manage` | Paid and not yet shipped: refunds in full, cancels, puts the goods back |
| `POST /api/admin/payments/{id}/refunds` | `refunds.manage` | `{amount (sen), reason: goodwill \| late_payment, note?}` |
| `GET /api/admin/payments/needing-refund` | `refunds.manage` | Payments that arrived after their order was cancelled |

Actions that call the payment company answer `200` with the refund even when the company refused: it then has the status
`FAILED` and a `failure_reason`, and can be retried.

**What keeps the money safe**

| Risk | How it is handled |
|---|---|
| Refunding more than was paid | `payments.refunded_amount` is capped by a database `CHECK`, and the amount that can still be refunded **includes refunds that are still being sent**, so two at once cannot add up to more |
| Refunding twice (a timeout, a double click, a retry) | The refund is `PROCESSING` before the gateway is called, only one approval can win (row locks), and the gateway receives the refund's id as an **idempotency key**: asking again returns the first refund. Tested with a gateway that refunds and then "loses" its answer |
| A slow gateway freezing the shop | The gateway call is outside the database transaction; the result is written in a second one |
| Half-finished changes | If the gateway says no, the order, payment and stock are untouched and the refund is `FAILED`. A retry finishes the job exactly once |
| Stock going back twice, or when it should not | Goods return to the shelf only for a refund that covers the **whole order**, and only if it never shipped (or staff confirm the parcel came back). It is a `return` line in the product's stock history |
| One request waiting per order | A partial unique index |
| Who did it | Every approval, rejection, shipment and status change is in the audit log |

Late payments are refunded from the same screen: a payment that arrived after its order was cancelled is flagged `requires_refund`
and appears in `payments/needing-refund`; refunding it in full clears the flag without reopening the order.

The fake gateway implements refunds too (`fake_gateway_refunds`, with the idempotency key as a unique column). Real gateways
can answer a refund with "pending" and confirm later by webhook; only the immediate answer is handled today.

## Payment reconciliation

*Do our books and the payment company's agree?* For every payment sent to a gateway in the chosen period (up to 31 days)
the shop asks the gateway for **its own record** (what it received, the state, what it refunded) and compares it with ours
and with the order. Each disagreement is listed in words. A payment that agrees is `MATCHED`; any difference is a
`RECONCILIATION ERROR`. The summary shows what the company received against what we recorded, like a bank reconciliation.

| It notices | Example | What can be done |
|---|---|---|
| Different amounts | Gateway received RM 4,602.00, the payment is for RM 4,502.00 | Nothing automatic: a person must look. The shop never accepts a wrong amount |
| A missed payment notice | Gateway says paid, we say waiting | **Ask the payment company again** applies its answer through the same idempotent processor a webhook uses |
| A failure or cancellation we never heard about | Gateway says failed, we say waiting | Same button |
| We say paid, the gateway disagrees | | A person (nothing is changed behind anyone's back) |
| Refunds on one side only | Gateway refunded RM 70.00, we have none | A person |
| No record, or the gateway cannot be reached | | Reported, not hidden |
| Our own books disagree | The payment is not for the order's total | A person |

**To see an error yourself** (fake gateway, local): place an order, press **Pay**, and on the FakePay page open
**"Test: pay a different amount"**, enter less than was asked and pay. The shop refuses to treat the order as paid
(`AMOUNT_MISMATCH` in the payment's diary), and the Reconciliation page shows both amounts and the error.
