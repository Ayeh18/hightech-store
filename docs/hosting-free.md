# Hosting the shop for free

This guide puts HighTech Store on the internet for **free**, using three free services. Read part 1 first: it explains
*why* three, and what the free plans cannot do.

**What was tested, and what was not.** The API container (`backend/Dockerfile`) was built and run on a computer with the
same settings you will give Render, against **Supabase's own database and storage programs** (the open-source versions,
PostgreSQL 17.11 and storage 1.81, run locally). As Supabase's ordinary, non-administrator database user it created the
`shop` schema and every table inside it, created the admin, added the demo shop, and started nginx, PHP, the queue worker and
the scheduler (about 140 MB of memory, well inside Render's free 512 MB). A customer registered, checked out and paid on
FakePay; the payment notice was delivered through the container's own queue and the order ended PAID, with the reconciliation
page MATCHED. A photo uploaded in the admin was stored through Supabase's S3 service, served from its public address,
survived the container being thrown away and recreated, and was removed when deleted. Restarting changed nothing, a missing
or dangerous setting stopped the start-up with a plain message, and with `APP_ENV=production` the payment simulator switched
itself off. Netlify's own parser reads `netlify.toml` as intended.

**What could not be tested from here** is the hosted services themselves: the dashboards of Supabase, Render and Netlify
(a button may be labelled slightly differently when you get there), Supabase's "session pooler" address that Render connects
through, and the forwarding from Netlify to Render. Those run for real only when you deploy; part 11 covers what to do if
one of them complains. The optional AI in part 14 was tested against a stand-in, not a real AI company's service.

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
   SUPABASE  the PostgreSQL database                        free, no card: 500 MB database,
             + the product photos                           1 GB of photos
```

Why does Netlify *forward* the API calls instead of the browser calling Render directly? Your login uses a cookie. A browser
only keeps a login cookie happily if the website and the API share one address. Forwarding makes the browser believe it is
talking to a single site, exactly as it does on your own computer with the Vite dev server.

### What "free" really means (checked October 2026; plans change, always look at each site's pricing page)

| Service | Free plan | What to expect |
|---|---|---|
| **Netlify** | A monthly pool of 300 credits. Each production deploy costs 15, bandwidth costs 20 per GB, and requests and functions draw from the same pool | Plenty for a portfolio demo (about 20 deploys a month). Heavy traffic could pause the site until next month |
| **Render** | A free web service with 750 instance hours a month | It **sleeps after 15 minutes** without a visitor and needs about **a minute to wake up** |
| **Supabase** | 500 MB of PostgreSQL, 1 GB of files, 5 GB of downloads a month, 2 projects. No card | A project with **no database activity for a week is paused** (you get a warning email first) and everything stops until you press Resume. Part 8 prevents it |

Render also offers a free database, but it **expires after 30 days**, which is why the database lives elsewhere. Supabase is
used here because one free account, without a card, holds both the database and the photos. Neon (database only) and
Cloudflare R2 (photos only) are alternatives; see the end of part 4 and part 12.

Sources: [Netlify's free plan](https://netli.fyi/blog/netlify-free-plan-limits-2026), [Render's free tier](https://render.com/articles/platforms-with-a-real-free-tier-for-developers-in-2026), [Supabase pricing](https://supabase.com/pricing), [Supabase project pausing](https://supabase.com/docs/guides/platform/free-project-pausing).

### Things you must know before you start

1. **The first visit after a quiet period is slow** (the API wakes up, about a minute). Netlify gives up on a forwarded request
   after about 26 seconds, so the very first request may show an error. Refresh and it works. Part 8 shows how to avoid it.
2. **Photos must not be kept on Render**: its free disk is wiped on every restart. This guide keeps them in Supabase Storage
   instead (part 4), where they stay.
3. **Supabase pauses a free project after a week without database activity.** While the API is awake it uses the database
   every few seconds, so a shop that is kept awake (part 8) is never paused. If nobody visits for a week and you skipped
   part 8, the shop stops until you open Supabase and press **Resume project**; nothing is lost.
4. **This is a demo shop with the payment *simulator*.** It runs with `APP_ENV=staging` on purpose: the simulator refuses to run
   in `production`, so that nobody can fake a payment on a real shop. Never put real payment keys on this deployment.
5. **Emails** are written to the log (`MAIL_MAILER=log`) unless you connect a mail service (part 9).

## 2. Accounts you need

- **GitHub** (github.com): where the code goes, so Render and Netlify can read it.
- **Supabase** (supabase.com), **Render** (render.com), **Netlify** (netlify.com): choose "Sign up with GitHub" on each.

You should not need to enter a card for any free plan. If a site insists on one, stop and check you picked the free plan.

## 3. Put the code on GitHub

Your code is only on your computer so far. On github.com create a **new empty repository** (name it e.g. `hightech-store`, choose
**Private**; do not add a README). Then in the project folder:

```bash
git remote add origin https://github.com/YOUR-NAME/hightech-store.git
git push -u origin main
```

It is safe: your `.env` files (passwords) are in `.gitignore`, and only the `.env.example` templates are committed.

## 4. The database and the photos (Supabase)

One Supabase project gives you both. Write down each value as you go; they all go into Render in part 5.

**A. Create the project**

1. In Supabase: **New project**. Give it a name and choose the region **Southeast Asia (Singapore)**.
2. **Database password**: press **Generate a password** and copy it somewhere private. If you type your own, use only
   letters and numbers: characters such as `@ # ? &` break the connection address.
3. Create the project and wait a minute or two until it is ready.

**B. The database address (`DB_URL`)**

1. Press **Connect** at the top of the project page and find **Session pooler**. (Not "Direct connection": that one works
   only over IPv6, which Render's free service does not have. Not "Transaction pooler" either: Laravel needs features it
   lacks.)
2. Copy its address. It looks like
   `postgresql://postgres.abcdefghijkl:[YOUR-PASSWORD]@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres`.
3. Replace `[YOUR-PASSWORD]` (brackets included) with your database password and add `?sslmode=require` at the end. That
   is your `DB_URL`. The `abcdefghijkl` part is your **project ref**; you need it below.

**C. Keep the shop's tables private (`DB_SCHEMA=shop`)**

Supabase publishes everything in a database's `public` schema (a schema is a named group of tables) through a web API of
its own. A shop's tables hold customers, orders and password hashes, so they must not go there. Setting `DB_SCHEMA=shop`
in part 5 puts them in a schema called `shop`, which Supabase does not publish and which the API creates by itself. If you
forget, the API refuses to start and tells you, rather than quietly publishing your data.

As a second lock, since the shop never uses that web API: open **Integrations → Data API** (on some dashboards:
**Project Settings → Data API**) and switch **Enable Data API** off.

**D. The photo bucket**

1. Open **Storage**, press **New bucket**, name it `photos`, switch **Public bucket** on, and create it. (Public means
   anyone can *view* a photo by its address, which is what a shop needs. Only the shop's key can add or delete.)
2. Still in Storage, open **Settings** (the page is called **S3 Configuration** or **S3 Connection**). Switch the S3
   connection on if it is off. Copy the **Endpoint** and the **Region** shown there.
3. On the same page press **New access key**, name it, and copy the **Access key ID** and the **Secret access key** now:
   the secret is shown only once. This key can write to all your project's storage, so treat it like a password.

Nothing else to do: the API creates all the tables itself on its first start.

### Using Neon for the database instead

Neon also offers a free PostgreSQL database without a card, and it wakes up by itself instead of being paused after a
quiet week. Use it if you keep photos somewhere other than Supabase (part 12). In Neon: **Create project**, region
**Singapore**, then **Connect**, turn **off** "Connection pooling" and copy the connection string as your `DB_URL`. Leave
`DB_SCHEMA` out. Keeping the database on Neon while the photos are on Supabase is *not* recommended: Supabase judges a
project by its database activity, so a project used only for photos may be paused after a week even while customers are
looking at them.

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
| `DB_URL` | the Supabase **Session pooler** address from part 4B, ending in `?sslmode=require` |
| `DB_SCHEMA` | `shop` (part 4C; required on Supabase) |
| `LOG_CHANNEL` | `stderr` |
| `PAYMENT_PROVIDER` | `fake` |
| `PAYMENT_WEBHOOK_SECRET` | the random string from above |
| `MAIL_MAILER` | `log` |
| `ADMIN_EMAIL` | the email you will sign in to the admin with |
| `ADMIN_PASSWORD` | a **long** password (at least 12 characters) that you have not used anywhere else |
| `SEED_DEMO_CATALOG` | `true` (adds the demo products the first time, only into an empty shop) |

And these eight for the photos, with the values from part 4D:

| Variable | Value |
|---|---|
| `IMAGE_DISK` | `s3` |
| `AWS_ACCESS_KEY_ID` | the Access key ID |
| `AWS_SECRET_ACCESS_KEY` | the Secret access key |
| `AWS_DEFAULT_REGION` | the Region shown on the S3 page, e.g. `ap-southeast-1` |
| `AWS_BUCKET` | `photos` |
| `AWS_ENDPOINT` | the Endpoint shown on the S3 page: `https://YOUR-PROJECT-REF.storage.supabase.co/storage/v1/s3` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` |
| `AWS_URL` | `https://YOUR-PROJECT-REF.supabase.co/storage/v1/object/public/photos` (no slash at the end). This is the public address customers load photos from; note it has no `.storage` in it |

Click **Create Web Service**. Render builds the image (the first build takes several minutes) and starts it. Open the **Logs**
tab and watch for:

```
The shop's tables live in the "shop" schema.
Roles, permissions and compatibility rules are in place.
Admin you@example.com created.
Photo storage (s3) works: a test file was written and removed.
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
- [ ] In the admin, open **Products**, pick a product and add a photo. It should appear, and its address (right-click →
      copy image address) should start with `https://YOUR-PROJECT-REF.supabase.co/storage/`. Then restart the API from
      Render's **Manual Deploy** menu: the photo must still be there.
- [ ] In Supabase, **Table Editor** should show the shop's tables under the schema `shop`, and nothing of the shop's under
      `public`.

## 8. Keep the API awake (recommended)

Render's free API sleeps after 15 minutes. A free monitor that visits it every 5 minutes keeps it awake, so visitors never
wait. With Supabase it does a second job: an awake API uses the database every few seconds, which is exactly the "database
activity" that stops Supabase from pausing your project after a quiet week.

1. Create a free account at uptimerobot.com.
2. **Add new monitor** → type **HTTP(s)** → URL `https://YOUR-API.onrender.com/up` → interval **5 minutes**.

One always-awake service uses about 744 of your 750 free hours each month, so this is fine for a single API. It also keeps the
queue worker and scheduler running (unpaid orders are cancelled on time, lost payment notices are caught).

If you skip this and the project is paused one day: Supabase emails you a warning about a week before, and again when it
pauses. Open the Supabase dashboard, choose the project, press **Resume project**, wait a few minutes, then visit the shop.
Nothing is lost; a paused project can be resumed for a year.

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

The API's own reference is at `https://YOUR-API.onrender.com/docs/api` on this demo deployment (it is hidden when
`APP_ENV=production`).

### Deploy only what passed the checks (optional)

By default both hosts deploy every push, including one whose tests fail. The repository's checks
(`.github/workflows/ci.yml`: tests, code style, static analysis, and a trial start of the production container) run on
GitHub on every push. To let only checked code reach the hosts:

1. **Render**: in the service's **Settings**, set **Auto-Deploy** to **Off**. On the same page copy the **Deploy Hook**
   address.
2. **Netlify**: in **Project configuration → Build & deploy → Continuous deployment**, under **Build hooks**, add a
   build hook and copy its address. Then stop automatic builds for pushes (**Build settings → Stop builds**, or lock
   automatic publishing).
3. **GitHub**: in the repository, **Settings → Secrets and variables → Actions → New repository secret**. Add
   `RENDER_DEPLOY_HOOK_URL` and `NETLIFY_BUILD_HOOK_URL` with those two addresses.

From then on a push to `main` is deployed by the last job of the workflow, and only if every check before it passed.
A hook address lets anyone who has it start a deploy, so keep the two only as secrets.

## 11. When something goes wrong

| What you see | Likely reason | What to do |
|---|---|---|
| The site shows an error on the first visit, then works | The API was asleep (Netlify gives up after about 26 s) | Refresh. Use part 8 to avoid it |
| You log in, then are logged out on the next click | The login cookie is not being kept | Check: `SANCTUM_STATEFUL_DOMAINS` is exactly the Netlify address without `https://`; `SESSION_SECURE_COOKIE=true`; the three `/api` `/sanctum` `/storage` rules are in `netlify.toml` and above the `/*` rule |
| "Page expired" (419) when logging in | Same family: the address in `SANCTUM_STATEFUL_DOMAINS` does not match the address in the browser | Fix the variable and let Render restart |
| Pay works but the order never says Paid | The queue worker could not deliver the payment notice | Look at Render's log for `webhooks/fake` lines. Do not set `PAYMENT_FAKE_WEBHOOK_URL` unless you know why |
| The FakePay page is "not found" | `APP_ENV` is `production` | Set `APP_ENV=staging` |
| Deploy fails with `STARTUP STOPPED` | A required setting is missing | Read the sentence after it |
| Deploy stops with `the database settings were refused` and a line about Supabase publishing the `public` schema | `DB_SCHEMA` is missing or blank | Set `DB_SCHEMA=shop` (part 4C). Nothing was created, so nothing needs cleaning up |
| The log repeats `Database not ready`, then `could not be reached` | With Supabase: you copied **Direct connection** (IPv6 only) instead of **Session pooler**, left `[YOUR-PASSWORD]` in the address, or the password has a character like `@ # ? &` | Copy the Session pooler address again (part 4B). If the password is the problem, reset it under **Project Settings → Database** to letters and numbers only |
| The log says `prepared statement ... does not exist` | You copied the **Transaction pooler** address (port `6543`) | Use the Session pooler address (port `5432`) |
| The whole shop stops after a quiet week, and the log says the database cannot be reached | Supabase paused the project | Press **Resume project** in Supabase, then set up part 8 |
| Everything is slow after a quiet period | Render was asleep | Normal on the free plan; part 8 avoids it |
| Product photos disappear after a while | They are on Render's temporary disk: `IMAGE_DISK` is not `s3` | Part 4D and the photo variables in part 5 |
| Deploy fails with `IMAGE_DISK is "s3" but these settings are missing` | A storage variable is empty | Add the ones it names |
| The log says `Photo storage (s3) could not be written to` | Wrong key, secret, bucket name or endpoint | Compare each with the storage site. The shop still runs; only photo uploads fail |
| Upload works but the picture is broken | `AWS_URL` is not the bucket's **public** address, or the bucket is not public | Open one photo's address in a browser. With Supabase, `AWS_URL` must be `https://REF.supabase.co/storage/v1/object/public/photos` (not the S3 endpoint), and the bucket must have **Public bucket** on |
| Uploading a photo says it could not be saved | The key is wrong, or the bucket name in `AWS_BUCKET` does not exist | Render's log has the storage service's own reason, just above |

## 12. Photo storage: how it works, and other places to keep photos

Render's free disk is wiped on every restart and redeploy, so photos must live somewhere else: in a **bucket**, a folder on
a storage company's servers. The shop saves each photo there and shows it to customers straight from the bucket's own
address, which is also faster than sending it through Netlify and Render. The database keeps only each photo's path.

The shop speaks the "S3" language that nearly every storage company understands, so the company is chosen with settings
alone (`IMAGE_DISK=s3` and the `AWS_...` variables). Part 4 sets up Supabase. The free choices compared:

| Service | Free plan (checked October 2026) | The catch |
|---|---|---|
| **Supabase Storage** (used in this guide) | 1 GB of files, 5 GB of downloads a month, no card | The project is paused after a week without *database* activity, so keep the shop's database in the same project (part 4) and the API awake (part 8) |
| **Cloudflare R2** | 10 GB, and downloads are never charged | You must add a card or PayPal to switch R2 on. You pay nothing inside the free amounts, but it is not capped: going over is billed |
| **Backblaze B2** | 10 GB, no card to sign up | A *public* bucket (which photos need) requires a card for verification |

For scale: the shop re-saves every photo as WebP, at most 1600 pixels wide. The eight photos in the development shop came out
at 5 to 31 KB each; a large, detailed photo can reach a few hundred KB. Either way 1 GB holds thousands of photos.

Sources: [Supabase pricing](https://supabase.com/pricing), [Supabase and S3](https://supabase.com/docs/guides/storage/s3/compatibility), [R2 pricing](https://developers.cloudflare.com/r2/pricing/), [card needed for R2](https://community.cloudflare.com/t/why-using-r2-free-tier-involves-giving-card-info/945179), [Backblaze B2 pricing](https://www.backblaze.com/cloud-storage/pricing).

### With Cloudflare R2

Use this if you do not mind giving a card and want more room. Unlike Supabase and the other parts of this guide, R2 was
tested only against a general S3 test server, not against Cloudflare's own software.

1. Create a free account at cloudflare.com. In the dashboard open **R2 Object Storage** and switch it on (this is where it asks
   for the card).
2. **Create bucket.** Name it, for example `hightech-photos`. Leave the location on automatic.
3. Make it readable by everyone: open the bucket → **Settings** → **Public Development URL** → **Enable** (type `allow` to
   confirm). You get an address like `https://pub-1a2b3c4d.r2.dev`. That is your `AWS_URL`.
4. Make a key for the shop: back on the R2 page, **API Tokens → Manage → Create API token**. Choose **Object Read & Write**,
   limit it to your bucket, and create it. Copy the **Access Key ID** and the **Secret Access Key** now (the secret is shown
   only once), and the endpoint, which looks like `https://YOUR-ACCOUNT-ID.r2.cloudflarestorage.com`.
5. In Render → **Environment**, add:

| Variable | Value |
|---|---|
| `IMAGE_DISK` | `s3` |
| `AWS_ACCESS_KEY_ID` | the Access Key ID |
| `AWS_SECRET_ACCESS_KEY` | the Secret Access Key |
| `AWS_DEFAULT_REGION` | `auto` |
| `AWS_BUCKET` | `hightech-photos` (your bucket's name) |
| `AWS_ENDPOINT` | `https://YOUR-ACCOUNT-ID.r2.cloudflarestorage.com` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` |
| `AWS_URL` | `https://pub-1a2b3c4d.r2.dev` (the public address from step 3, no slash at the end) |

6. Save. Render restarts the API, and the log should say
   `Photo storage (s3) works: a test file was written and removed.` Then upload a photo in the admin (**Products → a product**)
   and check that it is still there after you restart the service from Render's **Manual Deploy** menu.

The `r2.dev` address is meant for demos: Cloudflare limits how fast it can be used and does not cache it at its edge. For a
real shop, connect a domain you own under the bucket's **Custom Domains** and put that in `AWS_URL` instead.

### With Backblaze B2

The same eight variables: `AWS_ENDPOINT` is `https://s3.YOUR-REGION.backblazeb2.com`, `AWS_DEFAULT_REGION` is the region
in that address (for example `us-west-004`), `AWS_USE_PATH_STYLE_ENDPOINT` is `true`, and `AWS_URL` is
`https://s3.YOUR-REGION.backblazeb2.com/YOUR-BUCKET`. Create the bucket as **public**, and make the key under
**Application Keys → Add a New Application Key**.

### Moving photos you already have

Photos already uploaded on a computer (for example your own development shop) can be copied into the bucket, keeping their
paths, with one command. It only copies, never deletes, and can be run again safely:

```bash
docker compose exec app php artisan shop:copy-images public s3
```

It needs the `AWS_...` variables in that computer's `backend/.env`. When it reports that every photo is on `s3`, set
`IMAGE_DISK=s3`.

### Good to know

- **Keep the secret key secret.** It lets its holder write to your bucket. It belongs only in Render's environment (and a
  local `.env`, which git ignores), never in the code or in `netlify.toml`.
- If the storage company is down, the shop keeps working: only uploading a new photo fails, with a message asking you to try
  again, and nothing half-saved is left behind.
- The demo products come without photos, so there is nothing to lose until you upload your own.

## 13. Other free routes

- **Cloudflare Pages** can host the website instead of Netlify, with no bandwidth cap on its free plan. The forwarding of `/api`
  works differently there (a small function), so the guide above is the straightforward one.
- If you would rather never wait for a sleeping API, a small paid server (a few US dollars a month) removes the sleeping, the lost
  photos and most of the limits. Everything in `backend/` (the image) works there unchanged.

## 14. Build suggestions by AI (optional)

"Suggest a build for me" in the PC builder already works on your deployment: the store's own rules choose the parts,
for free, with nothing to set up. This part is only for when you would like an AI to do the choosing. Skip it and
nothing is missing.

**How it behaves.** The AI is asked first. Whatever it answers is checked by the shop exactly like a customer's own
build: real products, in stock, compatible, and within the budget at the shop's prices. If the answer cannot be used, or
the AI service is slow, down or over its free allowance, the store's rules answer instead, so the customer always gets
a build. The AI is sent the budget, the purpose and a list of products. It is never sent anything about a customer.

### With Groq (free, no card)

1. Create an account at [console.groq.com](https://console.groq.com). Under **API Keys**, create a key and copy it: it is
   shown only once.
2. Choose a model from [their list](https://console.groq.com/docs/models). It must support "JSON mode" (the model's page
   says so). In October 2026 the list included `openai/gpt-oss-120b` (larger, better choices) and `openai/gpt-oss-20b`
   (smaller, faster). Names change, so copy the name exactly as the list shows it.
3. In Render, open your service, go to **Environment** and add:

   | Key | Value |
   |---|---|
   | `RECOMMENDER_DRIVER` | `ai` |
   | `AI_API_KEY` | the key from step 1 |
   | `AI_MODEL` | the model's name from step 2 |
   | `AI_DAILY_LIMIT` | `40` (explained below) |

4. Save. Render restarts the API. Open the PC builder and press **Suggest a build for me**. A new box, "Games you
   play", shows that the AI is switched on, and a result chosen by it is labelled "Chosen by AI, checked by the store".

The key is a password. Put it only in Render's Environment (or in `backend/.env` on your own computer), never in the
code and never in a message or a screenshot.

### What the free allowance means

Free AI plans limit **tokens** (pieces of words) per minute and per day, not only requests. One suggestion sends the
shop's shortlist of up to 64 products, which is roughly 4,000 to 5,000 tokens with the answer. Published figures for
Groq's free plan in October 2026 are about 8,000 tokens a minute and 200,000 a day for a large model, and 6,000 a minute
and 500,000 a day for a small one. That works out at **about one AI suggestion a minute, and around 40 a day on a large
model or 100 on a small one**. Your own account's **Limits** page shows the real numbers; they change.

Going over is harmless: Groq answers "too many requests" and the store's rules choose that build. `AI_DAILY_LIMIT` just
stops the shop asking once the day's allowance is probably gone, so set it a little under what your plan allows. The
same question (same budget, purpose and catalog) is answered from memory for an hour and uses no allowance.

### Seeing how it is doing

In the admin area, open **Insights** and look at "Build suggestions". It shows how many suggestions the AI was asked
for, how many of its builds were used, and why the store's rules took over for the others: the build cost more than the
budget, its parts did not fit together, the service answered with an error, and so on. If every suggestion says "Its
answer could not be read", the model probably does not support JSON mode: choose another.

### Other services, and switching it off

Any service that speaks the "OpenAI chat completions" format works: add `AI_BASE_URL` with the address its
documentation gives (OpenRouter's is `https://openrouter.ai/api/v1`), and use its key and model name. A paid service is
set up the same way. To go back to the store's rules, set `RECOMMENDER_DRIVER` to `rules` or delete it.

**What was tested.** The AI path was tested by playing the AI service: inside the test suite, and over real HTTP
against a stand-in server made to answer well, invent a product, choose parts that clash, answer in plain prose, fail
and time out. Each time the customer got a valid build. It was **not** run against Groq itself, which needs an account.
Your first suggestion after step 4 is that test.

Sources: [Groq's rate limits](https://console.groq.com/docs/rate-limits), [free AI services compared](https://openrouter.ai/blog/tutorials/free-llm-apis-compared/), [Groq's free plan](https://eesel.ai/blog/groq-pricing).
