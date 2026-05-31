<?php
/**
 * admin/index.php — user list with action buttons.
 *
 * Columns: win_username | computer_name | device_id (last 8) | registered_at
 *          | submitted? | submitted_at | reset_count | Actions
 *
 * Sortable by registered_at (default desc). The sort link toggles
 * registered_at asc/desc only — other columns are not sortable on purpose:
 * the row count is small (one row per assessment-taker, ever), and adding
 * more sort axes invites bugs without clear admin benefit.
 *
 * Each row offers up to three actions:
 *   - Mark Decrypted — only when submitted AND not yet marked
 *   - Reset           — destroys the submission row + bumps reset_count
 *   - Delete          — removes the user entirely (cascades the submission)
 *
 * The reset/delete confirmations use a <details>-based inline form, NOT
 * JavaScript confirm() — matches the prompt requirement and means the
 * confirmation copy is server-rendered and reviewable.
 */

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

// Sort handling — limited to registered_at to keep the surface small.
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$nextDir = $sortDir === 'asc' ? 'desc' : 'asc';

// Query: LEFT JOIN submissions so we can show the submission timestamp and
// the decrypted_at marker without losing rows for users who registered but
// never submitted. We project only the columns the table needs — no
// ciphertext bytes here (that's view-ciphertext.php).
$sql = "
    SELECT
        u.device_id,
        u.win_username,
        u.computer_name,
        u.registered_at,
        u.reset_count,
        s.submitted_at,
        s.decrypted_at,
        (s.device_id IS NOT NULL) AS has_submission
    FROM users u
    LEFT JOIN submissions s ON s.device_id = u.device_id
    ORDER BY u.registered_at " . ($sortDir === 'asc' ? 'ASC' : 'DESC') . "
";

try {
    $rows = admin_pdo()->query($sql)->fetchAll();
} catch (Throwable $e) {
    // Fail loud-but-safe: show a friendly message, log the detail server-side.
    error_log('[admin/index.php] query failed: ' . $e->getMessage());
    render_header('Error');
    echo '<div class="flash flash-error">Unable to load users. Check the server error log.</div>';
    render_footer();
    exit;
}

$csrf = csrf_token();

render_header('Users');
?>

<h2>Registered users (<?= count($rows) ?>)</h2>

<table class="users">
    <thead>
        <tr>
            <th>Windows username</th>
            <th>Computer</th>
            <th>Device ID (last 8)</th>
            <th>
                <a href="?dir=<?= h($nextDir) ?>"
                   title="Sort by registration time">
                    Registered
                    <?= $sortDir === 'desc' ? '▼' : '▲' ?>
                </a>
            </th>
            <th>Submitted?</th>
            <th>Submitted at</th>
            <th>Resets</th>
            <th style="width: 1%;">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
        <tr>
            <td colspan="8" style="text-align:center; padding: 32px; color: var(--mmit-text-muted);">
                No registered users yet.
            </td>
        </tr>
    <?php else: foreach ($rows as $r):
        $deviceIdShort = substr((string)$r['device_id'], -8);
        $hasSubmission = (bool)$r['has_submission'];
        $isDecrypted   = $hasSubmission && !empty($r['decrypted_at']);
    ?>
        <tr>
            <td><strong><?= h($r['win_username']) ?></strong></td>
            <td><?= h($r['computer_name']) ?></td>
            <td><code class="mono"><?= h($deviceIdShort) ?></code></td>
            <td><?= h($r['registered_at']) ?></td>
            <td>
                <?php if ($hasSubmission): ?>
                    <?php if ($isDecrypted): ?>
                        <span title="Decrypted at <?= h($r['decrypted_at']) ?>">✔ decrypted</span>
                    <?php else: ?>
                        <span style="color: var(--mmit-success);">✔ submitted</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($hasSubmission): ?>
                    <?= h($r['submitted_at']) ?>
                <?php else: ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </td>
            <td><?= (int)$r['reset_count'] ?></td>
            <td class="actions">

                <?php if ($hasSubmission): ?>
                    <a href="view-ciphertext.php?device_id=<?= h($r['device_id']) ?>"
                       class="btn btn-default">View</a>
                <?php endif; ?>

                <?php if ($hasSubmission && !$isDecrypted): ?>
                    <!--
                        Mark Decrypted — single-step POST (no second confirm).
                        This is a low-risk, idempotent flag flip. The button only
                        appears when there is a submission to mark, so this can't
                        accidentally affect a user who hasn't submitted.
                    -->
                    <form method="post" action="mark-decrypted.php" style="display:inline;">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="device_id" value="<?= h($r['device_id']) ?>">
                        <button type="submit" class="btn btn-success">Mark Decrypted</button>
                    </form>
                <?php endif; ?>

                <!--
                    Reset — opens an inline form (via <details>) where the
                    admin must type a reason of 10-500 chars. No JS confirm().
                    Reset is disabled when there is no submission to clear AND
                    we are only bumping reset_count — but a reset against a
                    user who never submitted is still a legitimate operation
                    (e.g. unsticking a registration), so we leave it enabled.
                -->
                <details class="confirm">
                    <summary class="btn btn-warn">Reset</summary>
                    <form class="confirm-box" method="post" action="reset.php">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="device_id" value="<?= h($r['device_id']) ?>">
                        <label for="reason-<?= h($deviceIdShort) ?>">Reason for reset (10-500 chars):</label>
                        <small>
                            This deletes the submission and lets
                            <strong><?= h($r['win_username']) ?></strong> retake the
                            assessment. The reason is stored on the user row.
                        </small>
                        <textarea
                            id="reason-<?= h($deviceIdShort) ?>"
                            name="reset_reason"
                            minlength="10"
                            maxlength="500"
                            required
                            placeholder="e.g. Wrong department selected; allow retake on dev-senior."></textarea>
                        <div class="row-buttons">
                            <button type="submit" class="btn btn-warn">Confirm reset</button>
                        </div>
                    </form>
                </details>

                <!--
                    Delete — requires the admin to type the win_username
                    exactly. Server re-validates this; client-side `pattern`
                    is a usability hint, not a trust boundary.
                -->
                <details class="confirm">
                    <summary class="btn btn-danger">Delete</summary>
                    <form class="confirm-box" method="post" action="delete.php">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="device_id" value="<?= h($r['device_id']) ?>">
                        <input type="hidden" name="confirm" value="YES">
                        <label for="confirm-username-<?= h($deviceIdShort) ?>">
                            Type <code class="mono"><?= h($r['win_username']) ?></code> to confirm:
                        </label>
                        <small>
                            This permanently removes the user record AND their submission.
                            Use this for test data or staff who left the company —
                            for normal retakes, use <strong>Reset</strong> instead.
                        </small>
                        <input type="text"
                               id="confirm-username-<?= h($deviceIdShort) ?>"
                               name="confirm_username"
                               required
                               autocomplete="off"
                               pattern="<?= h(preg_quote($r['win_username'], '/')) ?>">
                        <div class="row-buttons">
                            <button type="submit" class="btn btn-danger">Confirm delete</button>
                        </div>
                    </form>
                </details>

            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<?php render_footer(); ?>
