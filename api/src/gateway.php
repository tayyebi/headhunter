<?php
declare(strict_types=1);

/**
 * Push one delivery to the configured gateway webhook. This API has no idea the
 * gateway happens to speak Telegram: it sends an opaque external_ref, some text,
 * and a URL where the attachment can be fetched.
 */
function gateway_push(array $settings, array $payload): array
{
    $url = trim((string) $settings['gateway_url']);
    if ($url === '') {
        throw new RuntimeException('No gateway_url is configured. Set one in Settings.');
    }

    $headers = ['X-Gateway-Secret: ' . (string) $settings['gateway_secret']];
    [$status, $raw, $curlError] = http_post_json($url, $headers, $payload, 60);

    if ($curlError !== '') {
        throw new RuntimeException('Gateway unreachable: ' . $curlError);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Gateway returned HTTP ' . $status . ': ' . substr($raw, 0, 300));
    }

    return ['status' => $status, 'body' => substr($raw, 0, 1000)];
}

function delivery_payload(array $delivery): array
{
    $base = rtrim(BASE_URL, '/');

    return [
        'external_ref' => $delivery['external_ref'],
        'kind'         => $delivery['kind'],
        'text'         => $delivery['body'],
        'file_url'     => $delivery['file_path'] === null ? null : $base . '/deliveries/' . $delivery['id'] . '/file',
        'file_name'    => $delivery['file_name'],
        'delivery_id'  => (int) $delivery['id'],
    ];
}
