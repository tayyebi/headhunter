<?php
declare(strict_types=1);

/**
 * Push one delivery out. The delivery is already Telegram-shaped by this
 * point (see telegram.php); this just decides sendMessage vs sendDocument.
 */
function gateway_push(array $settings, array $delivery): void
{
    $chatId = preg_replace('/^telegram:/', '', (string) $delivery['external_ref']);

    if ($delivery['kind'] === 'document' && $delivery['file_path'] !== null) {
        $fileUrl = rtrim(BASE_URL, '/') . '/deliveries/' . $delivery['id'] . '/file';
        telegram_send_document(
            $settings,
            $chatId,
            $fileUrl,
            (string) ($delivery['file_name'] ?? ''),
            (string) $delivery['body'],
            (string) $delivery['idempotency_key']
        );
        return;
    }

    telegram_send_message($settings, $chatId, (string) $delivery['body'], (string) $delivery['idempotency_key']);
}
