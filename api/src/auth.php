<?php
declare(strict_types=1);

/**
 * Application authentication.
 *
 * Sign in with a username and password, get a bearer token. Only the SHA-256 of
 * a token is stored, so a database dump hands nobody a working credential, and
 * only a bcrypt hash of a password is stored.
 *
 * Authorization is a single declarative table: every route in public/index.php
 * names the capability it needs, and this is the only place that decides who
 * holds it. It is deliberately one readable list rather than scattered checks.
 */
const CAPABILITY_ROLES = [
    'public' => null,                             // no token required
    'user'   => ['gateway', 'admin', 'owner'],    // any signed-in account
    'admin'  => ['admin', 'owner'],               // a headhunter
    'owner'  => ['owner'],                        // account administration
];

/** A bcrypt hash of a value nobody knows, used to keep failed logins constant-time. */
const DUMMY_PASSWORD_HASH = '$2y$12$C6UzMDM.H6dfI/f/IKcEe.7EkVkgLdiUAf9zvKfL3xPHVCkYcLK2u';

function hash_token(string $token): string
{
    return hash('sha256', $token);
}

function new_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/** The bearer token on this request, if any. */
function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (preg_match('/^Bearer\s+(\S+)$/i', (string) $header, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Resolve the caller, or null. Touches the session so idle expiry slides forward
 * for humans; machine tokens (expires_at IS NULL) are left alone.
 */
function current_user(): ?array
{
    static $resolved = false;
    static $user = null;

    if ($resolved) {
        return $user;
    }
    $resolved = true;

    $token = bearer_token();
    if ($token === null) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT s.id AS session_id, s.expires_at, s.label,
                u.id, u.username, u.display_name, u.role, u.status
           FROM sessions s
           JOIN users u ON u.id = s.user_id
          WHERE s.token_hash = :hash
            AND s.revoked_at IS NULL
            AND (s.expires_at IS NULL OR s.expires_at > now())'
    );
    $stmt->execute([':hash' => hash_token($token)]);
    $row = $stmt->fetch();

    if (!$row || $row['status'] !== 'active') {
        return null;
    }

    $touch = db()->prepare(
        "UPDATE sessions
            SET last_seen_at = now(),
                expires_at = CASE WHEN expires_at IS NULL THEN NULL
                                  ELSE now() + (:days || ' days')::interval END
          WHERE id = :id"
    );
    $touch->execute([':days' => (string) SESSION_IDLE_DAYS, ':id' => $row['session_id']]);

    return $user = $row;
}

/** The caller, or a 401. */
function auth(): array
{
    $user = current_user();
    if ($user === null) {
        header('WWW-Authenticate: Bearer realm="headhunter"');
        fail('Sign in to continue.', 401);
    }
    return $user;
}

/** Enforce a route's declared capability. */
function require_capability(string $capability): void
{
    if (!array_key_exists($capability, CAPABILITY_ROLES)) {
        throw new RuntimeException('Unknown capability: ' . $capability);
    }

    $roles = CAPABILITY_ROLES[$capability];
    if ($roles === null) {
        return;
    }

    $user = auth();
    if (!in_array($user['role'], $roles, true)) {
        fail('Your account is not permitted to do that.', 403);
    }
}

function current_user_id(): ?int
{
    $user = current_user();
    return $user === null ? null : (int) $user['id'];
}

// ---------------------------------------------------------------------------
// Passwords
// ---------------------------------------------------------------------------

function check_password_policy(string $password): void
{
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        fail('Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.', 422);
    }
    // bcrypt silently ignores anything past 72 bytes, which would make a long
    // password weaker than it looks. Refuse instead.
    if (strlen($password) > PASSWORD_MAX_BYTES) {
        fail('Password must be at most ' . PASSWORD_MAX_BYTES . ' bytes.', 422);
    }
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify a sign in, applying lockout. Unknown usernames and wrong passwords are
 * reported identically and take the same time, so this cannot be used to find
 * out which accounts exist.
 */
function verify_login(string $username, string $password): array
{
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username');
    $stmt->execute([':username' => strtolower(trim($username))]);
    $user = $stmt->fetch();

    if (!$user) {
        password_verify($password, DUMMY_PASSWORD_HASH);
        fail('Incorrect username or password.', 401);
    }

    if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
        fail('Too many failed attempts. Try again later.', 429);
    }

    $ok = $user['status'] === 'active'
        && $user['password_hash'] !== null
        && password_verify($password, $user['password_hash']);

    if (!$ok) {
        record_failed_login($pdo, (int) $user['id']);
        fail('Incorrect username or password.', 401);
    }

    $pdo->prepare(
        'UPDATE users SET failed_attempts = 0, locked_until = NULL,
                          last_login_at = now(), updated_at = now()
          WHERE id = :id'
    )->execute([':id' => $user['id']]);

    // Opportunistically upgrade a hash made with an older cost or algorithm.
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute([':hash' => hash_password($password), ':id' => $user['id']]);
    }

    return $user;
}

/** Back off exponentially once the attempt threshold is passed, capped. */
function record_failed_login(PDO $pdo, int $userId): void
{
    $pdo->prepare(
        "UPDATE users
            SET failed_attempts = failed_attempts + 1,
                locked_until = CASE
                    WHEN failed_attempts + 1 >= :threshold
                    THEN now() + (least(power(2, failed_attempts + 1 - :threshold2), :cap)
                                  * interval '1 minute')
                    ELSE locked_until END,
                updated_at = now()
          WHERE id = :id"
    )->execute([
        ':threshold'  => LOGIN_MAX_ATTEMPTS,
        ':threshold2' => LOGIN_MAX_ATTEMPTS,
        ':cap'        => LOGIN_MAX_LOCK_MINS,
        ':id'         => $userId,
    ]);
}

// ---------------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------------

/**
 * Issue a bearer token. $days of null makes it non-expiring, which is only used
 * for machine accounts such as the gateway.
 */
function issue_token(int $userId, string $label = '', ?int $days = SESSION_IDLE_DAYS): array
{
    $token = new_token();

    $stmt = db()->prepare(
        "INSERT INTO sessions (user_id, token_hash, label, expires_at)
         VALUES (:user, :hash, :label,
                 CASE WHEN :days::int IS NULL THEN NULL
                      ELSE now() + (:days2 || ' days')::interval END)
         RETURNING id, label, created_at, expires_at"
    );
    $stmt->execute([
        ':user'  => $userId,
        ':hash'  => hash_token($token),
        ':label' => $label,
        ':days'  => $days,
        ':days2' => $days === null ? '0' : (string) $days,
    ]);

    return ['token' => $token, 'session' => $stmt->fetch()];
}

function revoke_session(int $sessionId, ?int $userId = null): bool
{
    $sql = 'UPDATE sessions SET revoked_at = now() WHERE id = :id AND revoked_at IS NULL';
    $params = [':id' => $sessionId];

    if ($userId !== null) {
        $sql .= ' AND user_id = :user';
        $params[':user'] = $userId;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount() > 0;
}

function public_user(array $user): array
{
    return [
        'id'           => (int) $user['id'],
        'username'     => $user['username'],
        'display_name' => $user['display_name'],
        'role'         => $user['role'],
        'status'       => $user['status'],
    ];
}
