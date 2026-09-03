<?php
declare(strict_types=1);

/**
 * The one database connection. The API is the database's only client, so there
 * are no per-user roles and no credentials to pass around: identity is decided
 * above this layer, in auth.php, against the users and sessions tables.
 */
function db(): PDO
{
    global $__db;

    if ($__db instanceof PDO) {
        return $__db;
    }

    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME);

    return $__db = new PDO($dsn, DB_USER, '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/** Drop the handle so the next db() reconnects. Only the long-running worker needs this. */
function db_disconnect(): void
{
    global $__db;
    $__db = null;
}

/**
 * pdo_pgsql reports connection-time failures as SQLSTATE 08006 carrying libpq's
 * numeric code 7, so the SQLSTATE has to be read out of the message.
 */
function pdo_sqlstate(PDOException $e): string
{
    $code = (string) $e->getCode();
    if (preg_match('/^[0-9A-Za-z]{5}$/', $code)) {
        return strtoupper($code);
    }
    if (preg_match('/SQLSTATE\[([0-9A-Za-z]{5})\]/', $e->getMessage(), $m)) {
        return strtoupper($m[1]);
    }
    return '';
}

/**
 * Translate a PostgreSQL error into an HTTP status. Nothing here maps to 401 or
 * 403 any more: authentication is the application's job now, so a database error
 * is either a constraint the caller tripped, or our own bug.
 */
function pdo_status(PDOException $e): int
{
    $state = pdo_sqlstate($e);

    if (str_starts_with($state, '08')) {
        return 503;  // the connection did not happen
    }

    return match ($state) {
        '23505'                   => 409,  // unique violation
        '23503', '23514', '22P02' => 422,  // foreign key / check / bad literal
        default                   => 500,
    };
}

function pdo_message(PDOException $e): string
{
    return match (pdo_status($e)) {
        409     => 'That record already exists.',
        422     => 'The database rejected those values.',
        503     => 'The database is unavailable.',
        default => 'Database error.',
    };
}
