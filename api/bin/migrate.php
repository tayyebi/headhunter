<?php
declare(strict_types=1);

/**
 * Runs on every boot of the api and worker containers, before either starts
 * serving. Applying a schema change is now "drop a new
 * db/migrations/NNNN_name.up.php (+ .down.php) pair in the repo", never a
 * by-hand psql session.
 *
 * Usage: php migrate.php [up|down]   (default: up)
 */
$root = dirname(__DIR__);
require $root . '/src/config.php';
require $root . '/src/migrate.php';

$direction = $argv[1] ?? 'up';
if (!in_array($direction, ['up', 'down'], true)) {
    fwrite(STDERR, "Usage: php migrate.php [up|down]\n");
    exit(1);
}

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME);
$pdo = new PDO($dsn, DB_MIGRATE_USER, '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$dir = dirname($root) . '/db/migrations';

if ($direction === 'up') {
    migrate_up($pdo, $dir);
    fwrite(STDERR, "[migrate] up to date\n");
} else {
    migrate_down($pdo, $dir);
}
