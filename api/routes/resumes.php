<?php
declare(strict_types=1);

function resume_or_404(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM resumes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('No such resume.', 404);
    }
    return $row;
}

/** Attach an uploaded file to an existing candidate. Shared by the admin and intake paths. */
function attach_resume(int $candidateId, array $upload, string $source): array
{
    [$relative, $sha, $size, $mime] = store_original($upload['tmp_name'], (string) ($upload['name'] ?? 'resume'));

    $stmt = db()->prepare(
        'INSERT INTO resumes (candidate_id, source, file_path, orig_filename, mime, size_bytes, sha256)
         VALUES (:candidate, :source, :path, :name, :mime, :size, :sha)
         RETURNING id, candidate_id, source, orig_filename, mime, size_bytes, sha256, created_at'
    );
    $stmt->execute([
        ':candidate' => $candidateId,
        ':source'    => $source,
        ':path'      => $relative,
        ':name'      => substr((string) ($upload['name'] ?? 'resume'), 0, 255),
        ':mime'      => $mime,
        ':size'      => $size,
        ':sha'       => $sha,
    ]);

    return $stmt->fetch();
}

function r_resume_upload(array $p): array
{
    $candidateId = (int) $p[0];
    candidate_or_404($candidateId);

    return ['resume' => attach_resume($candidateId, uploaded_file(), 'admin')];
}

function r_resume_get(array $p): array
{
    return ['resume' => resume_or_404((int) $p[0])];
}

function r_resume_file(array $p): never
{
    $resume = resume_or_404((int) $p[0]);
    send_file(
        storage_abs($resume['file_path']),
        $resume['orig_filename'] !== '' ? $resume['orig_filename'] : ('resume-' . $resume['id']),
        $resume['mime']
    );
}

/** Queue a polish. The worker picks it up; this returns immediately. */
function r_run_create(array $p): array
{
    $resume = resume_or_404((int) $p[0]);

    $stmt = db()->prepare(
        "INSERT INTO runs (resume_id, requested_by, status)
         VALUES (:resume, :user, 'queued') RETURNING *"
    );
    $stmt->execute([':resume' => $resume['id'], ':user' => current_user_id()]);

    return ['run' => $stmt->fetch()];
}
