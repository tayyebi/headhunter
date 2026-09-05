<?php
declare(strict_types=1);

/**
 * Standard numbered up/down migrations, applied automatically on every boot
 * of the api and worker containers. Each db/migrations/NNNN_name.up.sql (or
 * .up.php, for the one migration that needs real code — generating and
 * printing secrets) is applied once and recorded in schema_migrations;
 * shipping a schema change is "add a new NNNN_name.up.sql / .down.sql pair",
 * never a by-hand psql session. `migrate_down` rolls back the single most
 * recently applied migration, for manual/CLI use only — nothing calls it on
 * boot.
 */
function migrate_up(PDO $pdo, string $dir): void
{
    with_migration_lock($pdo, function () use ($pdo, $dir) {
        ensure_migrations_table($pdo);
        $applied = applied_migrations($pdo);
        seed_pre_existing_database($pdo, $applied);

        foreach (discover_migrations($dir) as $name => $files) {
            if (in_array($name, $applied, true)) {
                continue;
            }
            if (!isset($files['up'])) {
                throw new RuntimeException("Migration {$name} has no .up file.");
            }

            run_step($pdo, $files['up']);
            $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (:n)')->execute([':n' => $name]);
            fwrite(STDERR, "[migrate] applied {$name}\n");
        }
    });
}

function migrate_down(PDO $pdo, string $dir): void
{
    with_migration_lock($pdo, function () use ($pdo, $dir) {
        ensure_migrations_table($pdo);
        $applied = applied_migrations($pdo);

        if ($applied === []) {
            fwrite(STDERR, "[migrate] nothing to roll back\n");
            return;
        }

        $name  = end($applied);
        $files = discover_migrations($dir)[$name] ?? null;
        if ($files === null || !isset($files['down'])) {
            throw new RuntimeException("Migration {$name} has no .down file.");
        }

        run_step($pdo, $files['down']);
        $pdo->prepare('DELETE FROM schema_migrations WHERE name = :n')->execute([':n' => $name]);
        fwrite(STDERR, "[migrate] rolled back {$name}\n");
    });
}

/** Keeps the api and worker containers, which can boot at the same moment, from racing. */
function with_migration_lock(PDO $pdo, Closure $fn): void
{
    $pdo->exec("SELECT pg_advisory_lock(hashtext('headhunter_migrations'))");
    try {
        $fn();
    } finally {
        $pdo->exec("SELECT pg_advisory_unlock(hashtext('headhunter_migrations'))");
    }
}

function ensure_migrations_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            name       text PRIMARY KEY,
            applied_at timestamptz NOT NULL DEFAULT now()
        )'
    );
}

/** @return list<string> */
function applied_migrations(PDO $pdo): array
{
    return $pdo->query('SELECT name FROM schema_migrations ORDER BY applied_at, name')->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * A database that already has its schema and accounts from before this
 * migration system existed has 0001_initial_schema's tables and, once the
 * `api` role exists, 0002_bootstrap's accounts too. Mark those applied
 * instead of replaying DDL and account creation that already happened.
 */
function seed_pre_existing_database(PDO $pdo, array &$applied): void
{
    if ($applied !== [] || !table_exists($pdo, 'settings')) {
        return;
    }

    mark_applied($pdo, '0001_initial_schema');
    $applied[] = '0001_initial_schema';

    if (role_exists($pdo, 'api')) {
        mark_applied($pdo, '0002_bootstrap');
        $applied[] = '0002_bootstrap';
    }
}

function mark_applied(PDO $pdo, string $name): void
{
    $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (:n) ON CONFLICT DO NOTHING')->execute([':n' => $name]);
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT to_regclass('public.' || :t) IS NOT NULL");
    $stmt->execute([':t' => $table]);
    return (bool) $stmt->fetchColumn();
}

function role_exists(PDO $pdo, string $role): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM pg_roles WHERE rolname = :r');
    $stmt->execute([':r' => $role]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Groups db/migrations/NNNN_name.{up,down}.{sql,php} by "NNNN_name", in
 * filename (i.e. numeric) order.
 *
 * @return array<string, array{up?: string, down?: string}>
 */
function discover_migrations(string $dir): array
{
    $files = array_merge(
        glob(rtrim($dir, '/') . '/*.sql') ?: [],
        glob(rtrim($dir, '/') . '/*.php') ?: []
    );
    sort($files);

    $migrations = [];
    foreach ($files as $file) {
        if (!preg_match('/^(.+)\.(up|down)\.(sql|php)$/', basename($file), $m)) {
            continue;
        }
        $migrations[$m[1]][$m[2]] = $file;
    }
    ksort($migrations);

    return $migrations;
}

/** Runs one .sql file, or one .php file that returns a `function (PDO $pdo): void`, in a transaction. */
function run_step(PDO $pdo, string $file): void
{
    $pdo->beginTransaction();
    try {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            $step = require $file;
            if (!is_callable($step)) {
                throw new RuntimeException(basename($file) . ' must return a callable.');
            }
            $step($pdo);
        } else {
            $pdo->exec((string) file_get_contents($file));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new RuntimeException(basename($file) . ' failed: ' . $e->getMessage(), 0, $e);
    }
}
