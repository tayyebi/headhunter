-- Headhunter schema. Five tables, all in the default `public` schema.
-- `runs` and `deliveries` double as work queues (FOR UPDATE SKIP LOCKED).

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

CREATE TABLE runs (
    id                    bigserial PRIMARY KEY,
    resume_id             bigint      NOT NULL REFERENCES resumes(id) ON DELETE CASCADE,
    status                text        NOT NULL DEFAULT 'queued'
                          CHECK (status IN ('queued', 'running', 'needs_review',
                                            'rendering', 'ready', 'delivered', 'failed')),
    -- What the global instruction said at the moment this run started.
    instruction_snapshot  text        NOT NULL DEFAULT '',
    model                 text        NOT NULL DEFAULT '',
    -- How the resume text reached the model: 'file' (PDF upload) or 'text' (pdftotext).
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
    kind             text        NOT NULL CHECK (kind IN ('text', 'document')),
    body             text        NOT NULL DEFAULT '',
    file_path        text,
    file_name        text,
    status           text        NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending', 'sent', 'failed')),
    attempts         int         NOT NULL DEFAULT 0,
    next_attempt_at  timestamptz NOT NULL DEFAULT now(),
    last_error       text,
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
