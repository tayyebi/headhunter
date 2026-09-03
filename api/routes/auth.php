<?php
declare(strict_types=1);

/** Who am I, according to the database itself. */
function r_login(array $p): array
{
    $row = db()->query(
        "SELECT current_user AS role,
                pg_has_role(current_user, 'hh_admin', 'member') AS is_admin,
                (SELECT rolcreaterole FROM pg_roles WHERE rolname = current_user) AS can_create_admins"
    )->fetch();

    return [
        'role'             => $row['role'],
        'is_admin'         => (bool) $row['is_admin'],
        'can_create_admins'=> (bool) $row['can_create_admins'],
    ];
}

/**
 * PostgreSQL lets an ordinary role change its own password, so this needs no
 * elevation. ALTER ROLE cannot take bound parameters, hence the explicit quoting.
 */
function r_change_password(array $p): array
{
    $new = (string) field(body(), 'new_password');
    if (strlen($new) < 10) {
        fail('New password must be at least 10 characters.', 422);
    }

    [$user, $currentPassword] = credentials();
    if ($new === $currentPassword) {
        fail('New password must differ from the current one.', 422);
    }

    $pdo = db();
    $pdo->exec('ALTER ROLE ' . quote_ident($user) . ' PASSWORD ' . quote_literal($pdo, $new));

    return ['ok' => true, 'role' => $user];
}

/** Create a colleague. Only works for a role holding CREATEROLE; otherwise the database says no. */
function r_create_admin(array $p): array
{
    $b        = body();
    $username = (string) field($b, 'username');
    $password = (string) field($b, 'password');

    if (strlen($password) < 10) {
        fail('Password must be at least 10 characters.', 422);
    }

    $pdo = db();
    $pdo->exec(
        'CREATE ROLE ' . quote_ident($username) .
        ' LOGIN PASSWORD ' . quote_literal($pdo, $password) .
        ' IN ROLE hh_admin'
    );

    return ['ok' => true, 'role' => $username];
}
