# Hosting the shop for free

This guide puts HighTech Store on the internet for **free**, using three free services. Read part 1 first: it explains
*why* three, and what the free plans cannot do.

**What was tested, and what was not.** The API container (`backend/Dockerfile`) was built and run on a computer against a
throwaway PostgreSQL database with the same settings you will give Render. It migrated the database, created the admin, added
the demo shop, started nginx, PHP, the queue worker and the scheduler (about 125 MB of memory, well inside Render's free
512 MB), let a customer register, check out and pay on FakePay, delivered the payment notice through its own queue, and ended
with the order PAID and the reconciliation page MATCHED. Restarting it changed nothing, and with `APP_ENV=production` the
payment simulator switched itself off. Netlify's own parser reads `netlify.toml` as intended. **What could not be tested from
here** is the dashboards of Neon, Render and Netlify themselves (they are not under our control), so a button may be labelled
slightly differently when you get there, and the forwarding from Netlify to Render has not run for real until you deploy.

## 1. The big picture

Netlify is excellent, but it can only serve **finished web pages** (and small JavaScript functions). Your shop also needs a
program that runs PHP (Laravel), a PostgreSQL database, a queue worker (sends emails, delivers payment notices) and a
scheduler (cancels unpaid orders when their time is up). Netlify cannot run those, so:

```
   a visitor's browser
          │
          ▼
   NETLIFY   the Vue website (pages, buttons)              free
          │   forwards every /api/... call
          ▼
   RENDER    the Laravel API + payment simulator +          free, but it goes to sleep after
             queue worker + scheduler (one container)       15 minutes without visitors
          │
          ▼
   NEON      the PostgreSQL database                        free, 1 GB
```

Why does Netlify *forward* the API calls instead of the browser calling Render directly? Your login uses a cookie. A browser
only keeps a login cookie happily if the website and the API share one address. Forwarding makes the browser believe it is
talking to a single site, exactly as it does on your own computer with the Vite dev server.

### What "free" really means (checked October 2026; plans change, always look at each site's pricing page)

| Service | Free plan | What to expect |
|---|---|---|
| **Netlify** | A monthly pool of 300 credits. Each production deploy costs 15, bandwidth costs 20 per GB, and requests and functions draw from the same pool | Plenty for a portfolio demo (about 20 deploys a month). Heavy traffic could pause the site until next month |
| **Render** | A free web service with 750 instance hours a month | It **sleeps after 15 minutes** without a visitor and needs about **a minute to wake up** |
| **Neon** | 1 GB of PostgreSQL per project, 100 compute-hours a month | The database sleeps after 5 minutes idle and wakes in about a second. Data is kept |

Render also offers a free database, but it **expires after 30 days**, which is why we use Neon for the database.

Sources: [Netlify's free plan](https://netli.fyi/blog/netlify-free-plan-limits-2026), [Render's free tier](https://render.com/articles/platforms-with-a-real-free-tier-for-developers-in-2026), [Neon's plans](https://neon.com/docs/introduction/plans).

### Things you must know before you start

1. **The first visit after a quiet period is slow** (the API wakes up, about a minute). Netlify gives up on a forwarded request
   after about 26 seconds, so the very first request may show an error. Refresh and it works. Part 8 shows how to avoid it.
2. **Photos you upload in the admin are lost** when the free Render service restarts: its disk is temporary. Until photos move to
   cloud storage (a small planned change), do not rely on uploaded photos.
3. **This is a demo shop with the payment *simulator*.** It runs with `APP_ENV=staging` on purpose: the simulator refuses to run
   in `production`, so that nobody can fake a payment on a real shop. Never put real payment keys on this deployment.
4. **Emails** are written to the log (`MAIL_MAILER=log`) unless you connect a mail service (part 9).

## 2. Accounts you need

- **GitHub** (github.com): where the code goes, so Render and Netlify can read it.
- **Neon** (neon.com), **Render** (render.com), **Netlify** (netlify.com): choose "Sign up with GitHub" on each.

You should not need to enter a card for any free plan. If a site insists on one, stop and check you picked the free plan.

## 3. Put the code on GitHub

Your code is only on your computer so far. On github.com create a **new empty repository** (name it e.g. `hightech-store`, choose
**Private**; do not add a README). Then in the project folder:

```bash
git remote add origin https://github.com/YOUR-NAME/hightech-store.git
git push -u origin main
```

It is safe: your `.env` files (passwords) are in `.gitignore`, and only the `.env.example` templates are committed.

## 4. The database (Neon)

1. In Neon: **Create project**. Name it, choose the region **Singapore** (closest to Malaysia), Postgres version 17.
2. On the project's dashboard click **Connect**. Turn **off** "Connection pooling" (the **direct** connection is the one we
   want) and copy the connection string. It looks like
   `postgresql://USER:PASSWORD@ep-something.ap-southeast-1.aws.neon.tech/neondb?sslmode=require`.
