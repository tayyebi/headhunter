<?php
declare(strict_types=1);

/** The exact object shape the model must return. Also drives the review form in the PWA. */
function resume_schema_hint(): string
{
    return <<<'JSON'
{
  "language": "fa or en",
  "full_name": "",
  "headline": "",
  "summary": "",
  "contact": { "email": "", "phone": "", "location": "", "links": [{"label": "", "url": ""}] },
  "experience": [{"title": "", "company": "", "location": "", "start": "", "end": "", "bullets": [""]}],
  "education": [{"degree": "", "institution": "", "start": "", "end": "", "note": ""}],
  "skills": [{"group": "", "items": [""]}],
  "languages": [{"name": "", "level": ""}],
  "certifications": [{"name": "", "issuer": "", "year": ""}],
  "projects": [{"name": "", "description": "", "link": ""}]
}
JSON;
}

/** Plain text out of a PDF, used when the endpoint will not accept a file part. */
function pdf_to_text(string $absolutePath): string
{
    $out = [];
    $code = 0;
    exec('pdftotext -layout -enc UTF-8 ' . escapeshellarg($absolutePath) . ' - 2>/dev/null', $out, $code);
    return $code === 0 ? implode("\n", $out) : '';
}

/**
 * Ask the configured OpenAI-compatible endpoint to polish a resume into structured JSON.
 * Returns [data, model, input_mode].
 */
function ai_extract(array $settings, string $absolutePath, string $mime, string $originalName): array
{
    $key = trim((string) $settings['ai_api_key']);
    if ($key === '') {
        throw new RuntimeException('No AI API key is configured. Set one in Settings.');
    }

    $isPdf   = $mime === 'application/pdf';
    $isText  = str_starts_with($mime, 'text/');
    if (!$isPdf && !$isText) {
        throw new RuntimeException(
            'Cannot read a ' . $mime . ' file. Ask the candidate to resend the resume as a PDF.'
        );
    }

    $attempts = $isPdf ? ['file', 'text'] : ['text'];
    $lastError = 'AI request failed.';

    foreach ($attempts as $mode) {
        $userContent = ai_user_content($mode, $absolutePath, $originalName);
        if ($userContent === null) {
            $lastError = 'Could not extract any text from the PDF.';
            continue;
        }

        [$status, $raw, $curlError] = http_post_json(
            rtrim((string) $settings['ai_base_url'], '/') . '/chat/completions',
            [
                'Authorization: Bearer ' . $key,
                'HTTP-Referer: ' . BASE_URL,
                'X-Title: headhunter',
            ],
            [
                'model'       => $settings['ai_model'],
                'temperature' => (float) $settings['temperature'],
                'response_format' => ['type' => 'json_object'],
                'messages'    => [
                    ['role' => 'system', 'content' => ai_system_prompt((string) $settings['system_instruction'])],
                    ['role' => 'user', 'content' => $userContent],
                ],
            ]
        );

        if ($curlError !== '') {
            $lastError = 'Could not reach the AI endpoint: ' . $curlError;
            continue;
        }
        if ($status < 200 || $status >= 300) {
            $lastError = 'AI endpoint returned HTTP ' . $status . ': ' . substr($raw, 0, 400);
            continue;
        }

        $decoded = json_decode($raw, true);
        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            $lastError = 'AI response contained no content: ' . substr($raw, 0, 400);
            continue;
        }

        $data = decode_model_json($content);
        if ($data === null) {
            $lastError = 'AI did not return usable JSON: ' . substr($content, 0, 400);
            continue;
        }

        return [normalise_resume($data), (string) ($decoded['model'] ?? $settings['ai_model']), $mode];
    }

    throw new RuntimeException($lastError);
}

function ai_system_prompt(string $instruction): string
{
    return trim($instruction) . "\n\n"
        . "Reply with a single JSON object and nothing else. Use exactly this shape, "
        . "omitting arrays that have no content:\n" . resume_schema_hint() . "\n"
        . "Set \"language\" to \"fa\" if the resume is in Persian, otherwise \"en\". "
        . "Keep every value in the resume's own language.";
}

function ai_user_content(string $mode, string $absolutePath, string $originalName): ?array
{
    if ($mode === 'file') {
        return [
            ['type' => 'text', 'text' => 'Polish the attached resume and return the JSON object.'],
            ['type' => 'file', 'file' => [
                'filename'  => $originalName !== '' ? $originalName : 'resume.pdf',
                'file_data' => 'data:application/pdf;base64,' . base64_encode((string) file_get_contents($absolutePath)),
            ]],
        ];
    }

    $text = str_starts_with(detect_mime($absolutePath), 'text/')
        ? (string) file_get_contents($absolutePath)
        : pdf_to_text($absolutePath);

    if (trim($text) === '') {
        return null;
    }

    return [[
        'type' => 'text',
        'text' => "Polish this resume and return the JSON object.\n\n--- RESUME TEXT ---\n"
            . mb_substr($text, 0, 60000),
    ]];
}

/** Models sometimes wrap JSON in a fenced block; unwrap before decoding. */
function decode_model_json(string $content): ?array
{
    $content = trim($content);
    if (str_starts_with($content, '```')) {
        $content = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $content);
    }

    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($content, '{');
    $end   = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }
    $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

    return is_array($decoded) ? $decoded : null;
}

/** Force every expected key to exist so the review form never has to guess. */
function normalise_resume(array $d): array
{
    $str  = static fn($v): string => is_scalar($v) ? trim((string) $v) : '';
    $list = static fn($v): array => is_array($v) ? array_values($v) : [];

    $contact = is_array($d['contact'] ?? null) ? $d['contact'] : [];

    return [
        'language'   => in_array($d['language'] ?? '', ['fa', 'en'], true) ? $d['language'] : '',
        'full_name'  => $str($d['full_name'] ?? ''),
        'headline'   => $str($d['headline'] ?? ''),
        'summary'    => $str($d['summary'] ?? ''),
        'contact'    => [
            'email'    => $str($contact['email'] ?? ''),
            'phone'    => $str($contact['phone'] ?? ''),
            'location' => $str($contact['location'] ?? ''),
            'links'    => $list($contact['links'] ?? []),
        ],
        'experience'     => $list($d['experience'] ?? []),
        'education'      => $list($d['education'] ?? []),
        'skills'         => $list($d['skills'] ?? []),
        'languages'      => $list($d['languages'] ?? []),
        'certifications' => $list($d['certifications'] ?? []),
        'projects'       => $list($d['projects'] ?? []),
    ];
}
