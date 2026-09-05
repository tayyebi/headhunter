<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec(<<<'SQL'
        DROP TABLE IF EXISTS telegram_updates;
        ALTER TABLE settings DROP COLUMN IF EXISTS telegram_admin_chat_id;
        SQL);
};
