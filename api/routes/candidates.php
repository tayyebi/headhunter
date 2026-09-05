<?php
declare(strict_types=1);

function candidate_or_404(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM candidates WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('No such candidate.', 404);
    }
    return $row;
}

function r_candidates_list(array $p): array
{
    $q      = query('q');
    $status = query('status');
    $limit  = min(200, max(1, (int) (query('limit') ?? '100')));
    $offset = max(0, (int) (query('offset') ?? '0'));

    $sql = "SELECT c.*,
                   (SELECT count(*) FROM resumes r WHERE r.candidate_id = c.id) AS resume_count,
                   (SELECT count(*) FROM runs u
                      JOIN resumes r ON r.id = u.resume_id
                     WHERE r.candidate_id = c.id AND u.status = 'needs_review') AS review_count,
                   (SELECT max(r.created_at) FROM resumes r WHERE r.candidate_id = c.id) AS last_resume_at
              FROM candidates c
             WHERE (:q1::text IS NULL
                    OR c.display_name ILIKE :q2
                    OR coalesce(c.external_ref, '') ILIKE :q3
                    OR coalesce(c.phone, '') ILIKE :q4)
               AND (:status::text IS NULL OR c.status = :status2)
             ORDER BY review_count DESC, last_resume_at DESC NULLS LAST, c.id DESC
             LIMIT :limit OFFSET :offset";

    $like = $q === null ? null : '%' . $q . '%';
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':q1', $q);
    $stmt->bindValue(':q2', $like);
    $stmt->bindValue(':q3', $like);
    $stmt->bindValue(':q4', $like);
    $stmt->bindValue(':status', $status);
    $stmt->bindValue(':status2', $status);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return ['candidates' => $stmt->fetchAll()];
}

/** Create, or update in place when the external_ref is already known. */
function r_candidates_upsert(array $p): array
{
    $b   = body();
    $ref = $b['external_ref'] ?? null;

    if ($ref === null || $ref === '') {
        $stmt = db()->prepare(
            'INSERT INTO candidates (display_name, phone, note)
             VALUES (:name, :phone, :note) RETURNING *'
        );
        $stmt->execute([
            ':name'  => (string) ($b['display_name'] ?? ''),
            ':phone' => $b['phone'] ?? null,
            ':note'  => (string) ($b['note'] ?? ''),
        ]);
        return ['candidate' => $stmt->fetch(), 'created' => true];
    }

    return ['candidate' => upsert_candidate((string) $ref, (string) ($b['display_name'] ?? ''), $b['phone'] ?? null)];
}

/** Shared by /candidates and the Telegram webhook. */
function upsert_candidate(string $externalRef, string $displayName, ?string $phone): array
{
    $stmt = db()->prepare(
        "INSERT INTO candidates (external_ref, display_name, phone)
         VALUES (:ref, :name, :phone)
         ON CONFLICT (external_ref) DO UPDATE
            SET display_name = CASE WHEN excluded.display_name <> '' THEN excluded.display_name
                                    ELSE candidates.display_name END,
                phone        = coalesce(excluded.phone, candidates.phone),
                updated_at   = now()
         RETURNING *"
    );
    $stmt->execute([':ref' => $externalRef, ':name' => $displayName, ':phone' => $phone]);
    return $stmt->fetch();
}

function r_candidate_get(array $p): array
{
    $id        = (int) $p[0];
    $candidate = candidate_or_404($id);

    $resumes = db()->prepare(
        'SELECT id, source, orig_filename, mime, size_bytes, created_at
           FROM resumes WHERE candidate_id = :id ORDER BY created_at DESC'
    );
    $resumes->execute([':id' => $id]);

    $runs = db()->prepare(
        'SELECT u.id, u.resume_id, u.status, u.model, u.input_mode, u.error,
                u.output_path IS NOT NULL AS has_output,
                u.created_at, u.finished_at
           FROM runs u JOIN resumes r ON r.id = u.resume_id
          WHERE r.candidate_id = :id ORDER BY u.id DESC'
    );
    $runs->execute([':id' => $id]);

    $deliveries = db()->prepare(
        'SELECT id, kind, body, file_name, status, attempts, last_error, created_at, sent_at
           FROM deliveries WHERE candidate_id = :id ORDER BY id DESC LIMIT 50'
    );
    $deliveries->execute([':id' => $id]);

    return [
        'candidate'  => $candidate,
        'resumes'    => $resumes->fetchAll(),
        'runs'       => $runs->fetchAll(),
        'deliveries' => $deliveries->fetchAll(),
    ];
}

function r_candidate_patch(array $p): array
{
    $id = (int) $p[0];
    candidate_or_404($id);

    $b      = body();
    $sets   = [];
    $params = [':id' => $id];

    foreach (['display_name', 'phone', 'note', 'status'] as $column) {
        if (array_key_exists($column, $b)) {
            $sets[] = "{$column} = :{$column}";
            $params[":{$column}"] = $b[$column];
        }
    }
    if ($sets === []) {
        fail('Nothing to update.', 422);
    }
    $sets[] = 'updated_at = now()';

    $stmt = db()->prepare('UPDATE candidates SET ' . implode(', ', $sets) . ' WHERE id = :id RETURNING *');
    $stmt->execute($params);

    return ['candidate' => $stmt->fetch()];
}
