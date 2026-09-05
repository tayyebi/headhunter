<?php
declare(strict_types=1);

/**
 * This is the only file that knows Telegram exists. The gateway (gas/Code.gs)
 * is a dumb relay: it holds the bot token and physically reaches
 * api.telegram.org, but every decision about what to call and what to send
 * is made here.
 */

/**
 * Ask the gateway to call a Telegram Bot API method. Returns Telegram's
 * `result`. Throws on any failure (bad secret, Telegram rejected the call,
 * gateway unreachable).
 */
function telegram_call(
    array $settings,
    string $method,
    array $params,
    ?string $attachmentUrl = null,
    ?string $attachmentParam = null,
    ?string $idempotencyKey = null
): mixed {
    $url = trim((string) $settings['gateway_url']);
    if ($url === '') {
        throw new RuntimeException('No gateway_url is configured. Set one in Settings.');
    }
    $url .= (str_contains($url, '?') ? '&' : '?') . 'secret=' . rawurlencode((string) $settings['gateway_secret']);

    $envelope = ['method' => $method, 'params' => $params];
    if ($attachmentUrl !== null) {
        $envelope['attachment_url']   = $attachmentUrl;
        $envelope['attachment_param'] = $attachmentParam;
    }
    if ($idempotencyKey !== null) {
        $envelope['idempotency_key'] = $idempotencyKey;
    }

    [$status, $raw, $curlError] = http_post_json($url, [], $envelope, 60);

    if ($curlError !== '') {
        throw new RuntimeException('Gateway unreachable: ' . $curlError);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Gateway returned HTTP ' . $status . ': ' . substr($raw, 0, 300));
    }

    $body = json_decode($raw, true);
    if (!is_array($body) || !($body['ok'] ?? false)) {
        throw new RuntimeException('Telegram call failed: ' . substr($raw, 0, 300));
    }

    return $body['result'] ?? null;
}

/** Fetches the raw bytes of a Telegram file through the gateway. */
function telegram_download(array $settings, string $fileId, string $fileName = ''): string
{
    $url = trim((string) $settings['gateway_url']);
    if ($url === '') {
        throw new RuntimeException('No gateway_url is configured. Set one in Settings.');
    }
    $url .= (str_contains($url, '?') ? '&' : '?') . 'secret=' . rawurlencode((string) $settings['gateway_secret']);

    $params = ['file_id' => $fileId];
    if ($fileName !== '') {
        $params['file_name'] = $fileName;
    }

    [$status, $raw, $curlError] = http_post_json($url, [], ['method' => 'downloadFile', 'params' => $params], 60);

    if ($curlError !== '') {
        throw new RuntimeException('Gateway unreachable: ' . $curlError);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Gateway returned HTTP ' . $status . ' downloading file.');
    }

    return $raw;
}

function telegram_send_message(array $settings, string $chatId, string $text, ?string $idempotencyKey = null): void
{
    telegram_call($settings, 'sendMessage', ['chat_id' => $chatId, 'text' => $text], null, null, $idempotencyKey);
}

function telegram_send_document(
    array $settings,
    string $chatId,
    string $attachmentUrl,
    string $fileName,
    string $caption,
    ?string $idempotencyKey = null
): void {
    $params = ['chat_id' => $chatId];
    if ($caption !== '') {
        $params['caption'] = substr($caption, 0, 1024);
    }
    telegram_call($settings, 'sendDocument', $params, $attachmentUrl, 'document', $idempotencyKey);
}

/**
 * Acknowledges receipt of an incoming message with a 👍 reaction. Best-effort:
 * a reaction failing (message too old, chat does not allow reactions, gateway
 * hiccup) must never block actually handling the message.
 */
function telegram_react(array $settings, string $chatId, int $messageId, string $emoji = '👍'): void
{
    try {
        telegram_call($settings, 'setMessageReaction', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'reaction'   => [['type' => 'emoji', 'emoji' => $emoji]],
        ]);
    } catch (Throwable $e) {
        error_log('[telegram] failed to react: ' . $e->getMessage());
    }
}

/** Best-effort PV alert. Swallows its own errors so a broken chat id or a
 * down gateway never turns into a second unhandled error. */
function telegram_notify_admin(array $settings, string $text): void
{
    $chatId = trim((string) ($settings['telegram_admin_chat_id'] ?? ''));
    if ($chatId === '') {
        return;
    }
    try {
        telegram_send_message($settings, $chatId, $text);
    } catch (Throwable $e) {
        error_log('[telegram] failed to notify admin: ' . $e->getMessage());
    }
}
