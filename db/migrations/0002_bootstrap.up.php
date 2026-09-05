<?php
declare(strict_types=1);

/**
 * Creates the single application role and seeds the first owner and gateway
 * accounts. This used to be a one-time shell script run only by
 * docker-entrypoint-initdb.d; now it is an ordinary migration, tracked the
 * same way as every schema change, so it runs exactly once no matter how
 * many times the containers boot.
 *
 * Generated secrets never touch the repository: only a bcrypt hash and a
 * SHA-256 hash reach the database, and the plaintext exists only in the one
 * log line below (and, best-effort, in data/secrets/initial-credentials.txt).
 */
require_once dirname(__DIR__, 2) . '/api/src/auth.php';

return function (PDO $pdo): void {
    $pdo->exec('CREATE ROLE api LOGIN');
    $pdo->exec('GRANT USAGE ON SCHEMA public TO api');
    $pdo->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO api');
    $pdo->exec('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO api');
    $pdo->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO api');
    $pdo->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO api');

    $ownerPassword = new_token();
    $gatewayToken  = new_token();

    $pdo->prepare(
        "INSERT INTO users (username, display_name, password_hash, role)
         VALUES ('owner', 'Owner', :hash, 'owner')"
    )->execute([':hash' => hash_password($ownerPassword)]);

    $pdo->exec("INSERT INTO users (username, display_name, role) VALUES ('gateway', 'Telegram gateway', 'gateway')");

    $pdo->prepare(
        "INSERT INTO sessions (user_id, token_hash, label, expires_at)
         SELECT id, :hash, 'bootstrap token', NULL FROM users WHERE username = 'gateway'"
    )->execute([':hash' => hash_token($gatewayToken)]);

    $credentials = "owner password : {$ownerPassword}\ngateway token  : {$gatewayToken}";

    fwrite(STDERR, "\n==============================================================\n");
    fwrite(STDERR, " Initial credentials (shown once)\n");
    fwrite(STDERR, "--------------------------------------------------------------\n");
    fwrite(STDERR, $credentials . "\n");
    fwrite(STDERR, "--------------------------------------------------------------\n");
    fwrite(STDERR, " owner password -> sign in to the admin app as user 'owner'\n");
    fwrite(STDERR, " gateway token  -> Apps Script property API_TOKEN\n");
    fwrite(STDERR, "==============================================================\n\n");

    $secretsFile = '/var/www/html/secrets/initial-credentials.txt';
    if (is_dir(dirname($secretsFile)) && @file_put_contents($secretsFile, $credentials . "\n") !== false) {
        @chmod($secretsFile, 0600);
        fwrite(STDERR, "Also written to ./data/secrets/initial-credentials.txt\n");
    } else {
        fwrite(STDERR, "Could not write ./data/secrets/initial-credentials.txt - copy the values above now.\n");
    }
};
