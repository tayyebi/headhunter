# Headhunter

AI resume polishing for a recruitment agency. Candidates send their resume to a
Telegram bot; the headhunter reviews the polished result on their phone and sends
the finished PDF back.

Three deliberately separate pieces:

| Piece | What it is | Rebuilds? |
|---|---|---|
| **API** | Plain PHP, no framework, no Composer. Bind-mounted from the host. | Never — edit and reload |
| **Admin app** | A PWA. Plain HTML/JS served as static files. Installs to the Android home screen. | Never — edit and reload |
| **Gateway** | A Google Apps Script Telegram bot. Just another REST client. | Paste and deploy |

Only the PHP runtime is baked into an image, and only because the official PHP
image has no `pdo_pgsql`. All application code is mounted from the host.

## The one idea worth knowing

**PostgreSQL roles are the users.** There is no users table, no session table, no
JWT, no password hashing in PHP. A request carries HTTP Basic credentials, the API
opens a database connection *as that role*, and `GRANT` plus row level security
decide everything after that.

So `bot_gateway` cannot read your AI API key because the database refuses, not
because an `if` statement in PHP said so:

```
$ curl -u bot_gateway:… https://hunty.ir/settings
{"error":"Your database role is not permitted to do that.","sqlstate":"42501"}
```

Changing your password is `ALTER ROLE`. Adding a colleague is `CREATE ROLE`.

## Flow

```
candidate ──PDF──> Telegram ──> Apps Script ──POST /intake──> API ──> PostgreSQL
                                                               │
headhunter (PWA) ── taps Polish ──> POST /resumes/{id}/runs ────┘
                                                               │
                                     worker ── AI ──> JSON ──> review in PWA
                                                               │
                                     worker ── HTML ──> Gotenberg ──> PDF
                                                               │
headhunter taps Send ──> deliveries ──> worker pushes ──> Apps Script ──> Telegram
```

The API has no concept of Telegram. It addresses candidates by an opaque
`external_ref` string that the gateway happens to fill with `telegram:<chat id>`.

There is no job description and no matching. The AI's whole job is general polish,
governed by one editable instruction on the Settings screen.

## Running it

```sh
docker compose up -d --build
docker compose logs db | grep -A6 'Initial database credentials'
```

First boot generates passwords for `hh_owner` and `bot_gateway` and prints them to
the database log, which is the reliable place to read them from. It also tries to
write them to `data/secrets/initial-credentials.txt`, which only works if that
directory is writable by the container. Sign in to the PWA as `hh_owner` and change
the password from the Account screen.

They are shown **once**, on the very first boot. To start over, stop the stack and
delete `data/postgres`.

The API listens on `0.0.0.0:9345` over plain HTTP. Reverse-proxy `https://hunty.ir`
onto it — **Basic auth over plain HTTP would leak database passwords**, so TLS at
the proxy is not optional. The PWA is on `0.0.0.0:9346`.

Optional, makes PDF rendering fully offline:

```sh
./scripts/fetch-fonts.sh
```

Without it the template loads Vazirmatn from Google Fonts at render time, which
needs the Gotenberg container to have internet access.

## Configuration

There is no `.env` file. Two kinds of setting, two places:

- **Fixed, non-secret** — `api/src/config.php` (and `API_BASE` at the top of
  `pwa/app.js` and `gas/Code.gs`). The domain is hardcoded to `https://hunty.ir`;
  change those three constants if it ever moves.
- **Secret or operational** — the `settings` table, edited on the Settings screen:
  the AI instruction, AI base URL / model / key, and the gateway URL / secret.

## Wiring up the Telegram bot

1. Create a bot with `@BotFather`, keep the token.
2. Paste `gas/Code.gs` into a new Apps Script project.
3. Deploy as a **Web app**, execute as yourself, access **Anyone**.
4. Script Properties: `BOT_TOKEN`, `API_PASS` (the `bot_gateway` password),
   `GATEWAY_SECRET` (any long random string).
5. Run `setWebhook()` once from the editor. It logs the gateway URL to use.
6. In the PWA's Settings, set **Gateway URL** to that logged URL — the web app URL
   with `?secret=<GATEWAY_SECRET>` appended. Apps Script cannot read request
   headers, so the secret travels as a query parameter.

## Layout

```
api/                PHP: public/index.php is the whole router
  src/              config, db, auth, files, ai, pdf, gateway, http client
  routes/           one file per resource
worker/worker.php   both queues: AI extraction, and delivery pushes
templates/          the resume PDF template (Persian RTL and English LTR)
pwa/                the admin app
gas/Code.gs         the Telegram gateway
db/                 schema, roles, RLS, pg_hba
data/               all runtime state (gitignored)
```

## Security notes

- `hh_worker` has **no password**. `db/pg_hba.conf` trusts it, and the database
  port is not published, so only this compose network can reach it. Every other
  role needs a real password — if they did not, HTTP Basic would verify nothing.
- Resumes are personal data. `data/` holds all of it; keep it off public paths.
- Nothing auto-deletes. Retention is your call.
