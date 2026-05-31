<?php
/**
 * submit.php — store an encrypted assessment result and notify the reviewer.
 *
 * POST https://assessment-api.mmitnetwork.com/api/submit.php
 *   Headers:
 *     Content-Type: application/json
 *     X-MMIT-Shared-Secret: <32-byte hex>
 *   Body:
 *     {
 *       "device_id":  "<64 hex chars>",          // must match a registered user
 *       "ciphertext": "<base64, original variant>" // sodium.crypto_box_seal output
 *     }
 *
 * Responses:
 *   201 { "submission_id": <int>, "submission_state": "submitted" }
 *   404 { "error": "not_registered" }                — no users row for device_id
 *   409 { "submission_state": "submitted" }          — submission already exists
 *   400 — validation failure
 *   401 — missing/invalid shared secret
 *   429 — rate limited
 *
 * Important: the notification email contains NO answer data. It names the
 * candidate (win_username, computer_name, last 8 hex chars of device_id)
 * and a timestamp — just enough for the reviewer to know who submitted
 * and when, without exposing anything that should stay encrypted.
 *
 * If the mail() call fails we still return 201 — the submission is
 * durably stored in MySQL, which is the source of truth. A mail outage
 * would otherwise cause the client to retry-on-409 (since the second
 * attempt would hit the duplicate-key rule), and we'd lose the
 * notification anyway. Mail failures are logged to the cPanel error log.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

require_shared_secret();

$body = read_json_body();

$deviceId  = validate_device_id($body['device_id'] ?? null);
// 256 KB decoded is a generous ceiling — the actual envelope is well
// under 64 KB. validate_b64 also rejects malformed base64 outright.
$ciphertext = validate_b64($body['ciphertext'] ?? null, 262144);
$decodedBytes = strlen(base64_decode($ciphertext, true) ?: '');

enforce_rate_limit('submit', $deviceId);

// Look up the user. We need win_username / computer_name for the email,
// so we fetch them here rather than relying on a JOIN-on-insert.
$stmt = $pdo->prepare(
    'SELECT user_id, win_username, computer_name
     FROM users WHERE device_id = :did LIMIT 1'
);
$stmt->execute([':did' => $deviceId]);
$user = $stmt->fetch();

if (!$user) {
    json_response(404, [
        'error'   => 'not_registered',
        'message' => 'No registration found for this device. Call /api/register.php first.',
    ]);
}

// Try to insert. The UNIQUE KEY on submissions.user_id is the
// authoritative "one submission per device" enforcer; a race between
// two concurrent submits is decided by MySQL, not by us.
try {
    $stmt = $pdo->prepare(
        'INSERT INTO submissions
            (user_id, ciphertext_b64, ciphertext_bytes, submitted_ip)
         VALUES (:uid, :ct, :ctb, :ip)'
    );
    $stmt->execute([
        ':uid' => (int)$user['user_id'],
        ':ct'  => $ciphertext,
        ':ctb' => $decodedBytes,
        ':ip'  => client_ip(),
    ]);
    $submissionId = (int)$pdo->lastInsertId();
} catch (PDOException $e) {
    $isDuplicate = ($e->getCode() === '23000') && (($e->errorInfo[1] ?? null) === 1062);
    if ($isDuplicate) {
        json_response(409, ['submission_state' => 'submitted']);
    }
    throw $e;
}

// ── Notification email ──────────────────────────────────────────────
// Plaintext, single short line. The spec calls for the device_id "ending
// ...<last 8 hex>" so the reviewer can correlate against the encrypted
// envelope without storing the full id in their inbox.
$notifyTo  = (string)($MMIT_CONFIG['notify_email'] ?? 'snoyes@mmitnetwork.com');
$winUser   = $user['win_username']  !== '' ? $user['win_username']  : '(unknown)';
$compName  = $user['computer_name'] !== '' ? $user['computer_name'] : '(unknown)';
$lastEight = substr($deviceId, -8);
$tsIso     = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

$subject = 'MMIT Assessment submission received';
$message = sprintf(
    "New MMIT assessment submission from %s@%s (device id ending ...%s). Submitted at %s.",
    $winUser, $compName, $lastEight, $tsIso
);
$headers = [
    'From: noreply@assessment-api.mmitnetwork.com',
    'Reply-To: noreply@assessment-api.mmitnetwork.com',
    'X-Mailer: MMIT-Assessment-API/1.0',
    'Content-Type: text/plain; charset=utf-8',
];

// mail() can be surprisingly slow on shared hosts when Exim is busy. We
// still call it inline because there's no queuing primitive available
// to us here — but we swallow the failure so the submission itself isn't
// affected.
$mailSent = @mail($notifyTo, $subject, $message, implode("\r\n", $headers));
if (!$mailSent) {
    error_log('[assessment-api] mail() returned false for submission_id=' . $submissionId);
}

json_response(201, [
    'submission_id'    => $submissionId,
    'submission_state' => 'submitted',
]);
