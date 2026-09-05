<?php
declare(strict_types=1);

/**
 * All non-secret configuration. There is no .env file anywhere in this project:
 * change a value here and restart the containers.
 *
 * The AI API key lives in the `settings` table and is edited from the admin app.
 * User passwords and tokens are hashed in the database.
 */

/**
 * Public origin of this API, as the outside world reaches it.
 * The admin app is served separately from https://hunty.ir, so the two are
 * different origins and the API answers with CORS headers.
 */
const BASE_URL = 'https://api.hunty.ir';

/**
 * The database has one login role and no password. It sits on an internal Docker
 * network with no published port and no route off the host, so the only things
 * that can reach it are the api and worker containers. See db/pg_hba.conf.
 */
const DB_HOST = 'db';
const DB_PORT = '5432';
const DB_NAME = 'headhunter';
const DB_USER = 'api';

/**
 * The `api` role has no DDL rights, by design, so migrations run as `postgres`
 * instead — trusted the same way over the internal network (see pg_hba.conf).
 * Used only by bin/migrate.php, never by the request/worker path.
 */
const DB_MIGRATE_USER = 'postgres';

const GOTENBERG_URL = 'http://gotenberg:3000';

const STORAGE_DIR   = '/var/www/html/storage';
const TEMPLATES_DIR = '/var/www/html/templates';

/** How long a human's sign-in stays valid without use. Refreshed on every request. */
const SESSION_IDLE_DAYS = 30;

/** Failed sign-ins before an account is temporarily locked, and the ceiling in minutes. */
const LOGIN_MAX_ATTEMPTS   = 5;
const LOGIN_MAX_LOCK_MINS  = 60;

/** bcrypt cannot use more than 72 bytes, so longer passwords are rejected, not truncated. */
const PASSWORD_MIN_LENGTH = 10;
const PASSWORD_MAX_BYTES  = 72;

const WORKER_POLL_SECONDS = 3;

/** Set to true to include PHP error detail in API responses. */
const APP_DEBUG = false;
