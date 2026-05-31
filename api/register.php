<?php
/**
 * register.php — register a device before it submits an assessment.
 *
 * POST https://assessment-api.mmitnetwork.com/api/register.php
 *   Headers:
 *     Content-Type: application/json
 *     X-MMIT-Shared-Secret: <32-byte hex>
 *   Body:
 *     {
 *       "device_id":        "<64 hex chars>",
 *       "win_username":     "<string, may be empty>",
 *       "computer_name":    "<string, may be empty>",
 *       "app_version":      "<semver-ish, e.g. 1.0.0>",
 *       "weak_fingerprint": <boolean>
 *     }
 *
 * Responses:
 *   201 { "user_id": <int>, "submission_state": "registered-no-submission" }
 *       — new row inserted, candidate may now POST /submit.php
 *   409 { "submission_state": "submitted" | "registered-no-submission" }
 *       — device_id already exists. submission_state tells the client
 *       whether the candidate has already submitted (and should be sent
 *       to the final results screen) or registered without submitting
 *       (and should be allowed to resume the quiz).
 *   400 — validation failure (specific code in body)
 *   401 — missing/invalid shared secret
 *   429 — rate limited
 *
 * Design notes:
 *   - We INSERT first and let the unique index decide. This avoids a
 *     classic check-then-insert race where two concurrent calls both
 *     see "no row" and both proceed to insert. MySQL's duplicate-key
 *     error (SQLSTATE 23000 / errno 1062) is our authoritative signal.
 *   - On the 409 path we read submissions separately to decide between
 *     the two possible submission_state values. We don't roll that into
 *     a single JOIN-on-insert because it's clearer this way and the
 *     extra round-trip only happens on the duplicate path.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

require_shared_secret();

$body = read_json_body();

$deviceId       = validate_device_id($body['device_id']       ?? null);
$appVersion     = validate_app_version($body['app_version']   ?? null);
$winUsername    = isset($body['win_username'])  && is_string($body['win_username'])  ? mb_substr($body['win_username'],  0, 255) : '';
$computerName   = isset($body['computer_name']) && is_string($body['computer_name']) ? mb_substr($body['computer_name'], 0, 255) : '';
$weakFp         = !empty($body['weak_fingerprint']) ? 1 : 0;

enforce_rate_limit('register', $deviceId);

try {
    $stmt = $pdo->prepare(
        'INSERT INTO users
            (device_id, win_username, computer_name, app_version, weak_fingerprint, registered_ip)
         VALUES (:did, :wu, :cn, :av, :wf, :ip)'
    );
    $stmt->execute([
        ':did' => $deviceId,
        ':wu'  => $winUsername,
        ':cn'  => $computerName,
        ':av'  => $appVersion,
        ':wf'  => $weakFp,
        ':ip'  => client_ip(),
    ]);
    $userId = (int)$pdo->lastInsertId();
    json_response(201, [
        'user_id'          => $userId,
        'submission_state' => 'registered-no-submission',
    ]);
} catch (PDOException $e) {
    // SQLSTATE 23000 is integrity constraint violation; MySQL errno 1062
    // is "Duplicate entry". Anything else is a genuine error.
    $isDuplicate = ($e->getCode() === '23000') && (($e->errorInfo[1] ?? null) === 1062);
    if (!$isDuplicate) {
        throw $e;  // let the bootstrap exception handler emit 500
    }

    // Decide which submission_state to return. We pull user_id too so
    // future client versions could resume against the same row if we
    // ever want to surface it; the current spec doesn't return user_id
    // on the 409 path, so we keep the response minimal.
    $stmt = $pdo->prepare(
        'SELECT u.user_id,
                EXISTS (SELECT 1 FROM submissions s WHERE s.user_id = u.user_id) AS has_submission
         FROM users u WHERE u.device_id = :did LIMIT 1'
    );
    $stmt->execute([':did' => $deviceId]);
    $row = $stmt->fetch();
    $state = (!empty($row) && (int)$row['has_submission'] === 1)
        ? 'submitted'
        : 'registered-no-submission';

    json_response(409, ['submission_state' => $state]);
}
