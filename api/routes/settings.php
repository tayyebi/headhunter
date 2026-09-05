<?php
declare(strict_types=1);

function load_settings(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM settings WHERE id = 1')->fetch();
    if (!$row) {
        fail('Settings row is missing.', 500);
    }
    return $row;
}

/** The API key is never echoed back in full. */
function r_settings_get(array $p): array
{
    $s   = load_settings(db());
    $key = (string) $s['ai_api_key'];

    return ['settings' => [
        'system_instruction' => $s['system_instruction'],
        'ai_base_url'        => $s['ai_base_url'],
        'ai_model'           => $s['ai_model'],
        'ai_api_key_set'     => $key !== '',
        'ai_api_key_hint'    => $key === '' ? '' : ('…' . substr($key, -4)),
        'temperature'        => (float) $s['temperature'],
        'gateway_url'        => $s['gateway_url'],
        'gateway_secret_set' => $s['gateway_secret'] !== '',
        'telegram_admin_chat_id' => $s['telegram_admin_chat_id'],
        'updated_at'         => $s['updated_at'],
    ]];
}

function r_settings_put(array $p): array
{
    $b      = body();
    $sets   = [];
    $params = [];

    $plain = ['system_instruction', 'ai_base_url', 'ai_model', 'gateway_url', 'telegram_admin_chat_id'];
    foreach ($plain as $column) {
        if (array_key_exists($column, $b)) {
            $sets[] = "{$column} = :{$column}";
            $params[":{$column}"] = (string) $b[$column];
        }
    }

    if (array_key_exists('temperature', $b)) {
        $sets[] = 'temperature = :temperature';
        $params[':temperature'] = (float) $b['temperature'];
    }

    // Secrets: an empty string means "leave the stored value alone".
    foreach (['ai_api_key', 'gateway_secret'] as $secret) {
        if (!empty($b[$secret])) {
            $sets[] = "{$secret} = :{$secret}";
            $params[":{$secret}"] = (string) $b[$secret];
        }
    }

    if ($sets === []) {
        fail('Nothing to update.', 422);
    }
    $sets[] = 'updated_at = now()';

    $stmt = db()->prepare('UPDATE settings SET ' . implode(', ', $sets) . ' WHERE id = 1');
    $stmt->execute($params);

    return r_settings_get([]);
}