3. Keep it somewhere private for the next step. This is your `DB_URL`.

Nothing else to do: the API creates all the tables itself on its first start.

## 5. The API (Render)

First make two secrets on your computer:

```bash
docker compose exec app php artisan key:generate --show      # prints  base64:...   (this is APP_KEY)
openssl rand -hex 32                                         # prints a random string (this is PAYMENT_WEBHOOK_SECRET)
```

Then in Render: **New + → Web Service → Git provider → pick your repository**, and fill in:

| Field | Value |
|---|---|
| Name | something short, e.g. `hightech-api`. Your API address will be `https://hightech-api.onrender.com` |
| Region | Singapore |
| Branch | `main` |
| **Root Directory** | `backend` |
| Runtime / Language | **Docker** (Render finds `backend/Dockerfile` by itself) |
| Instance type | **Free** |
| Health Check Path (under Advanced) | `/up` |

Open **Environment Variables** and add these. Lines marked *later* are set after the website exists (part 6); put a placeholder now.

| Variable | Value |
|---|---|
| `APP_ENV` | `staging` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | the `base64:...` from above |
| `APP_URL` | `https://hightech-api.onrender.com` (your API address) |
| `FRONTEND_URL` | *later*: your Netlify address, e.g. `https://hightech-store.netlify.app` |
| `SANCTUM_STATEFUL_DOMAINS` | *later*: the same address **without** `https://`, e.g. `hightech-store.netlify.app` |
| `SESSION_SECURE_COOKIE` | `true` |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | the Neon connection string |
| `LOG_CHANNEL` | `stderr` |
| `PAYMENT_PROVIDER` | `fake` |
| `PAYMENT_WEBHOOK_SECRET` | the random string from above |
| `MAIL_MAILER` | `log` |
| `ADMIN_EMAIL` | the email you will sign in to the admin with |
| `ADMIN_PASSWORD` | a **long** password (at least 12 characters) that you have not used anywhere else |
| `SEED_DEMO_CATALOG` | `true` (adds the demo products the first time, only into an empty shop) |

Click **Create Web Service**. Render builds the image (the first build takes several minutes) and starts it. Open the **Logs**
tab and watch for:

```
Roles, permissions and compatibility rules are in place.
Admin you@example.com created.
The demo catalog and coupons were added.
```

Then open `https://YOUR-API.onrender.com/up`. If it says the application is up, the API works. If the deploy fails, the log says
`STARTUP STOPPED:` followed by what is missing.

## 6. The website (Netlify)

1. Open the file `netlify.toml` in the project. Replace **`YOUR-API-NAME.onrender.com`** (three places) with your API's address,
   without `https://`. Save, then:

   ```bash
   git add netlify.toml && git commit -m "Point Netlify at the API" && git push
   ```

2. In Netlify: **Add new project → Import an existing project → GitHub →** your repository. Netlify reads `netlify.toml`, so
   the build settings are already filled in (base `frontend`, command `npm run build`, publish `dist`). Click **Deploy**.
