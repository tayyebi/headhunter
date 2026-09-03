<?php
declare(strict_types=1);

/**
 * The gateway's single call. Nothing here knows what Telegram is: `external_ref`
 * is an opaque string the caller owns, and the file is just a file.
 *
 * multipart/form-data: external_ref, display_name?, phone?, file
 */
function r_intake(array $p): array
{
    $b   = body();
    $ref = (string) field($b, 'external_ref');

    $candidate = upsert_candidate(
        $ref,
        (string) ($b['display_name'] ?? ''),
        isset($b['phone']) && $b['phone'] !== '' ? (string) $b['phone'] : null
    );

    $resume = attach_resume((int) $candidate['id'], uploaded_file(), 'gateway');

    return [
        'candidate' => ['id' => $candidate['id'], 'external_ref' => $candidate['external_ref']],
        'resume'    => ['id' => $resume['id'], 'mime' => $resume['mime'], 'size_bytes' => $resume['size_bytes']],
    ];
}
