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
require $root . '/src/gateway.php';

require $root . '/routes/auth.php';
require $root . '/routes/candidates.php';
require $root . '/routes/resumes.php';
require $root . '/routes/runs.php';
require $root . '/routes/deliveries.php';
require $root . '/routes/settings.php';
require $root . '/routes/intake.php';

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

// method  path pattern                      handler
$routes = [
    ['GET',    '/',                           'r_index'],
    ['GET',    '/health',                     'r_health'],

    ['POST',   '/auth/login',                 'r_login'],
    ['GET',    '/me',                         'r_login'],
    ['POST',   '/auth/password',              'r_change_password'],
    ['POST',   '/admins',                     'r_create_admin'],

    ['GET',    '/candidates',                 'r_candidates_list'],
    ['POST',   '/candidates',                 'r_candidates_upsert'],
    ['GET',    '/candidates/{id}',            'r_candidate_get'],
    ['PATCH',  '/candidates/{id}',            'r_candidate_patch'],
    ['POST',   '/candidates/{id}/resumes',    'r_resume_upload'],

    ['POST',   '/intake',                     'r_intake'],

    ['GET',    '/resumes/{id}',               'r_resume_get'],
    ['GET',    '/resumes/{id}/file',          'r_resume_file'],
    ['POST',   '/resumes/{id}/runs',          'r_run_create'],

    ['GET',    '/runs',                       'r_runs_list'],
    ['GET',    '/runs/{id}',                  'r_run_get'],
    ['PATCH',  '/runs/{id}',                  'r_run_patch'],
    ['POST',   '/runs/{id}/render',           'r_run_render'],
    ['POST',   '/runs/{id}/deliver',          'r_run_deliver'],
    ['POST',   '/runs/{id}/retry',            'r_run_retry'],
    ['GET',    '/runs/{id}/output',           'r_run_output'],

    ['GET',    '/deliveries',                 'r_deliveries_list'],
    ['POST',   '/deliveries',                 'r_delivery_create'],
    ['GET',    '/deliveries/{id}/file',       'r_delivery_file'],

    ['GET',    '/settings',                   'r_settings_get'],
    ['PUT',    '/settings',                   'r_settings_put'],
];

try {
    $allowedForPath = [];

    foreach ($routes as [$routeMethod, $pattern, $handler]) {
        $regex = '#^' . preg_replace('/\{[a-z_]+\}/', '([^/]+)', str_replace('#', '\#', $pattern)) . '$#';
        if (!preg_match($regex, $path, $m)) {
            continue;
        }
        $allowedForPath[] = $routeMethod;
        if ($routeMethod !== $method) {
            continue;
        }

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
    if ($status === 401) {
        header('WWW-Authenticate: Basic realm="headhunter"');
    }
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
    return ['service' => 'headhunter-api', 'auth' => 'HTTP Basic, credentials are PostgreSQL roles'];
}

function r_health(array $p): array
{
    // Deliberately unauthenticated: it must not need a role to answer.
    return ['ok' => true, 'time' => gmdate('c')];
}
