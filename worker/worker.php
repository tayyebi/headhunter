<?php
declare(strict_types=1);

/**
 * Background worker. Two queues, both claimed with FOR UPDATE SKIP LOCKED so
 * more than one worker can run safely:
 *
 *   runs       queued  -> AI extraction -> render -> needs_review
 *   deliveries pending -> push to the gateway webhook -> sent
 */

$api = '/var/www/html/api';
require $api . '/src/config.php';
require $api . '/src/http.php';
require $api . '/src/db.php';
require $api . '/src/files.php';
require $api . '/src/client.php';
require $api . '/src/ai.php';
require $api . '/src/pdf.php';
require $api . '/src/gateway.php';

const MAX_DELIVERY_ATTEMPTS = 6;

function say(string $message): void
{
    fwrite(STDERR, '[worker ' . gmdate('H:i:s') . '] ' . $message . "\n");
}

function worker_settings(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM settings WHERE id = 1')->fetch();
    if (!$row) {
        throw new RuntimeException('Settings row is missing.');
    }
    return $row;
}

/** Claim one queued run, or null. */
function claim_run(PDO $pdo): ?array
{
    $pdo->beginTransaction();
    try {
        $claim = $pdo->query(
            "SELECT id FROM runs WHERE status = 'queued' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED"
        )->fetch();

        if (!$claim) {
            $pdo->commit();
            return null;
        }

        $stmt = $pdo->prepare(
            "UPDATE runs SET status = 'running', started_at = now(), attempts = attempts + 1, error = NULL
              WHERE id = :id
              RETURNING id, resume_id, attempts"
        );
        $stmt->execute([':id' => $claim['id']]);
        $run = $stmt->fetch();

        $pdo->commit();
        return $run;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function process_run(PDO $pdo, array $run): void
{
    $id = (int) $run['id'];

    $stmt = $pdo->prepare('SELECT * FROM resumes WHERE id = :id');
    $stmt->execute([':id' => $run['resume_id']]);
    $resume = $stmt->fetch();
    if (!$resume) {
        throw new RuntimeException('The resume behind this run has disappeared.');
    }

    $settings = worker_settings($pdo);

    say("run {$id}: extracting from resume {$resume['id']} ({$resume['mime']})");
    [$data, $model, $mode] = ai_extract(
        $settings,
        storage_abs($resume['file_path']),
        (string) $resume['mime'],
        (string) $resume['orig_filename']
    );

    $save = $pdo->prepare(
        "UPDATE runs
            SET extracted = :extracted::jsonb,
                edited = :edited::jsonb,
                model = :model,
                input_mode = :mode,
                instruction_snapshot = :instruction,
                status = 'needs_review'
          WHERE id = :id"
    );
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $save->execute([
        ':extracted'   => $json,
        ':edited'      => $json,
        ':model'       => $model,
        ':mode'        => $mode,
        ':instruction' => (string) $settings['system_instruction'],
        ':id'          => $id,
    ]);

    say("run {$id}: extracted via {$mode}, rendering");

    // A failed render must not lose the extraction, so it only downgrades the status.
    try {
        render_run_pdf($pdo, $id, $data);
        $pdo->prepare("UPDATE runs SET status = 'needs_review' WHERE id = :id")->execute([':id' => $id]);
        say("run {$id}: rendered, awaiting review");
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("UPDATE runs SET status = 'needs_review', error = :error WHERE id = :id");
        $stmt->execute([':error' => 'Extraction succeeded but rendering failed: ' . $e->getMessage(), ':id' => $id]);
        say("run {$id}: rendering failed: " . $e->getMessage());
    }
}

/** Claim one due delivery, or null. */
function claim_delivery(PDO $pdo): ?array
{
    $pdo->beginTransaction();
    try {
        $claim = $pdo->query(
            "SELECT id FROM deliveries
              WHERE status = 'pending' AND next_attempt_at <= now()
              ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED"
        )->fetch();

        if (!$claim) {
            $pdo->commit();
            return null;
        }

        // Push it out of the way immediately so a slow send is not claimed twice.
        $stmt = $pdo->prepare(
            "UPDATE deliveries
                SET attempts = attempts + 1,
                    next_attempt_at = now() + (interval '30 seconds' * power(2, attempts))
              WHERE id = :id
              RETURNING id, candidate_id, kind, body, file_path, file_name, attempts,
                        idempotency_key"
        );
        $stmt->execute([':id' => $claim['id']]);
        $delivery = $stmt->fetch();

        $ref = $pdo->prepare('SELECT external_ref FROM candidates WHERE id = :id');
        $ref->execute([':id' => $delivery['candidate_id']]);
        $delivery['external_ref'] = $ref->fetchColumn();

        $pdo->commit();
        return $delivery;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function process_delivery(PDO $pdo, array $delivery): void
{
    $id = (int) $delivery['id'];

    if (!$delivery['external_ref']) {
        throw new RuntimeException('Candidate has no external_ref, so the gateway cannot address them.');
    }

    gateway_push(worker_settings($pdo), delivery_payload($delivery));

    $pdo->prepare("UPDATE deliveries SET status = 'sent', sent_at = now(), last_error = NULL WHERE id = :id")
        ->execute([':id' => $id]);

    say("delivery {$id}: sent");
}

function fail_delivery(PDO $pdo, array $delivery, string $error): void
{
    $final  = (int) $delivery['attempts'] >= MAX_DELIVERY_ATTEMPTS;
    $status = $final ? 'failed' : 'pending';

    $pdo->prepare('UPDATE deliveries SET status = :status, last_error = :error WHERE id = :id')
        ->execute([':status' => $status, ':error' => $error, ':id' => $delivery['id']]);

    say("delivery {$delivery['id']}: " . ($final ? 'giving up' : 'will retry') . ' — ' . $error);
}

// ---------------------------------------------------------------------------

$poll = max(1, WORKER_POLL_SECONDS);
$pdo  = null;
say('starting, polling every ' . $poll . 's');

while (true) {
    try {
        if (!$pdo instanceof PDO) {
            $pdo = db();
            say('connected as ' . $pdo->query('SELECT current_user')->fetchColumn());
        }

        $didWork = false;

        if ($run = claim_run($pdo)) {
            $didWork = true;
            try {
                process_run($pdo, $run);
            } catch (Throwable $e) {
                $pdo->prepare("UPDATE runs SET status = 'failed', error = :error, finished_at = now() WHERE id = :id")
                    ->execute([':error' => $e->getMessage(), ':id' => $run['id']]);
                say("run {$run['id']}: failed — " . $e->getMessage());
            }
        }

        if ($delivery = claim_delivery($pdo)) {
            $didWork = true;
            try {
                process_delivery($pdo, $delivery);
            } catch (Throwable $e) {
                fail_delivery($pdo, $delivery, $e->getMessage());
            }
        }

        if (!$didWork) {
            sleep($poll);
        }
    } catch (PDOException $e) {
        say('database error, reconnecting in 5s: ' . $e->getMessage());
        db_disconnect();
        $pdo = null;
        sleep(5);
    } catch (Throwable $e) {
        say('unexpected error: ' . $e->getMessage());
        sleep($poll);
    }
}
