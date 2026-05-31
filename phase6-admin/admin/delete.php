<?php
/**
 * admin/delete.php — POST handler for deleting a user entirely.
 *
 * Behavior:
 *   1. CSRF check.
 *   2. confirm=YES sentinel required (matches the prompt; cheap belt-and-
 *      suspenders against a malformed POST sneaking through the inline form).
 *   3. confirm_username must equal the row's actual win_username, verified
 *      server-side (the client `pattern` attribute is UX only).
 *   4. DELETE FROM users WHERE device_id = ?
 *      — relies on the ON DELETE CASCADE on submissions.device_id to remove
 *      the associated submission row. If the schema does NOT have that
 *      cascade, delete the submission first; see README-ADMIN.md.
 *   5. Redirect to index.php with a flash.
 *
 * When to use: removing test data or staff who have left the company.
 * For legitimate retake scenarios, use reset.php instead — that preserves
 * the user row + reset history for audit.
 */

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

csrf_require_valid();

$deviceId        = $_POST['device_id']        ?? '';
$confirm         = $_POST['confirm']          ?? '';
$confirmUsername = trim((string)($_POST['confirm_username'] ?? ''));

if ($confirm !== 'YES') {
    flash_set('error', 'Missing confirmation sentinel.');
    header('Location: index.php');
    exit;
}

if (!preg_match('/^[a-f0-9]{64}$/i', (string)$deviceId)) {
    flash_set('error', 'Invalid device_id format.');
    header('Location: index.php');
    exit;
}

$pdo = admin_pdo();

// Look up the user FIRST so we can verify the typed username matches and
// produce a useful flash message after the delete.
$lookup = $pdo->prepare('SELECT win_username FROM users WHERE device_id = ?');
$lookup->execute([$deviceId]);
$user = $lookup->fetch();

if (!$user) {
    flash_set('error', 'No user found for that device_id.');
    header('Location: index.php');
    exit;
}

// hash_equals on UTF-8 strings is fine — both sides are byte-compared.
// We compare against the stored value, not against the user-supplied value
// alone, so a mistyped confirm gets rejected.
if (!hash_equals((string)$user['win_username'], $confirmUsername)) {
    flash_set('error', sprintf(
        'Typed username "%s" does not match "%s". Delete cancelled.',
        $confirmUsername,
        $user['win_username']
    ));
    header('Location: index.php');
    exit;
}

try {
    // ON DELETE CASCADE on submissions.device_id should remove the
    // submission. If that constraint is absent from your schema, replace
    // this with an explicit transaction that deletes the submission first.
    $del = $pdo->prepare('DELETE FROM users WHERE device_id = ?');
    $del->execute([$deviceId]);
} catch (Throwable $e) {
    error_log('[admin/delete.php] failed for device_id=' . substr($deviceId, -8) . ': ' . $e->getMessage());
    flash_set('error', 'Delete failed — see server error log. ' .
        'If the error mentions a foreign key, the submissions row may need to be removed first ' .
        '(see README-ADMIN.md).');
    header('Location: index.php');
    exit;
}

flash_set('success', sprintf(
    'Deleted user "%s" and any associated submission.',
    $user['win_username']
));
header('Location: index.php');
exit;
