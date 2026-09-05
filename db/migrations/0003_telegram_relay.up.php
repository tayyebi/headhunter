<?php
declare(strict_types=1);

/**
 * Apps Script became a dumb relay: the app now needs a chat id to alert on
 * webhook errors, and its own replay-guard against Telegram redelivering an
 * update (the gateway forwards every update it receives, unopened).
 */
return function (PDO $pdo): void {
    $pdo->exec(<<<'SQL'
        ALTER TABLE settings ADD COLUMN telegram_admin_chat_id text NOT NULL DEFAULT '';

        CREATE TABLE telegram_updates (
            update_id   bigint      PRIMARY KEY,
            received_at timestamptz NOT NULL DEFAULT now()
        );
        SQL);
};