3. When it is live you get an address like `https://random-name-123.netlify.app`. To choose a nicer one: **Project
   configuration → Change project name**.
4. Back in Render, **Environment**: set `FRONTEND_URL` to that address (with `https://`) and `SANCTUM_STATEFUL_DOMAINS` to the
   same without `https://`. Save: Render restarts the API by itself.

## 7. Check that it all works

Open your Netlify address (if it shows an error, wait a minute and refresh: the API was asleep).

- [ ] The shop shows products, with filters and search.
- [ ] **Sign up** with a new account, then log in and out. Reload a page: you should still be logged in.
- [ ] Add a part to the cart, check out, press **Pay**, and on the **FakePay** page choose **Pay successfully**. After a few seconds
      the order says **Paid**.
- [ ] Log in with `ADMIN_EMAIL` and open `/admin`. The dashboard should show your test order.
- [ ] In the admin, **Reconciliation** should show your payment as **MATCHED**.

## 8. Keep the API awake (optional)

Render's free API sleeps after 15 minutes. A free monitor that visits it every 5 minutes keeps it awake, so visitors never wait:

1. Create a free account at uptimerobot.com.
2. **Add new monitor** → type **HTTP(s)** → URL `https://YOUR-API.onrender.com/up` → interval **5 minutes**.

One always-awake service uses about 744 of your 750 free hours each month, so this is fine for a single API. It also keeps the
queue worker and scheduler running (unpaid orders are cancelled on time, lost payment notices are caught).

## 9. Real emails (optional)

With `MAIL_MAILER=log`, emails only appear in Render's log. To send real ones, use a free mail service such as Brevo or Resend,
create an SMTP login there, and set in Render: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
`MAIL_PASSWORD`, `MAIL_SCHEME` (`tls` or `smtps`, as the service says) and `MAIL_FROM_ADDRESS` (an address the service has
verified for you).

## 10. Updating the site later

```bash
git add -A && git commit -m "Describe the change" && git push
```

Netlify and Render both notice the push and redeploy by themselves. New database tables or columns are added automatically when the
API starts (it only ever **adds**; it never wipes your data). Check the Render log if the new version does not start.

## 11. When something goes wrong

| What you see | Likely reason | What to do |
|---|---|---|
| The site shows an error on the first visit, then works | The API was asleep (Netlify gives up after about 26 s) | Refresh. Use part 8 to avoid it |
| You log in, then are logged out on the next click | The login cookie is not being kept | Check: `SANCTUM_STATEFUL_DOMAINS` is exactly the Netlify address without `https://`; `SESSION_SECURE_COOKIE=true`; the three `/api` `/sanctum` `/storage` rules are in `netlify.toml` and above the `/*` rule |
| "Page expired" (419) when logging in | Same family: the address in `SANCTUM_STATEFUL_DOMAINS` does not match the address in the browser | Fix the variable and let Render restart |
| Pay works but the order never says Paid | The queue worker could not deliver the payment notice | Look at Render's log for `webhooks/fake` lines. Do not set `PAYMENT_FAKE_WEBHOOK_URL` unless you know why |
| The FakePay page is "not found" | `APP_ENV` is `production` | Set `APP_ENV=staging` |
| Deploy fails with `STARTUP STOPPED` | A required setting is missing | Read the sentence after it |
| Deploy fails while talking to the database | Wrong `DB_URL`, or you copied the pooled connection | Copy the **direct** connection string again |
| Everything is slow after a quiet period | Neon or Render was asleep | Normal on free plans; part 8 helps with Render |

## 12. Other free routes

- **Cloudflare Pages** can host the website instead of Netlify, with no bandwidth cap on its free plan. The forwarding of `/api`
  works differently there (a small function), so the guide above is the straightforward one.
- If you would rather never wait for a sleeping API, a small paid server (a few US dollars a month) removes the sleeping, the lost
  photos and most of the limits. Everything in `backend/` (the image) works there unchanged.
