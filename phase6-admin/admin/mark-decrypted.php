<?php
/**
 * admin/mark-decrypted.php — POST handler for flagging a submission as
 * decrypted.
 *
 * Behavior:
 *   1. CSRF check.
 *   2. UPDATE submissions SET decrypted_at = NOW() WHERE device_id = ?
 *      — only when decrypted_at IS NULL, so re-clicking the button by
 *      accident doesn't reset the timestamp to "now" and lose the original.
 *   3. Redirect to index.php with a flash.
 *
 * The Mark Decrypted action is the final step of the manual decrypt
 * workflow: the manager pastes the ciphertext from view-ciphertext.php into
 * the local CLI (tools/decrypt-result.js), gets the plaintext, then clicks
 * Mark Decrypted in the admin UI so the row no longer appears as a pending
 * decrypt in the listing.
 */

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

csrf_require_valid();

$deviceId = $_POST['device_id'] ?? '';

if (!preg_match('/^[a-f0-9]{64}$/i', (string)$deviceId)) {
    flash_set('error', 'Invalid device_id format.');
    header('Location: index.php');
    exit;
}

$pdo = admin_pdo();

try {
    // The "AND decrypted_at IS NULL" clause makes this idempotent: a second
    // click does NOT overwrite the original timestamp. The affected-row
    // count tells us which message to show.
    $upd = $pdo->prepare('
        UPDATE submissions
           SET decrypted_at = NOW()
         WHERE device_id    = ?
           AND decrypted_at IS NULL
    ');
    $upd->execute([$deviceId]);
    $affected = $upd->rowCount();
} catch (Throwable $e) {
    error_log('[admin/mark-decrypted.php] failed for device_id=' . substr($deviceId, -8) . ': ' . $e->getMessage());
    flash_set('error', 'Mark Decrypted failed — see server error log.');
    header('Location: index.php');
    exit;
}

if ($affected === 1) {
    flash_set('success', 'Submission marked as decrypted.');
} else {
    // Either no row matched (already marked, or no submission for that
    // device_id) — either way, not a hard failure, but tell the admin
    // so they don't think the flag flipped silently.
    flash_set('info', 'No change — submission was already marked or does not exist.');
}

header('Location: index.php');
exit;
