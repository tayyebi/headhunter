<?php
declare(strict_types=1);

/** Minimal outbound HTTP. Returns [status, raw_body, curl_error]. */
function http_send(
    string $method,
    string $url,
    array $headers = [],
    string|array|null $body = null,
    int $timeout = 180
): array {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if (strtoupper($method) === 'POST') {
        $options[CURLOPT_POST] = true;
    } else {
        $options[CURLOPT_CUSTOMREQUEST] = $method;
    }
    curl_setopt_array($ch, $options);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    return [$status, $raw === false ? '' : (string) $raw, $error];
}

function http_post_json(string $url, array $headers, array $payload, int $timeout = 180): array
{
    $headers[] = 'Content-Type: application/json';
    return http_send('POST', $url, $headers, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $timeout);
}
