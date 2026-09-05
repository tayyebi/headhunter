<?php
declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/src/config.php';
require $root . '/src/http.php';
require $root . '/src/db.php';
require $root . '/src/auth.php';
require $root . '/src/files.php';
require $root . '/src/client.php';
require $root . '/src/ai.php';
require $root . '/src/pdf.php';
require $root . '/src/telegram.php';
require $root . '/src/gateway.php';

require $root . '/routes/auth.php';
require $root . '/routes/users.php';
require $root . '/routes/candidates.php';
require $root . '/routes/resumes.php';
require $root . '/routes/runs.php';
require $root . '/routes/deliveries.php';
require $root . '/routes/settings.php';
require $root . '/routes/telegram.php';

cors();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
if ($path === '') {
    $path = '/';
}

/**
 * The whole authorization model. Every route names the capability it needs, and
 * src/auth.php maps capabilities to roles. Nothing else in the codebase decides
 * who may do what, so this table is the single thing to read or audit.
 *
 *   public  no token at all
 *   user    any signed-in account, including the gateway machine account
 *   admin   a headhunter
 *   owner   account administration
 */
$routes = [
    ['GET',    '/',                           'r_index',           'public'],
    ['GET',    '/health',                     'r_health',          'public'],

    ['POST',   '/auth/login',                 'r_login',           'public'],
    ['POST',   '/auth/logout',                'r_logout',          'user'],
    ['GET',    '/auth/me',                    'r_me',              'user'],
    ['GET',    '/me',                         'r_me',              'user'],
    ['POST',   '/auth/password',              'r_change_password', 'user'],

    ['GET',    '/users',                      'r_users_list',      'owner'],
    ['POST',   '/users',                      'r_user_create',     'owner'],
    ['PATCH',  '/users/{id}',                 'r_user_patch',      'owner'],
    ['DELETE', '/users/{id}',                 'r_user_delete',     'owner'],
    ['POST',   '/users/{id}/tokens',          'r_user_token',      'owner'],
    ['GET',    '/sessions',                   'r_sessions_list',   'owner'],
    ['DELETE', '/sessions/{id}',              'r_session_revoke',  'owner'],

    ['GET',    '/candidates',                 'r_candidates_list', 'admin'],
    ['POST',   '/candidates',                 'r_candidates_upsert', 'admin'],
    ['GET',    '/candidates/{id}',            'r_candidate_get',   'admin'],
    ['PATCH',  '/candidates/{id}',            'r_candidate_patch', 'admin'],
    ['POST',   '/candidates/{id}/resumes',    'r_resume_upload',   'admin'],

    // The gateway's two endpoints: forward a Telegram update, fetch a file we asked it to send.
    ['POST',   '/telegram/webhook',           'r_telegram_webhook', 'user'],
    ['GET',    '/deliveries/{id}/file',       'r_delivery_file',   'user'],

    ['GET',    '/resumes/{id}',               'r_resume_get',      'admin'],
    ['GET',    '/resumes/{id}/file',          'r_resume_file',     'admin'],
    ['POST',   '/resumes/{id}/runs',          'r_run_create',      'admin'],

    ['GET',    '/runs',                       'r_runs_list',       'admin'],
    ['GET',    '/runs/{id}',                  'r_run_get',         'admin'],
    ['PATCH',  '/runs/{id}',                  'r_run_patch',       'admin'],
    ['POST',   '/runs/{id}/render',           'r_run_render',      'admin'],
    ['POST',   '/runs/{id}/deliver',          'r_run_deliver',     'admin'],
    ['POST',   '/runs/{id}/retry',            'r_run_retry',       'admin'],
    ['GET',    '/runs/{id}/output',           'r_run_output',      'admin'],

    ['GET',    '/deliveries',                 'r_deliveries_list', 'admin'],
    ['POST',   '/deliveries',                 'r_delivery_create', 'admin'],

    ['GET',    '/settings',                   'r_settings_get',    'admin'],
    ['PUT',    '/settings',                   'r_settings_put',    'admin'],
];

try {
    $allowedForPath = [];

    foreach ($routes as [$routeMethod, $pattern, $handler, $capability]) {
        $regex = '#^' . preg_replace('/\{[a-z_]+\}/', '([^/]+)', str_replace('#', '\#', $pattern)) . '$#';
        if (!preg_match($regex, $path, $m)) {
            continue;
        }
        $allowedForPath[] = $routeMethod;
        if ($routeMethod !== $method) {
            continue;
        }

        require_capability($capability);

        $result = $handler(array_slice($m, 1));
        json_out($result === null ? ['ok' => true] : $result);
    }

    if ($allowedForPath !== []) {
        header('Allow: ' . implode(', ', array_unique($allowedForPath)));
        fail('Method not allowed for this path.', 405);
    }
    fail('No such endpoint: ' . $method . ' ' . $path, 404);
} catch (ApiError $e) {
    json_out(['error' => $e->getMessage()] + $e->extra, $e->getCode() ?: 400);
} catch (PDOException $e) {
    $status = pdo_status($e);
    error_log('[api] pdo ' . $e->getCode() . ': ' . $e->getMessage());
    $payload = ['error' => pdo_message($e), 'sqlstate' => pdo_sqlstate($e)];
    if (APP_DEBUG) {
        $payload['detail'] = $e->getMessage();
    }
    json_out($payload, $status);
} catch (Throwable $e) {
    error_log('[api] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $payload = ['error' => 'Internal error.'];
    if (APP_DEBUG) {
        $payload['detail'] = $e->getMessage();
        $payload['where']  = $e->getFile() . ':' . $e->getLine();
    }
    json_out($payload, 500);
}

function r_index(array $p): array
{
    return ['service' => 'headhunter-api', 'auth' => 'Bearer token from POST /auth/login'];
}

function r_health(array $p): array
{
    // Deliberately unauthenticated, and deliberately does not touch the database.
    return ['ok' => true, 'time' => gmdate('c')];
}
