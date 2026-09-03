<?php
declare(strict_types=1);

/** The Basic credentials on this request, or a 401. */
function credentials(): array
{
    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $pass = $_SERVER['PHP_AUTH_PW'] ?? null;

    if ($user === null) {
        // Fall back to parsing the raw header when PHP did not populate the pair.
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Basic\s+(.+)$/i', (string) $header, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$user, $pass] = explode(':', $decoded, 2);
            }
        }
    }

    if ($user === null || $user === '') {
        header('WWW-Authenticate: Basic realm="headhunter"');
        fail('Authentication required.', 401);
    }

    return [(string) $user, (string) $pass];
}

/** The request's database handle, opened as the caller's own role. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    [$user, $pass] = credentials();
    try {
        return $pdo = connect_as($user, $pass);
    } catch (PDOException $e) {
        if (pdo_status($e) === 401) {
            header('WWW-Authenticate: Basic realm="headhunter"');
            fail('Invalid database username or password.', 401);
        }
        throw $e;
    }
}

function current_role(): string
{
    return (string) db()->query('SELECT current_user')->fetchColumn();
}
