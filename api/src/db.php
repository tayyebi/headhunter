<?php
declare(strict_types=1);

/**
 * Connect as a real PostgreSQL role. There is no users table: a successful
 * connection IS the authentication, and GRANTs plus row level security are
 * the entire authorisation system.
 */
function connect_as(string $user, string $password): PDO
{
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME);

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/**
 * pdo_pgsql reports every connection-time failure as SQLSTATE 08006 carrying
 * libpq's numeric code 7 — a wrong password and an unreachable host look
 * identical until you read the message. Since a successful connection IS the
 * authentication here, telling those apart is the difference between a login
 * form that works and one that returns 500.
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

/** Only ever consulted for connection-level failures, never for query errors. */
function pdo_rejected_credentials(PDOException $e): bool
{
    $message = $e->getMessage();

    if (preg_match('/role ".*" does not exist/i', $message)) {
        return true;
    }
    foreach ([
        'password authentication failed',
        'authentication failed',
        'no password supplied',
        'no pg_hba.conf entry',
        'is not permitted to log in',
    ] as $needle) {
        if (stripos($message, $needle) !== false) {
            return true;
        }
    }

    return false;
}

/** Translate a PostgreSQL error into an HTTP status. */
function pdo_status(PDOException $e): int
{
    $state = pdo_sqlstate($e);

    // 28xxx is an explicit authentication rejection.
    if (str_starts_with($state, '28')) {
        return 401;
    }

    // 08xxx is "the connection did not happen"; the message says why.
    if ($state === '' || str_starts_with($state, '08')) {
        if (pdo_rejected_credentials($e)) {
            return 401;
        }
        return str_starts_with($state, '08') ? 503 : 500;
    }

    return match ($state) {
        '42501', '42P01', '42703' => 403,  // no privilege, even to see it
        '23505'                   => 409,  // unique violation
        '23503', '23514', '22P02' => 422,  // foreign key / check / bad literal
        default                   => 500,
    };
}

function pdo_message(PDOException $e): string
{
    return match (pdo_status($e)) {
        401     => 'Invalid database username or password.',
        403     => 'Your database role is not permitted to do that.',
        409     => 'That record already exists.',
        422     => 'The database rejected those values.',
        503     => 'The database is unavailable.',
        default => 'Database error.',
    };
}

/** Quote an identifier for statements (like ALTER ROLE) that cannot take parameters. */
function quote_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $name)) {
        fail('Invalid role name.', 422);
    }
    return '"' . str_replace('"', '""', $name) . '"';
}

/** Quote a literal for the same reason. */
function quote_literal(PDO $pdo, string $value): string
{
    return $pdo->quote($value);
}
