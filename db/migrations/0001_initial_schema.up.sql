-- Headhunter schema.
--
-- The database has exactly one client: the API. There is one login role, it has
-- no password, and it is reachable only from an internal Docker network. So there
-- is no row level security and no per-user database role here: users, sessions
-- and permissions are ordinary application data.

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ---------------------------------------------------------------------------
-- Identity
-- ---------------------------------------------------------------------------

CREATE TABLE users (
    id              bigserial PRIMARY KEY,
    username        text        NOT NULL UNIQUE
                    CHECK (username = lower(username) AND length(username) BETWEEN 2 AND 64),
    display_name    text        NOT NULL DEFAULT '',
    -- bcrypt. NULL for machine accounts, which authenticate by token only.
    password_hash   text,
    role            text        NOT NULL CHECK (role IN ('owner', 'admin', 'gateway')),
    status          text        NOT NULL DEFAULT 'active'
                    CHECK (status IN ('active', 'disabled')),
    -- Login throttling. Cleared on every successful sign in.
    failed_attempts int         NOT NULL DEFAULT 0,
    locked_until    timestamptz,
    last_login_at   timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);

-- Bearer tokens. Only the SHA-256 of a token is ever stored, so a database dump
-- does not hand anyone a working credential.
CREATE TABLE sessions (
    id           bigserial PRIMARY KEY,
    user_id      bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash   text        NOT NULL UNIQUE,
    -- Set for long-lived machine tokens so they can be told apart in the UI.
    label        text        NOT NULL DEFAULT '',
    -- NULL means the token never expires; used for the gateway.
    expires_at   timestamptz,
    revoked_at   timestamptz,
    last_seen_at timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX sessions_user_idx ON sessions (user_id, created_at DESC);

-- ---------------------------------------------------------------------------
-- Candidates and their resumes
-- ---------------------------------------------------------------------------

CREATE TABLE candidates (
    id            bigserial PRIMARY KEY,
    -- Opaque to this API. The gateway writes things like 'telegram:123456789'.
    external_ref  text UNIQUE,
    display_name  text        NOT NULL DEFAULT '',
    phone         text,
    note          text        NOT NULL DEFAULT '',
    status        text        NOT NULL DEFAULT 'new'
                  CHECK (status IN ('new', 'active', 'archived')),
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE resumes (
    id             bigserial PRIMARY KEY,
    candidate_id   bigint      NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    source         text        NOT NULL DEFAULT 'gateway'
                   CHECK (source IN ('gateway', 'admin')),
    file_path      text        NOT NULL,
    orig_filename  text        NOT NULL DEFAULT '',
    mime           text        NOT NULL DEFAULT 'application/octet-stream',
    size_bytes     bigint      NOT NULL DEFAULT 0,
    sha256         text        NOT NULL,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX resumes_candidate_idx ON resumes (candidate_id, created_at DESC);

-- Doubles as the polish queue (FOR UPDATE SKIP LOCKED).
CREATE TABLE runs (
    id                    bigserial PRIMARY KEY,
    resume_id             bigint      NOT NULL REFERENCES resumes(id) ON DELETE CASCADE,
    -- Who asked for this run. Kept when the user is later deleted.
    requested_by          bigint      REFERENCES users(id) ON DELETE SET NULL,
    status                text        NOT NULL DEFAULT 'queued'
                          CHECK (status IN ('queued', 'running', 'needs_review',
                                            'rendering', 'ready', 'delivered', 'failed')),
    -- What the global instruction said at the moment this run started.
    instruction_snapshot  text        NOT NULL DEFAULT '',
    model                 text        NOT NULL DEFAULT '',
    -- How the resume reached the model: 'file' (PDF upload) or 'text' (pdftotext).
    input_mode            text,
    extracted             jsonb,
    edited                jsonb,
    output_path           text,
    error                 text,
    attempts              int         NOT NULL DEFAULT 0,
    created_at            timestamptz NOT NULL DEFAULT now(),
    started_at            timestamptz,
    finished_at           timestamptz
);
CREATE INDEX runs_queue_idx  ON runs (status, id);
CREATE INDEX runs_resume_idx ON runs (resume_id, created_at DESC);

-- Push outbox. The worker POSTs these to the configured gateway webhook.
CREATE TABLE deliveries (
    id               bigserial PRIMARY KEY,
    candidate_id     bigint      NOT NULL REFERENCES candidates(id) ON DELETE CASCADE,
    run_id           bigint      REFERENCES runs(id) ON DELETE SET NULL,
    sent_by          bigint      REFERENCES users(id) ON DELETE SET NULL,
    kind             text        NOT NULL CHECK (kind IN ('text', 'document')),
    body             text        NOT NULL DEFAULT '',
    file_path        text,
    file_name        text,
    status           text        NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending', 'sent', 'failed')),
    attempts         int         NOT NULL DEFAULT 0,
    next_attempt_at  timestamptz NOT NULL DEFAULT now(),
    last_error       text,
    -- Sent to the gateway so a retried push can be recognised as a duplicate.
    idempotency_key  uuid        NOT NULL DEFAULT gen_random_uuid(),
    created_at       timestamptz NOT NULL DEFAULT now(),
    sent_at          timestamptz
);
CREATE INDEX deliveries_queue_idx ON deliveries (status, next_attempt_at);

-- Exactly one row, ever.
CREATE TABLE settings (
    id                  int PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    system_instruction  text        NOT NULL DEFAULT '',
    ai_base_url         text        NOT NULL DEFAULT 'https://openrouter.ai/api/v1',
    ai_model            text        NOT NULL DEFAULT 'anthropic/claude-sonnet-5',
    ai_api_key          text        NOT NULL DEFAULT '',
    temperature         numeric     NOT NULL DEFAULT 0.2,
    gateway_url         text        NOT NULL DEFAULT '',
    gateway_secret      text        NOT NULL DEFAULT '',
    updated_at          timestamptz NOT NULL DEFAULT now()
);

INSERT INTO settings (id, system_instruction) VALUES (1,
'You are an expert resume editor working for a recruitment agency.

You will be given a candidate''s resume. Read it carefully and produce a polished,
professional version of its content. Keep the candidate''s own language: if the
resume is in Persian, answer in Persian; if it is in English, answer in English.

Rules:
- Never invent employers, dates, degrees, titles or skills. Only rewrite what is there.
- Tighten wording. Prefer concrete achievements over vague duties.
- Normalise dates to a consistent format and order everything newest first.
- Drop filler, clip-art phrases and self-praise that carries no information.
- If something in the source is unreadable or missing, leave the field empty rather
  than guessing.');
