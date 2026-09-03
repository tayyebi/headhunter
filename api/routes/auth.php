<?php
declare(strict_types=1);

function r_login(array $p): array
{
    $b        = body();
    $username = (string) field($b, 'username');
    $password = (string) field($b, 'password');

    $user   = verify_login($username, $password);
    $issued = issue_token((int) $user['id'], 'sign in');

    return [
        'token'      => $issued['token'],
        'expires_at' => $issued['session']['expires_at'],
        'user'       => public_user($user),
    ];
}

function r_me(array $p): array
{
    $user = auth();

    return [
        'user'    => public_user($user),
        'session' => ['label' => $user['label'], 'expires_at' => $user['expires_at']],
    ];
}

function r_logout(array $p): array
{
    $user = auth();
    revoke_session((int) $user['session_id']);

    return ['ok' => true];
}

/** Change my own password. Every other session of mine is signed out. */
function r_change_password(array $p): array
{
    $user = auth();
    $b    = body();

    $current = (string) field($b, 'current_password');
    $next    = (string) field($b, 'new_password');

    check_password_policy($next);

    $stmt = db()->prepare('SELECT id, username, password_hash, status FROM users WHERE id = :id');
    $stmt->execute([':id' => $user['id']]);
    $row = $stmt->fetch();

    if (!$row || $row['password_hash'] === null || !password_verify($current, $row['password_hash'])) {
        fail('Your current password is not correct.', 403);
    }
    if ($current === $next) {
        fail('The new password must be different.', 422);
    }

    db()->prepare('UPDATE users SET password_hash = :hash, updated_at = now() WHERE id = :id')
        ->execute([':hash' => hash_password($next), ':id' => $user['id']]);

    // Changing a password should end every other session, but not this request's.
    db()->prepare(
        'UPDATE sessions SET revoked_at = now()
          WHERE user_id = :id AND id <> :session AND revoked_at IS NULL'
    )->execute([':id' => $user['id'], ':session' => $user['session_id']]);

    return ['ok' => true];
}
