# Headhunter

AI resume polishing for a recruitment agency.

A candidate sends their resume to a Telegram bot. The headhunter opens the admin
app on their phone, taps **Polish**, reviews what the AI extracted, and sends the
finished PDF back — all without the candidate leaving Telegram.

```
candidate ──PDF──> Telegram ──> Apps Script ──> API ──> PostgreSQL
                                                 │
headhunter taps Polish ──────────────────────────┤
                                                 ├── AI ──> structured JSON ──> review
                                                 └── Gotenberg ──> PDF ──> back to Telegram
```

There is no job description and no matching. The AI's whole job is general
polish, governed by one instruction you edit in the app.

## At a glance

| Piece | What it is | Rebuilds? |
|---|---|---|
| **API** | Plain PHP, no framework, no Composer | Never — edit and reload |
| **Admin app** | A PWA; installs to the Android home screen | Never — edit and reload |
| **Gateway** | Google Apps Script, a dumb relay to Telegram | Paste and deploy |
| **Worker** | Runs the AI and the PDF render off a queue | Never |

The database has exactly one client. It sits on an internal Docker network with
no published port and no route off the host, so the application role needs no
password. Users are ordinary application data: bcrypt passwords, bearer tokens
stored only as hashes, expiry, revocation and login lockout.

Everything mutable lives under `./data`. There is no `.env` file.

---

# Onboarding

## 1. Start the stack

```sh
docker compose up -d --build
```

Five containers come up: `db`, `api`, `worker`, `pwa`, `gotenberg`.

If Compose reports `failed to resolve reference "docker.io/library/headhunter-php:local"`,
it is trying to download that image instead of building it. That tag only ever
exists on your machine — it is built from `docker/php/Dockerfile`. Build it
explicitly and start again:

```sh
docker compose build api
docker compose up -d
```

The compose file pins `pull_policy: build` on `api` and `worker` to stop this
happening, so you should only see it on an older checkout. Note that
`docker compose pull` will always fail on those two services for the same
reason — use `docker compose build` for them.

## 2. Read the credentials printed on first boot

```sh
docker compose logs db | grep -A6 'Initial credentials'
```

```
==============================================================
 Initial credentials (shown once)
--------------------------------------------------------------
owner password : ••••••••••••••••••••••••
gateway token  : ••••••••••••••••••••••••••••••••••••••••••••••••
--------------------------------------------------------------
```

They are also written to `data/secrets/initial-credentials.txt` when that
directory is writable by the container. **They are shown once.** If you lose
them, see *Starting over* below.

## 3. Sign in

Open the admin app on `http://<host>:9346`, sign in as **`owner`** with the
password from the log, then use **Account → Change my password** immediately.
Changing a password signs out every other session for that account.

On Android, use the browser's *Add to home screen* to install it.

## 4. Fill in Settings

Nothing works until the AI is configured. Go to **Settings**:

| Field | Notes |
|---|---|
| AI instruction | The system prompt for every polish. A sensible default is seeded. |
| AI base URL | Defaults to `https://openrouter.ai/api/v1`. Any OpenAI-compatible endpoint. |
| Model | Defaults to `anthropic/claude-sonnet-5`. Must accept PDF file parts, or extraction falls back to `pdftotext`. |
| API key | Required. Write-only — afterwards you only see the last four characters. |
| Temperature | Defaults to `0.2`. |
| Gateway URL | The Apps Script web app URL **with `?secret=…` appended** (step 5). |
| Gateway secret | Any long random string. Write-only. |
| Telegram admin chat id | Optional. Gets a PV message whenever the Telegram webhook handler throws. Send `/whoami` to the bot to read one off. |

## 5. Connect the Telegram bot

The API server cannot reach `api.telegram.org` directly, but Google's servers
can, so `gas/Code.gs` sits in the middle purely as a network bridge. It does
not know what a command is or what to say to anyone — it forwards every
Telegram update to the API untouched, and otherwise just makes whatever
Telegram API call the API asks it to make. All of that logic (`/start`,
`/whoami`, what counts as a resume, what to reply) lives in the API, in
`api/src/telegram.php` and `api/routes/telegram.php`.

1. Create a bot with `@BotFather` and keep its token.
2. Paste `gas/Code.gs` into a new Apps Script project.
3. **Deploy → New deployment → Web app**, execute as yourself, access **Anyone**.
4. **Project Settings → Script Properties**:
   - `BOT_TOKEN` — from BotFather
   - `API_TOKEN` — the gateway token from step 2
   - `GATEWAY_SECRET` — the same string you put in Settings
5. Run `setWebhook()` once from the editor. It logs the exact Gateway URL to
   paste into Settings.
6. Run `testApiAuth()` to confirm the token works. It should print `200`.

Apps Script cannot read request headers, which is why the secret travels as a
query parameter on the Gateway URL.

## 6. Optional: install fonts

```sh
./scripts/fetch-fonts.sh
```

Makes PDF rendering fully offline. Without it the resume template pulls
Vazirmatn from Google Fonts at render time, so the Gotenberg container needs
internet access.

## 7. Put it behind TLS

Both services listen over plain HTTP on the host. Reverse-proxy two names onto
them, with TLS terminated at the proxy — a bearer token in cleartext is still a
credential, so this is not optional.

| Name | Proxies to | Serves |
|---|---|---|
| `https://api.hunty.ir` | `0.0.0.0:9345` | the API |
| `https://hunty.ir` | `0.0.0.0:9346` | the admin app |

