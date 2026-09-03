<?php
declare(strict_types=1);

/** An error we deliberately return to the client. */
class ApiError extends RuntimeException
{
    public array $extra;

    public function __construct(string $message, int $status = 400, array $extra = [])
    {
        parent::__construct($message, $status);
        $this->extra = $extra;
    }
}

function fail(string $message, int $status = 400, array $extra = []): never
{
    throw new ApiError($message, $status, $extra);
}

function cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Gateway-Secret');
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Access-Control-Max-Age: 86400');
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Decoded JSON request body, or [] when the request is a form/multipart. */
function body(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($type, 'multipart/form-data') || str_contains($type, 'x-www-form-urlencoded')) {
        return $cache = $_POST;
    }

    $raw = file_get_contents('php://input');
    if (trim((string) $raw) === '') {
        return $cache = [];
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        fail('Request body is not valid JSON.', 400);
    }
    return $cache = $decoded;
}

function field(array $source, string $key, mixed $default = null): mixed
{
    if (!array_key_exists($key, $source) || $source[$key] === '') {
        if (func_num_args() < 3) {
            fail("Missing required field: {$key}", 422);
        }
        return $default;
    }
    return $source[$key];
}

function query(string $key, ?string $default = null): ?string
{
    $value = $_GET[$key] ?? null;
    return ($value === null || $value === '') ? $default : (string) $value;
}

/** Stream a file to the client and stop. */
function send_file(string $absolute, string $downloadName, string $mime): never
{
    if (!is_file($absolute)) {
        fail('File is missing from storage.', 410);
    }
    http_response_code(200);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($absolute));
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
    readfile($absolute);
    exit;
}
