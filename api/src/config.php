<?php
declare(strict_types=1);

/**
 * All non-secret configuration. There is no .env file anywhere in this project:
 * change a value here and restart the containers.
 *
 * Real secrets (AI API key, gateway secret) live in the `settings` table and are
 * edited from the admin app. Database passwords are PostgreSQL's own, and are
 * changed with ALTER ROLE from the Account screen.
 */

/** Public origin of this API, as the outside world reaches it. */
const BASE_URL = 'https://hunty.ir';

/** Service names on the compose network. The database port is never published. */
const DB_HOST       = 'db';
const DB_PORT       = '5432';
const DB_NAME       = 'headhunter';
const GOTENBERG_URL = 'http://gotenberg:3000';

const STORAGE_DIR   = '/var/www/html/storage';
const TEMPLATES_DIR = '/var/www/html/templates';

/**
 * The worker's role. It has no password: pg_hba.conf trusts it, and the only
 * thing that can reach the database is the compose network itself.
 */
const WORKER_DB_USER      = 'hh_worker';
const WORKER_POLL_SECONDS = 3;

/** Set to true to include PHP error detail in API responses. */
const APP_DEBUG = false;
