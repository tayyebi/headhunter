<?php
declare(strict_types=1);

/**
 * The gateway (gas/Code.gs) forwards every Telegram webhook update here,
 * unopened. This is where the app decides what a command means, what counts
 * as a resume, and what to say back — the gateway itself knows none of it.
 */
function r_telegram_webhook(array $p): array
{
    $update = body();

    $updateId = (int) ($update['update_id'] ?? 0);
    if ($updateId !== 0 && !claim_telegram_update($updateId)) {
        return ['ok' => true, 'duplicate' => true];
    }

    $settings = load_settings(db());

    try {
        telegram_handle_update($settings, $update);
    } catch (Throwable $e) {
        error_log('[telegram] webhook error: ' . $e->getMessage());
        telegram_notify_admin(
            $settings,
            'headhunter webhook error on update ' . ($updateId ?: '(no id)') . ":\n" . $e->getMessage()
        );
        throw $e;
    }

    return ['ok' => true];
}

/** True if this update_id has not been seen before. */
function claim_telegram_update(int $updateId): bool
{
    $stmt = db()->prepare('INSERT INTO telegram_updates (update_id) VALUES (:id) ON CONFLICT DO NOTHING');
    $stmt->execute([':id' => $updateId]);
    return $stmt->rowCount() > 0;
}

function telegram_handle_update(array $settings, array $update): void
{
    $message = $update['message'] ?? $update['edited_message'] ?? null;
    if ($message === null) {
        return;
    }

    $chatId = (string) $message['chat']['id'];
    $text   = (string) ($message['text'] ?? '');

    if (str_starts_with($text, '/start')) {
        telegram_send_message($settings, $chatId,
            "سلام! رزومه‌تان را به صورت فایل PDF همین‌جا بفرستید تا بررسی و ویرایش شود.\n\n" .
            'Send your resume here as a PDF file and we will polish it for you.');
        return;
    }

    if (str_starts_with($text, '/whoami')) {
        telegram_send_message($settings, $chatId, telegram_whoami_text($message));
        return;
    }

    $file = telegram_extract_file($message);
    if ($file === null) {
        telegram_send_message($settings, $chatId,
            "لطفاً رزومه را به صورت فایل PDF ارسال کنید.\nPlease send your resume as a PDF file.");
        return;
    }

    $bytes = telegram_download($settings, $file['file_id'], $file['file_name']);
    $tmpPath = tempnam(sys_get_temp_dir(), 'tg');
    file_put_contents($tmpPath, $bytes);

    try {
        $candidate = upsert_candidate('telegram:' . $chatId, telegram_display_name($message['from'] ?? null), null);
        attach_resume((int) $candidate['id'], ['tmp_name' => $tmpPath, 'name' => $file['file_name']], 'gateway');
    } finally {
        @unlink($tmpPath);
    }

    telegram_send_message($settings, $chatId,
        "رزومه شما دریافت شد. پس از بررسی، نسخه ویرایش‌شده برایتان ارسال می‌شود.\n\n" .
        'Got your resume. We will send the polished version back here once it is ready.');
}

/** The document, or the largest photo, whichever the candidate sent. */
function telegram_extract_file(array $message): ?array
{
    if (isset($message['document'])) {
        return [
            'file_id'   => (string) $message['document']['file_id'],
            'file_name' => (string) ($message['document']['file_name'] ?? 'resume.pdf'),
        ];
    }
    if (!empty($message['photo'])) {
        $photo = end($message['photo']);
        return ['file_id' => (string) $photo['file_id'], 'file_name' => 'resume.jpg'];
    }
    return null;
}

function telegram_display_name(?array $from): string
{
    if ($from === null) {
        return '';
    }
    $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return isset($from['username']) ? '@' . $from['username'] : '';
}

/** Dumps the identifiers of whoever/wherever sent /whoami, for operator setup. */
function telegram_whoami_text(array $message): string
{
    $chat = $message['chat'];
    $from = $message['from'] ?? [];

    $lines = ['chat_id: ' . $chat['id'], 'chat_type: ' . $chat['type']];
    if (!empty($chat['title'])) {
        $lines[] = 'chat_title: ' . $chat['title'];
    }
    if (!empty($message['message_thread_id'])) {
        $lines[] = 'topic_id: ' . $message['message_thread_id'];
    }
    $lines[] = 'user_id: ' . ($from['id'] ?? '');
    if (!empty($from['username'])) {
        $lines[] = 'username: @' . $from['username'];
    }
    $name = telegram_display_name($from);
    if ($name !== '') {
        $lines[] = 'name: ' . $name;
    }
    if (!empty($from['language_code'])) {
        $lines[] = 'language_code: ' . $from['language_code'];
    }
    return implode("\n", $lines);
}
