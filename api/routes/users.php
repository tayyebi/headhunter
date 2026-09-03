<?php
declare(strict_types=1);

function user_or_404(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('No such user.', 404);
    }
    return $row;
}

function r_users_list(array $p): array
{
    $rows = db()->query(
        'SELECT u.id, u.username, u.display_name, u.role, u.status,
                u.last_login_at, u.locked_until, u.created_at,
                (SELECT count(*) FROM sessions s
                  WHERE s.user_id = u.id AND s.revoked_at IS NULL
                    AND (s.expires_at IS NULL OR s.expires_at > now())) AS active_sessions
           FROM users u ORDER BY u.id'
    )->fetchAll();

    return ['users' => $rows];
}

function r_user_create(array $p): array
{
    $b        = body();
    $username = strtolower(trim((string) field($b, 'username')));
    $role     = (string) field($b, 'role', 'admin');

    if (!preg_match('/^[a-z0-9._-]{2,64}$/', $username)) {
        fail('Username may only contain lowercase letters, digits, dot, dash and underscore.', 422);
    }
    if (!in_array($role, ['owner', 'admin', 'gateway'], true)) {
        fail('Role must be owner, admin or gateway.', 422);
    }

    // Machine accounts sign in with a token only, so they get no password.
    $hash = null;
    if ($role !== 'gateway') {
        $password = (string) field($b, 'password');
        check_password_policy($password);
        $hash = hash_password($password);
    }

    $stmt = db()->prepare(
        'INSERT INTO users (username, display_name, password_hash, role)
         VALUES (:username, :display_name, :hash, :role)
         RETURNING id, username, display_name, role, status, created_at'
    );
    $stmt->execute([
        ':username'     => $username,
        ':display_name' => (string) ($b['display_name'] ?? ''),
        ':hash'         => $hash,
        ':role'         => $role,
    ]);

    return ['user' => $stmt->fetch()];
}

function r_user_patch(array $p): array
{
    $id     = (int) $p[0];
    $target = user_or_404($id);
    $me     = auth();
    $b      = body();

    $sets   = [];
    $params = [':id' => $id];

    if (array_key_exists('display_name', $b)) {
        $sets[] = 'display_name = :display_name';
        $params[':display_name'] = (string) $b['display_name'];
    }

    if (array_key_exists('role', $b)) {
        if (!in_array($b['role'], ['owner', 'admin', 'gateway'], true)) {
            fail('Role must be owner, admin or gateway.', 422);
        }
        if ((int) $me['id'] === $id && $b['role'] !== 'owner') {
            fail('You cannot remove your own owner role.', 422);
        }
        guard_last_owner($id, (string) $b['role'], $target);
        $sets[] = 'role = :role';
        $params[':role'] = $b['role'];
    }

    if (array_key_exists('status', $b)) {
        if (!in_array($b['status'], ['active', 'disabled'], true)) {
            fail('Status must be active or disabled.', 422);
        }
        if ((int) $me['id'] === $id && $b['status'] !== 'active') {
            fail('You cannot disable your own account.', 422);
        }
        $sets[] = 'status = :status';
        $params[':status'] = $b['status'];
    }

    // An owner resetting someone's password does not need to know the old one.
    if (!empty($b['password'])) {
        check_password_policy((string) $b['password']);
        $sets[] = 'password_hash = :hash';
        $params[':hash'] = hash_password((string) $b['password']);
    }

    // Clear a lockout.
    if (!empty($b['unlock'])) {
        $sets[] = 'failed_attempts = 0';
        $sets[] = 'locked_until = NULL';
    }

    if ($sets === []) {
        fail('Nothing to update.', 422);
    }
    $sets[] = 'updated_at = now()';

    $stmt = db()->prepare(
        'UPDATE users SET ' . implode(', ', $sets) .
        ' WHERE id = :id RETURNING id, username, display_name, role, status, locked_until'
    );
    $stmt->execute($params);

    // A disabled account or a reset password must not keep working elsewhere.
    if (isset($params[':hash']) || (($b['status'] ?? null) === 'disabled')) {
        db()->prepare('UPDATE sessions SET revoked_at = now() WHERE user_id = :id AND revoked_at IS NULL')
            ->execute([':id' => $id]);
    }

    return ['user' => $stmt->fetch()];
}

function r_user_delete(array $p): array
{
    $id = (int) $p[0];
    $me = auth();

    if ((int) $me['id'] === $id) {
        fail('You cannot delete your own account.', 422);
    }
    $target = user_or_404($id);
    guard_last_owner($id, 'admin', $target);

    db()->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);

    return ['ok' => true];
}

/** Refuse anything that would leave nobody able to administer accounts. */
function guard_last_owner(int $id, string $newRole, array $target): void
{
    if ($target['role'] !== 'owner' || $newRole === 'owner') {
        return;
    }
    $others = (int) db()->query(
        "SELECT count(*) FROM users WHERE role = 'owner' AND status = 'active'"
    )->fetchColumn();

    if ($others <= 1) {
        fail('This is the only owner account. Promote someone else first.', 422);
    }
}

/**
 * Issue a long-lived token for a machine account. Shown once, then only its
 * SHA-256 exists. Human accounts get tokens by signing in instead.
 */
function r_user_token(array $p): array
{
    $target = user_or_404((int) $p[0]);

    if ($target['role'] !== 'gateway') {
        fail('Only gateway accounts can be issued a standing token. Humans sign in.', 422);
    }

    $issued = issue_token((int) $target['id'], (string) (body()['label'] ?? 'gateway token'), null);

    return [
        'token'   => $issued['token'],
        'session' => $issued['session'],
        'note'    => 'This is shown once. Store it in the Apps Script property API_TOKEN.',
    ];
}

function r_sessions_list(array $p): array
{
    $rows = db()->query(
        'SELECT s.id, s.user_id, s.label, s.created_at, s.last_seen_at, s.expires_at, s.revoked_at,
                u.username, u.role
           FROM sessions s JOIN users u ON u.id = s.user_id
          WHERE s.revoked_at IS NULL AND (s.expires_at IS NULL OR s.expires_at > now())
          ORDER BY s.id DESC LIMIT 200'
    )->fetchAll();

    return ['sessions' => $rows];
}

function r_session_revoke(array $p): array
{
    if (!revoke_session((int) $p[0])) {
        fail('No such active session.', 404);
    }
    return ['ok' => true];
}
