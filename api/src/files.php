<?php
declare(strict_types=1);

function storage_root(): string
{
    return rtrim(STORAGE_DIR, '/');
}

function storage_abs(string $relative): string
{
    $relative = ltrim($relative, '/');
    if (str_contains($relative, '..')) {
        fail('Bad storage path.', 400);
    }
    return storage_root() . '/' . $relative;
}

function detect_mime(string $absolute, string $fallback = 'application/octet-stream'): string
{
    if (!function_exists('finfo_open')) {
        return $fallback;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $absolute) : false;
    if ($finfo) {
        finfo_close($finfo);
    }
    return $mime ?: $fallback;
}

/** Keep a sane extension without trusting the client's filename. */
function safe_extension(string $filename, string $mime): string
{
    $known = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc',
        'text/plain' => 'txt',
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
    ];
    if (isset($known[$mime])) {
        return $known[$mime];
    }
    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'bin';
}

/**
 * Move an uploaded file into content-addressed storage.
 * Returns [relative_path, sha256, size_bytes, mime].
 */
function store_original(string $tmpPath, string $originalName): array
{
    $sha  = hash_file('sha256', $tmpPath);
    $size = (int) filesize($tmpPath);
    $mime = detect_mime($tmpPath);
    $ext  = safe_extension($originalName, $mime);

    $relative = sprintf('originals/%s/%s.%s', substr($sha, 0, 2), $sha, $ext);
    $absolute = storage_abs($relative);

    if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0775, true) && !is_dir(dirname($absolute))) {
        fail('Cannot create storage directory.', 500);
    }

    // Content addressed: an identical re-upload is a no-op.
    if (!is_file($absolute)) {
        $moved = is_uploaded_file($tmpPath)
            ? move_uploaded_file($tmpPath, $absolute)
            : rename($tmpPath, $absolute);
        if (!$moved) {
            fail('Cannot write file to storage.', 500);
        }
        @chmod($absolute, 0664);
    }

    return [$relative, $sha, $size, $mime];
}

function store_output(string $bytes, int $runId): string
{
    $relative = sprintf('outputs/%d/%d-%s.pdf', intdiv($runId, 1000), $runId, date('Ymd-His'));
    $absolute = storage_abs($relative);

    if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0775, true) && !is_dir(dirname($absolute))) {
        throw new RuntimeException('Cannot create output directory.');
    }
    if (file_put_contents($absolute, $bytes) === false) {
        throw new RuntimeException('Cannot write output PDF.');
    }
    @chmod($absolute, 0664);

    return $relative;
}

/** The single uploaded file on this request, whatever the form field is called. */
function uploaded_file(string $preferredField = 'file'): array
{
    $upload = $_FILES[$preferredField] ?? null;
    if ($upload === null && count($_FILES) === 1) {
        $upload = reset($_FILES);
    }
    if (!is_array($upload)) {
        fail('No file was uploaded.', 422);
    }
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail('Upload failed (error code ' . (int) $upload['error'] . '). Check upload_max_filesize.', 422);
    }
    return $upload;
}