They are deliberately separate origins, so the API answers with CORS headers to
let the admin app call it from `hunty.ir`. Both names need a certificate.

---

# First users

## What gets seeded

First boot creates exactly two accounts and one PostgreSQL role.

| Account | Role | How it signs in |
|---|---|---|
| `owner` | `owner` | Password, generated and printed once |
| `gateway` | `gateway` | A non-expiring token, generated and printed once |
| `api` *(PostgreSQL role)* | — | No password; reachable only on the internal network |

## Roles

| Role | Can do |
|---|---|
| `owner` | Everything, plus manage accounts, sessions and tokens |
| `admin` | Candidates, resumes, runs, deliveries, settings |
| `gateway` | Two endpoints only: accept a forwarded Telegram update, fetch a file it was asked to send |

A `gateway` account cannot read the AI API key, list candidates, or see a run.
Every route declares the capability it needs in `api/public/index.php`, and
`api/src/auth.php` maps capabilities to roles — those two files are the whole
authorization model.

## Adding a colleague

**Users → Add an account** (owner only). Give them a username, a display name,
role `admin`, and a starting password; have them change it on first sign-in.

## Rotating the gateway token

**Users → gateway → Issue token**. The new token is shown once. Old tokens keep
working until you revoke them under **Active sessions**, so you can paste the new
one into Apps Script before cutting the old one off.

## Account maintenance

All under **Users**, owner only:

- **Reset password** — no need to know the old one; revokes all their sessions.
- **Disable / Enable** — keeps history, blocks sign-in, revokes their sessions.
- **Unlock** — clears a lockout from repeated failed sign-ins.
- **Revoke** a single session under *Active sessions*.

You cannot delete, demote or disable your own account, and the system refuses to
leave you with no active owner.

## Policies

| | |
|---|---|
| Password length | 10–72 **bytes** — bcrypt's real limit, so a longer one is refused rather than silently truncated. Persian characters cost 2 bytes each. |
| Failed sign-ins | Locks after 5, backing off exponentially up to 60 minutes |
| Session lifetime | 30 idle days, refreshed on every request |
| Gateway tokens | Never expire; revoke them explicitly |
| Storage | Passwords as bcrypt, tokens as SHA-256 — a database dump contains no usable credential |

---

# Migrations

There is no first-boot-only script anywhere in this project. `api/bin/migrate.php`
runs before the `api` and `worker` containers start serving, **every time they
boot**. Each `db/migrations/NNNN_name.up.php` not yet recorded in the
`schema_migrations` table is applied, in order; already-applied ones are
skipped. Shipping a schema change is: add a new numbered pair of files,
redeploy. Nothing to run by hand, no separate migration step to remember.

| File | Does |
|---|---|
| `0001_initial_schema.up.php` | Every table, and the seeded `settings` row (default AI instruction) |
| `0002_bootstrap.up.php` | Creates the `api` database role and the `owner`/`gateway` accounts, and prints their credentials once |
| `0003_telegram_relay.up.php`, and any added since | Ordinary schema changes |

Every migration is plain PHP, including pure schema changes — a `.up.php`
file just returns a `function (PDO $pdo): void` that calls `$pdo->exec()`, so
there is one file format to read, not SQL files plus a special case for the
one migration (`0002_bootstrap`) that needs real code to generate and print
secrets. Migrations run as the `postgres` role (trusted the same way as
everything else on the internal network, see `pg_hba.conf`), since the `api`
role deliberately has no DDL rights. An advisory lock keeps the `api` and
`worker` containers, which can boot at the same moment, from racing to apply
the same migration twice.

Generated secrets never touch the repository: only a bcrypt hash and a SHA-256
hash reach the database, and the plaintext exists only in that one log line
(and, best-effort, in `data/secrets/initial-credentials.txt`).

Each `NNNN_name.up.php` has a matching `NNNN_name.down.php` for manual rollback:

```sh
docker compose exec api php /var/www/html/api/bin/migrate.php down
```

Nothing calls `down` automatically — only `up`, on boot.

## Starting over

Destroys every candidate, resume and rendered PDF along with the accounts:

```sh
docker compose down
rm -rf data/postgres data/storage/originals/* data/storage/outputs/*
docker compose up -d
```

First boot then prints a fresh `owner` password and gateway token.

## Backups

Stop the stack and copy `./data` — it holds the database, the original resumes,
the rendered PDFs and the bootstrap credentials. It contains candidates'
personal data, so keep it off any public path. Nothing is deleted automatically;
retention is your call.

---

# Reference

| | |
|---|---|
| API | `0.0.0.0:9345`, proxied as `https://api.hunty.ir` |
| Admin app | `0.0.0.0:9346`, proxied as `https://hunty.ir` |
| Domain constant | `api/src/config.php`, `pwa/app.js`, `gas/Code.gs` |
| Logs | `docker compose logs -f worker` shows every polish and delivery |

```
api/                public/index.php is the router and the capability table
  src/              config, db, auth, files, ai, pdf, gateway, http client, migrate
  routes/           one file per resource
  bin/migrate.php   applies pending db/migrations/*.up.php on every api/worker boot
worker/worker.php   two queues: AI extraction, and delivery pushes
templates/          resume PDF template (Persian RTL and English LTR)
pwa/                the admin app
gas/Code.gs         dumb relay to Telegram (holds the bot token)
db/migrations/      numbered up/down migrations, applied automatically on every boot
data/               all runtime state (gitignored)
```
