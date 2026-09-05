<?php
declare(strict_types=1);

/** Consolidate the Apps Script endpoint and its required query-string secret. */
return function (PDO $pdo): void {
    $pdo->exec(<<<'SQL'
        UPDATE settings
           SET gateway_url = gateway_url ||
               CASE WHEN position('?' in gateway_url) > 0 THEN '&' ELSE '?' END ||
               'secret=' || gateway_secret,
               updated_at = now()
         WHERE gateway_url <> ''
           AND gateway_secret <> ''
           AND gateway_url !~ '(^|[?&])secret=';

        ALTER TABLE settings DROP COLUMN gateway_secret;
        SQL);
};
