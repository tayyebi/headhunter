<?php
declare(strict_types=1);

function templates_root(): string
{
    return rtrim(TEMPLATES_DIR, '/');
}

function h(mixed $value): string
{
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Persian resumes must be laid out right to left. The model reports the language,
 * but we verify against the actual glyphs so a wrong label cannot ruin the page.
 */
function resume_direction(array $data): string
{
    $text = '';
    array_walk_recursive($data, static function ($value) use (&$text): void {
        if (is_string($value)) {
            $text .= $value;
        }
    });

    $letters = preg_match_all('/\p{L}/u', $text);
    if ($letters === 0 || $letters === false) {
        return ($data['language'] ?? '') === 'fa' ? 'rtl' : 'ltr';
    }
    $arabic = (int) preg_match_all('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text);

    return ($arabic / $letters) >= 0.30 ? 'rtl' : 'ltr';
}

/** Local font files, when someone has run scripts/fetch-fonts.sh. */
function resume_font_files(): array
{
    $found = [];
    foreach (['Vazirmatn-Regular.woff2', 'Vazirmatn-Bold.woff2'] as $name) {
        $path = templates_root() . '/assets/' . $name;
        if (is_file($path)) {
            $found[$name] = $path;
        }
    }
    return count($found) === 2 ? $found : [];
}

function render_resume_html(array $data): string
{
    $d     = $data;
    $dir   = resume_direction($data);
    $fonts = resume_font_files();

    ob_start();
    require templates_root() . '/resume.php';
    return (string) ob_get_clean();
}

/** HTML in, PDF bytes out, via the Gotenberg container. */
function gotenberg_html_to_pdf(string $html, array $fontFiles): string
{
    $tmpDir = sys_get_temp_dir() . '/gotenberg-' . bin2hex(random_bytes(6));
    if (!mkdir($tmpDir, 0700) && !is_dir($tmpDir)) {
        throw new RuntimeException('Cannot create a temporary directory for rendering.');
    }

    $indexPath = $tmpDir . '/index.html';
    file_put_contents($indexPath, $html);

    // Gotenberg keys parts by their filename, so the field names only have to be distinct.
    $form = [
        'index'          => new CURLFile($indexPath, 'text/html', 'index.html'),
        'paperWidth'     => '8.27',
        'paperHeight'    => '11.7',
        'marginTop'      => '0.5',
        'marginBottom'   => '0.5',
        'marginLeft'     => '0.5',
        'marginRight'    => '0.5',
        'preferCssPageSize' => 'false',
        'printBackground'   => 'true',
    ];

    $i = 0;
    foreach ($fontFiles as $name => $path) {
        $form['font' . $i++] = new CURLFile($path, 'font/woff2', $name);
    }

    try {
        [$status, $raw, $curlError] = http_send(
            'POST',
            rtrim(GOTENBERG_URL, '/') . '/forms/chromium/convert/html',
            [],
            $form,
            120
        );
    } finally {
        @unlink($indexPath);
        @rmdir($tmpDir);
    }

    if ($curlError !== '') {
        throw new RuntimeException('Could not reach the PDF renderer: ' . $curlError);
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('PDF renderer returned HTTP ' . $status . ': ' . substr($raw, 0, 300));
    }
    if ($raw === '' || !str_starts_with($raw, '%PDF')) {
        throw new RuntimeException('PDF renderer returned something that is not a PDF.');
    }

    return $raw;
}

/** Render a run's edited content and record the result on the run. Returns the relative path. */
function render_run_pdf(PDO $pdo, int $runId, array $data): string
{
    try {
        $bytes    = gotenberg_html_to_pdf(render_resume_html($data), resume_font_files());
        $relative = store_output($bytes, $runId);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("UPDATE runs SET status = 'failed', error = :error, finished_at = now() WHERE id = :id");
        $stmt->execute([':error' => $e->getMessage(), ':id' => $runId]);
        throw $e;
    }

    $stmt = $pdo->prepare(
        "UPDATE runs SET output_path = :path, status = 'ready', error = NULL, finished_at = now() WHERE id = :id"
    );
    $stmt->execute([':path' => $relative, ':id' => $runId]);

    return $relative;
}
