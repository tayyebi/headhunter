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

## Shape of the thing

**The database has exactly one client.** It runs on an internal Docker network
with no published port and no route off the host, so the only things that can
open a socket to it are the `api` and `worker` containers. Because that network
boundary is the whole perimeter, the single application role has no password.

```
networks:
  data   internal, no egress    db, api, worker
  edge   normal bridge          api, worker, gotenberg, pwa
```

**Users are application data**, not database roles: a `users` table with bcrypt
password hashes, a `sessions` table holding only the SHA-256 of each bearer
token, login throttling with exponential lockout, and revocation.

**Authorization is one table.** Every route in `api/public/index.php` names the
capability it needs, and `api/src/auth.php` maps capabilities to roles. Nothing
else in the codebase decides who may do what:

| Capability | Roles | Covers |
|---|---|---|
| `public` | no token | `/`, `/health`, `/auth/login` |
| `user` | gateway, admin, owner | own account, plus the gateway's two endpoints |
| `admin` | admin, owner | candidates, resumes, runs, deliveries, settings |
| `owner` | owner | accounts, sessions, tokens |

The gateway account can reach 9 of 35 routes. It cannot read the AI API key, list
candidates, or see a run.

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
docker compose logs db | grep -A6 'Initial credentials'
```

First boot generates the `owner` password and a gateway token, prints them to the
database log, and tries to write them to `data/secrets/initial-credentials.txt`.
They are shown **once**. Sign in to the PWA as `owner` and change the password
from the Account screen. To start over, stop the stack and delete `data/postgres`.

The API listens on `0.0.0.0:9345` over plain HTTP. Reverse-proxy `https://hunty.ir`
onto it — a bearer token in cleartext is still a credential, so TLS at the proxy
is not optional. The PWA is on `0.0.0.0:9346`.

Optional, makes PDF rendering fully offline:

```sh
./scripts/fetch-fonts.sh
```

Without it the template loads Vazirmatn from Google Fonts at render time, which
needs the Gotenberg container to have internet access.

## Configuration

There is no `.env` file. Three kinds of setting, three places:

- **Fixed, non-secret** — `api/src/config.php` (and `API_BASE` at the top of
  `pwa/app.js` and `gas/Code.gs`). The domain is hardcoded to `https://hunty.ir`;
  change those three constants if it ever moves.
- **Operational secrets** — the `settings` table, edited on the Settings screen:
  the AI instruction, AI base URL / model / key, and the gateway URL / secret.
- **Credentials** — the `users` and `sessions` tables. Nothing is ever stored in
  plaintext, and nothing is in the repository.

## Wiring up the Telegram bot

1. Create a bot with `@BotFather`, keep the token.
2. Paste `gas/Code.gs` into a new Apps Script project.
3. Deploy as a **Web app**, execute as yourself, access **Anyone**.
4. Script Properties: `BOT_TOKEN`, `API_TOKEN` (the gateway token from first boot,
   or reissued from the admin app's Users screen), and `GATEWAY_SECRET` (any long
   random string).
5. Run `setWebhook()` once from the editor. It logs the gateway URL to use.
6. In the PWA's Settings, set **Gateway URL** to that logged URL — the web app URL
   with `?secret=<GATEWAY_SECRET>` appended. Apps Script cannot read request
   headers, so the secret travels as a query parameter.

## Layout

```
api/                PHP: public/index.php is the router and the capability table
  src/              config, db, auth, files, ai, pdf, gateway, http client
  routes/           one file per resource
worker/worker.php   both queues: AI extraction, and delivery pushes
templates/          the resume PDF template (Persian RTL and English LTR)
pwa/                the admin app
gas/Code.gs         the Telegram gateway
db/                 schema, bootstrap, pg_hba
data/               all runtime state (gitignored)
```

## Security notes

- The database's protection is the network, not a password. If you ever publish
  its port or attach it to a routable network, that protection is gone.
- Deliveries carry an `idempotency_key`, and the gateway caches keys it has
  already sent, so a push that times out after Telegram accepted the document
  does not deliver it twice.
- Changing a password revokes every other session for that user. Disabling an
  account or resetting its password revokes all of them.
- Resumes are personal data. `data/` holds all of it; keep it off public paths.
- Nothing auto-deletes. Retention is your call.
