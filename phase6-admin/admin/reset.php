<?php
/**
 * admin/reset.php — POST handler for resetting a user's submission.
 *
 * Behavior:
 *   1. CSRF check.
 *   2. Validate reset_reason (10-500 chars, after trimming).
 *   3. Verify the user exists.
 *   4. In a transaction:
 *        DELETE FROM submissions WHERE device_id = ?
 *        UPDATE users SET reset_count = reset_count + 1,
 *                         reset_at    = NOW(),
 *                         reset_reason = ?
 *        WHERE device_id = ?
 *   5. Redirect back to index.php with a flash message.
 *
 * Notes:
 *   - Reset is idempotent at the user-row level (reset_count keeps climbing
 *     if you reset twice in a row) but NOT at the submissions level — a
 *     second reset just no-ops the DELETE. That's fine: the count reflects
 *     admin intent, not submission state.
 *   - Reset is allowed even when no submission exists. That handles the
 *     edge case of clearing a registration-only stuck state without forcing
 *     the admin to use Delete + Re-register.
 */

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

csrf_require_valid();

$deviceId = $_POST['device_id']   ?? '';
$reason   = trim((string)($_POST['reset_reason'] ?? ''));

// device_id from the client is the SHA-256 device fingerprint (64 hex
// chars). We don't trust the value to be sanitized, so do a strict format
// check before letting it near the database — even though PDO would handle
// quoting, a strict check rejects garbage early with a clear error.
if (!preg_match('/^[a-f0-9]{64}$/i', (string)$deviceId)) {
    flash_set('error', 'Invalid device_id format.');
    header('Location: index.php');
    exit;
}

// Reason length policy: matches the prompt requirement (10-500 chars).
// Trim first so a reason of pure whitespace is rejected.
$len = mb_strlen($reason);
if ($len < 10 || $len > 500) {
    flash_set('error', sprintf(
        'Reset reason must be between 10 and 500 characters (got %d).',
        $len
    ));
    header('Location: index.php');
    exit;
}

$pdo = admin_pdo();

// Confirm the user exists before opening the transaction. If they don't,
// the UPDATE would silently affect 0 rows and the admin would see a
// success message for a no-op.
$check = $pdo->prepare('SELECT win_username FROM users WHERE device_id = ?');
$check->execute([$deviceId]);
$user = $check->fetch();

if (!$user) {
    flash_set('error', 'No user found for that device_id.');
    header('Location: index.php');
    exit;
}

try {
    $pdo->beginTransaction();

    // DELETE the submission. We use the device_id directly because
    // submissions.device_id is the FK and the only way to identify the
    // row. No error if there is no submission — that just affects 0 rows
    // and the admin still gets the reset_count bump on the user row.
    $del = $pdo->prepare('DELETE FROM submissions WHERE device_id = ?');
    $del->execute([$deviceId]);

    // Bump reset_count and stamp reset_at + reset_reason on the user row.
    // This is what the rate-limit / abuse signal looks at later.
    $upd = $pdo->prepare('
        UPDATE users
           SET reset_count  = reset_count + 1,
               reset_at     = NOW(),
               reset_reason = ?
         WHERE device_id    = ?
    ');
    $upd->execute([$reason, $deviceId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[admin/reset.php] failed for device_id=' . substr($deviceId, -8) . ': ' . $e->getMessage());
    flash_set('error', 'Reset failed — see server error log.');
    header('Location: index.php');
    exit;
}

flash_set('success', sprintf(
    'Reset complete for %s. Submission cleared; they can now re-submit.',
    $user['win_username']
));
header('Location: index.php');
exit;
