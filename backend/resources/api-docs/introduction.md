The API behind HighTech Store: a PC hardware shop with a compatibility-checked PC builder, cart and checkout, payments
with idempotent webhooks, refunds, reconciliation and an admin area.

Every address below is relative to `/api`. Requests and answers are JSON (`Accept: application/json`).

## Logging in

The API uses a **cookie session** (Laravel Sanctum's SPA mode), not tokens. A browser on the shop's own website does all
of this by itself; another client has to keep cookies and follow three steps:

1. `GET /sanctum/csrf-cookie` (note: not under `/api`). It sets two cookies: the session and `XSRF-TOKEN`.
2. `POST /api/auth/login` with the email and password.
3. Send the cookies with every later request.

Every request that changes something (`POST`, `PUT`, `DELETE`) must also carry the header `X-XSRF-TOKEN`, holding the
URL-decoded value of the `XSRF-TOKEN` cookie. Without it the answer is `419`. Requests are treated as logged-in only when
their `Origin` or `Referer` is the shop's own website.

Endpoints marked with a lock need a logged-in user. Where a **permission** is named, the user's role must hold it:
customers have none of them, staff hold the day-to-day ones, admins hold all.

## Money

Money is never a decimal. An amount is a whole number of **sen** (RM 1 = 100 sen) and is returned as an object:

```json
{ "amount": 119900, "currency": "MYR", "formatted": "RM 1,199.00" }
```

Amounts sent to the API (a product's price, a refund) are whole numbers of sen too. Prices, totals, discounts and
shipping are always worked out by the server from its own data: nothing a client sends can change what an order costs.

## Identifiers

Catalog records (products, categories, brands) have whole-number ids, and products are also addressed by `slug`. Orders,
payments, refunds and saved builds have 26-character ids (ULIDs) that cannot be guessed or counted. An order also has a
number meant for people, such as `ORD-2026-00018`.

## Errors

Every error has the same shape. `errors` is present only when what was sent failed validation, with one list of
messages per field:

```json
{
  "message": "Graphics Card X is out of stock.",
  "errors": { "cart": ["Graphics Card X is out of stock."] }
}
```

| Status | Meaning |
|---|---|
| `401` | Not logged in |
| `403` | Logged in, but not allowed to do this (someone else's order, or a missing permission) |
| `404` | No such address or record. Another customer's record also answers `404` or `403`, never its contents |
| `405` | The address exists but not for this method |
| `419` | The session or its `X-XSRF-TOKEN` expired: fetch `/sanctum/csrf-cookie` again |
| `422` | What was sent is not acceptable; see `errors` |
| `429` | Too many requests; the `Retry-After` header says how many seconds to wait |
| `500`, `503` | A problem on the server. The answer never contains internal details |

## Lists

Lists are paged with `page` and `per_page`. The answer has the rows in `data`, and `meta` (`current_page`, `last_page`,
`per_page`, `total`) and `links` (`first`, `last`, `prev`, `next`) to move through them.

## Placing an order safely

`POST /checkout` accepts an `Idempotency-Key` header (8 to 64 letters, digits, `-` or `_`). Sending the same key again,
for example after a timeout, returns the order that was already created (`200`) instead of making a second one (`201`).

## Payments and webhooks

An order is paid on the payment company's own page: `POST /orders/{order}/payments` returns the `checkout_url` to send
the customer to. The company then tells the shop what happened by calling `POST /webhooks/{provider}`. That call is the
only thing that marks an order paid; the page the customer returns to is never trusted.

A webhook is accepted only with a valid signature: a header holding `t=<unix time>,v1=<signature>`, where the
signature is HMAC-SHA256 of `<unix time>.<raw body>` with a secret shared between the shop and the company, and the
time is at most five minutes old. Each event is processed once: a repeated delivery is answered `200` with
`{"status": "duplicate"}` and changes nothing.

The built-in gateway is **FakePay**, a simulator for development and demos (its header is `x-fakepay-signature`). It is
switched off when the shop runs in production mode.
