<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE settings ADD COLUMN gateway_secret text NOT NULL DEFAULT ''");
};
