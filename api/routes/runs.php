<?php
declare(strict_types=1);

function run_or_404(int $id): array
{
    $stmt = db()->prepare(
        'SELECT u.*, r.candidate_id, r.file_path AS source_path, r.orig_filename, r.mime AS source_mime
           FROM runs u JOIN resumes r ON r.id = u.resume_id
          WHERE u.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('No such run.', 404);
    }
    return $row;
}

function decode_run(array $run): array
{
    $run['extracted'] = $run['extracted'] === null ? null : json_decode($run['extracted'], true);
    $run['edited']    = $run['edited'] === null ? null : json_decode($run['edited'], true);
    $run['has_output'] = $run['output_path'] !== null;
    unset($run['output_path'], $run['source_path']);
    return $run;
}

function r_runs_list(array $p): array
{
    $status = query('status');
    $limit  = min(200, max(1, (int) (query('limit') ?? '100')));

    $stmt = db()->prepare(
        'SELECT u.id, u.resume_id, u.status, u.model, u.input_mode, u.error, u.attempts,
                u.output_path IS NOT NULL AS has_output,
                u.created_at, u.started_at, u.finished_at,
                r.candidate_id, r.orig_filename,
                c.display_name, c.external_ref
           FROM runs u
           JOIN resumes r    ON r.id = u.resume_id
           JOIN candidates c ON c.id = r.candidate_id
          WHERE (:status::text IS NULL OR u.status = :status2)
          ORDER BY u.id DESC
          LIMIT :limit'
    );
    $stmt->bindValue(':status', $status);
    $stmt->bindValue(':status2', $status);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return ['runs' => $stmt->fetchAll()];
}

function r_run_get(array $p): array
{
    return ['run' => decode_run(run_or_404((int) $p[0]))];
}

/** The headhunter's edits land in `edited`; `extracted` keeps the model's original answer. */
function r_run_patch(array $p): array
{
    $run = run_or_404((int) $p[0]);
    $b   = body();

    if (!array_key_exists('edited', $b) || !is_array($b['edited'])) {
        fail('Provide an `edited` object.', 422);
    }

    $stmt = db()->prepare(
        "UPDATE runs
            SET edited = :edited::jsonb,
                status = CASE WHEN status IN ('ready', 'delivered') THEN 'needs_review' ELSE status END
          WHERE id = :id
          RETURNING *"
    );
    $stmt->execute([
        ':edited' => json_encode($b['edited'], JSON_UNESCAPED_UNICODE),
        ':id'     => $run['id'],
    ]);

    $updated = $stmt->fetch();
    $updated['candidate_id'] = $run['candidate_id'];

    return ['run' => decode_run($updated)];
}

function r_run_render(array $p): array
{
    $run = run_or_404((int) $p[0]);
    if ($run['edited'] === null) {
        fail('This run has no extracted content to render yet.', 409);
    }

    try {
        render_run_pdf(db(), (int) $run['id'], json_decode($run['edited'], true));
    } catch (RuntimeException $e) {
        // The renderer or its container said no; that is not our bug to hide.
        fail($e->getMessage(), 502);
    }

    return ['run' => decode_run(run_or_404((int) $run['id']))];
}

/** Queue the finished PDF for the candidate. The worker pushes it to the gateway. */
function r_run_deliver(array $p): array
{
    $run = run_or_404((int) $p[0]);
    if ($run['output_path'] === null) {
        fail('Render the run before delivering it.', 409);
    }

    $message  = (string) (body()['message'] ?? '');
    $fileName = sprintf('resume-%d.pdf', $run['candidate_id']);

    $pdo = db();
    $stmt = $pdo->prepare(
        "INSERT INTO deliveries (candidate_id, run_id, sent_by, kind, body, file_path, file_name)
         VALUES (:candidate, :run, :user, 'document', :body, :path, :name)
         RETURNING id, kind, body, file_name, status, created_at"
    );
    $stmt->execute([
        ':candidate' => $run['candidate_id'],
        ':run'       => $run['id'],
        ':user'      => current_user_id(),
        ':body'      => $message,
        ':path'      => $run['output_path'],
        ':name'      => $fileName,
    ]);

    $mark = $pdo->prepare("UPDATE runs SET status = 'delivered' WHERE id = :id");
    $mark->execute([':id' => $run['id']]);

    return ['delivery' => $stmt->fetch()];
}

function r_run_retry(array $p): array
{
    $run = run_or_404((int) $p[0]);

    $stmt = db()->prepare(
        "UPDATE runs SET status = 'queued', error = NULL, started_at = NULL, finished_at = NULL
          WHERE id = :id RETURNING *"
    );
    $stmt->execute([':id' => $run['id']]);

    return ['run' => decode_run($stmt->fetch() + ['candidate_id' => $run['candidate_id']])];
}

function r_run_output(array $p): never
{
    $run = run_or_404((int) $p[0]);
    if ($run['output_path'] === null) {
        fail('This run has no rendered PDF.', 404);
    }
    send_file(storage_abs($run['output_path']), sprintf('resume-%d.pdf', $run['id']), 'application/pdf');
}
