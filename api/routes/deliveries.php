<?php
declare(strict_types=1);

function r_deliveries_list(array $p): array
{
    $status = query('status');
    $stmt   = db()->prepare(
        'SELECT d.id, d.candidate_id, d.run_id, d.kind, d.body, d.file_name, d.status,
                d.attempts, d.last_error, d.next_attempt_at, d.created_at, d.sent_at,
                c.display_name, c.external_ref
           FROM deliveries d JOIN candidates c ON c.id = d.candidate_id
          WHERE (:status::text IS NULL OR d.status = :status2)
          ORDER BY d.id DESC LIMIT 200'
    );
    $stmt->bindValue(':status', $status);
    $stmt->bindValue(':status2', $status);
    $stmt->execute();

    return ['deliveries' => $stmt->fetchAll()];
}

/** A free-form message from the headhunter to a candidate. */
function r_delivery_create(array $p): array
{
    $b           = body();
    $candidateId = (int) field($b, 'candidate_id');
    $message     = (string) field($b, 'body');

    candidate_or_404($candidateId);

    $stmt = db()->prepare(
        "INSERT INTO deliveries (candidate_id, sent_by, kind, body)
         VALUES (:candidate, :user, 'text', :body)
         RETURNING id, kind, body, status, created_at"
    );
    $stmt->execute([':candidate' => $candidateId, ':user' => current_user_id(), ':body' => $message]);

    return ['delivery' => $stmt->fetch()];
}

/**
 * The gateway fetches the attachment here after being pushed a delivery.
 * Only the columns bot_gateway is granted are selected, so its narrow GRANT holds.
 */
function r_delivery_file(array $p): never
{
    $stmt = db()->prepare(
        'SELECT id, candidate_id, kind, file_path, file_name, status FROM deliveries WHERE id = :id'
    );
    $stmt->execute([':id' => (int) $p[0]]);
    $row = $stmt->fetch();

    if (!$row) {
        fail('No such delivery.', 404);
    }
    if ($row['file_path'] === null) {
        fail('This delivery has no attachment.', 404);
    }

    send_file(
        storage_abs($row['file_path']),
        $row['file_name'] ?: ('delivery-' . $row['id'] . '.pdf'),
        'application/pdf'
    );
}
