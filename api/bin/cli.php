<?php
declare(strict_types=1);

// Operator CLI. Run inside the API container: php /var/www/html/api/bin/cli.php ...
$root = dirname(__DIR__);
require $root . '/src/config.php';
require $root . '/src/http.php';
require $root . '/src/db.php';
require $root . '/src/auth.php';
require $root . '/src/client.php';
require $root . '/src/telegram.php';
require $root . '/src/migrate.php';

$args = $argv;
array_shift($args);
$command = array_shift($args) ?? 'help';

function cli_json(mixed $value): never
{
    fwrite(STDOUT, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

function cli_usage(): never
{
    fwrite(STDOUT, <<<'TXT'
Usage:
  cli.php db-status
  cli.php migrations [up|down]
  cli.php settings
  cli.php queue [runs|deliveries]
  cli.php telegram-check [chat_id]
  cli.php webhook <json-file|->
  cli.php api <METHOD> <PATH> [JSON]
  cli.php upload <candidate_id> <file>

The wrapper ./scripts/cli runs these through docker compose exec api.
Set HEADHUNTER_TOKEN for authenticated API and upload commands.
TXT
    . "\n");
    exit(0);
}

$pdo = db();
switch ($command) {
    case 'help': cli_usage();
    case 'db-status':
        cli_json(['database' => $pdo->query('SELECT current_database()')->fetchColumn(), 'user' => $pdo->query('SELECT current_user')->fetchColumn(), 'migrations' => $pdo->query('SELECT name FROM schema_migrations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN)]);
    case 'migrations':
        $direction = $args[0] ?? 'up';
        $direction === 'down' ? migrate_down($pdo, '/var/www/html/db/migrations') : migrate_up($pdo, '/var/www/html/db/migrations');
        cli_json(['ok' => true, 'action' => $direction]);
    case 'settings':
        $s = $pdo->query('SELECT gateway_url, ai_base_url, ai_model, ai_api_key, telegram_admin_chat_id FROM settings WHERE id = 1')->fetch();
        cli_json(['gateway_url_set' => $s['gateway_url'] !== '', 'ai_base_url' => $s['ai_base_url'], 'ai_model' => $s['ai_model'], 'ai_api_key_set' => $s['ai_api_key'] !== '', 'telegram_admin_chat_id' => $s['telegram_admin_chat_id']]);
    case 'queue':
        $table = ($args[0] ?? '') === 'runs' ? 'runs' : 'deliveries';
        cli_json($pdo->query("SELECT status, count(*) AS count FROM {$table} GROUP BY status ORDER BY status")->fetchAll());
    case 'telegram-check':
        $s = $pdo->query('SELECT * FROM settings WHERE id = 1')->fetch();
        $url = (string) $s['gateway_url'];
        $bot = telegram_call($s, 'getMe', []);
        $result = ['gateway_url_set' => $url !== '', 'gateway_secret_in_url' => preg_match('/(?:^|[?&])secret=/', $url) === 1, 'telegram' => ['reachable' => true, 'bot_id' => $bot['id'] ?? null, 'username' => $bot['username'] ?? null]];
        if (($args[0] ?? '') !== '') {
            telegram_send_message($s, $args[0], 'headhunter CLI connectivity test ' . gmdate('c'));
            $result['message_sent'] = true;
        }
        cli_json($result);
    case 'webhook':
        $input = ($args[0] ?? '-') === '-' ? stream_get_contents(STDIN) : file_get_contents($args[0]);
        if ($input === false) throw new RuntimeException('Cannot read webhook JSON.');
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $GLOBALS['__cli_body'] = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
        // Exercise the same handler without an HTTP server.
        require $root . '/routes/settings.php';
        require $root . '/routes/telegram.php';
        cli_json(r_telegram_webhook([]));
    case 'api':
        $method = strtoupper($args[0] ?? 'GET');
        $path = $args[1] ?? '/';
        $payload = $args[2] ?? '';
        $headers = ['Accept: application/json'];
        $token = getenv('HEADHUNTER_TOKEN') ?: '';
        if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
        if ($payload !== '') $headers[] = 'Content-Type: application/json';
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'ignore_errors' => true,
        ]]);
        $response = file_get_contents('http://127.0.0.1' . $path, false, $context);
        if ($response === false) throw new RuntimeException('API request failed.');
        fwrite(STDOUT, $response . "\n");
        exit(preg_match('/\s([45][0-9]{2})\s/', $http_response_header[0] ?? '') ? 1 : 0);
    case 'upload':
        $candidateId = $args[0] ?? '';
        $file = $args[1] ?? '';
        if ($candidateId === '' || !is_file($file)) throw new RuntimeException('Usage: upload <candidate_id> <file>');
        $token = getenv('HEADHUNTER_TOKEN') ?: '';
        if ($token === '') throw new RuntimeException('HEADHUNTER_TOKEN is required.');
        $boundary = '----headhunter' . bin2hex(random_bytes(12));
        $bytes = file_get_contents($file);
        $payload = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . basename($file) . "\"\r\nContent-Type: application/octet-stream\r\n\r\n{$bytes}\r\n--{$boundary}--\r\n";
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$token}\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\nContent-Length: " . strlen($payload),
            'content' => $payload,
            'ignore_errors' => true,
        ]]);
        $response = file_get_contents("http://127.0.0.1/candidates/{$candidateId}/resumes", false, $context);
        if ($response === false) throw new RuntimeException('Upload request failed.');
        fwrite(STDOUT, $response . "\n");
        exit(preg_match('/\s([45][0-9]{2})\s/', $http_response_header[0] ?? '') ? 1 : 0);
    default: cli_usage();
}
