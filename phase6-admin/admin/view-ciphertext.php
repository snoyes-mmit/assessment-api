<?php
/**
 * admin/view-ciphertext.php — display a submission's ciphertext in a
 * readonly textarea so the admin can copy it into the local decrypt CLI.
 *
 * Workflow:
 *   1. Admin clicks "View" on a submitted row in index.php.
 *   2. This page renders the ciphertext (base64) and a one-click copy button.
 *   3. Admin pastes the ciphertext into a local terminal running
 *      `node tools/decrypt-result.js` (see tools/ in the renderer repo).
 *   4. Admin reviews the plaintext result locally.
 *   5. Admin returns to index.php and clicks "Mark Decrypted" on the row.
 *
 * The ciphertext is sealed-box encrypted to a private key that lives ONLY
 * on the manager's local machine — even an admin viewing this page over
 * HTTPS cannot read the plaintext without that local key. That's the
 * intended security model.
 */

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$deviceId = $_GET['device_id'] ?? '';

if (!preg_match('/^[a-f0-9]{64}$/i', (string)$deviceId)) {
    flash_set('error', 'Invalid device_id format.');
    header('Location: index.php');
    exit;
}

// Pull everything the admin might want in one query so the page is
// self-contained (no need to click back to index.php to check timestamps
// or app_version while wrangling the decrypt step).
$sql = '
    SELECT
        u.win_username,
        u.computer_name,
        u.device_id,
        u.registered_at,
        s.submitted_at,
        s.decrypted_at,
        s.app_version,
        s.ciphertext_b64
    FROM users u
    LEFT JOIN submissions s ON s.device_id = u.device_id
    WHERE u.device_id = ?
';
$stmt = admin_pdo()->prepare($sql);
$stmt->execute([$deviceId]);
$row = $stmt->fetch();

if (!$row) {
    flash_set('error', 'No user found for that device_id.');
    header('Location: index.php');
    exit;
}

if (empty($row['ciphertext_b64'])) {
    flash_set('error', sprintf(
        'User "%s" has registered but not submitted yet. Nothing to view.',
        $row['win_username']
    ));
    header('Location: index.php');
    exit;
}

render_header('View ciphertext — ' . $row['win_username']);
?>

<p><a href="index.php">← Back to users</a></p>

<h2>Ciphertext for <?= h($row['win_username']) ?></h2>

<dl class="meta-list">
    <dt>Windows username</dt>
    <dd><?= h($row['win_username']) ?></dd>

    <dt>Computer name</dt>
    <dd><?= h($row['computer_name']) ?></dd>

    <dt>Device ID (full)</dt>
    <dd><code class="mono"><?= h($row['device_id']) ?></code></dd>

    <dt>Registered at</dt>
    <dd><?= h($row['registered_at']) ?></dd>

    <dt>Submitted at</dt>
    <dd><?= h($row['submitted_at']) ?></dd>

    <dt>App version</dt>
    <dd><?= h($row['app_version']) ?></dd>

    <dt>Decrypted at</dt>
    <dd>
        <?php if (!empty($row['decrypted_at'])): ?>
            <?= h($row['decrypted_at']) ?>
        <?php else: ?>
            <span class="muted">— not yet —</span>
        <?php endif; ?>
    </dd>
</dl>

<div class="ciphertext-wrap">
    <p>
        <strong>Ciphertext (base64):</strong>
        <button type="button" id="copy-btn" class="btn btn-primary" style="margin-left: 8px;">
            Copy to clipboard
        </button>
        <span id="copy-status" style="margin-left: 8px; font-size: 12px; color: var(--mmit-success);"></span>
    </p>
    <!--
        readonly + onclick=select makes the textarea easy to grab even on
        older browsers that don't support the Clipboard API. The actual copy
        button uses navigator.clipboard.writeText with execCommand fallback.
    -->
    <textarea id="ciphertext"
              readonly
              onclick="this.select();"
              spellcheck="false"
              autocomplete="off"
    ><?= h($row['ciphertext_b64']) ?></textarea>
</div>

<p style="margin-top: 18px;">
    <em>Next step:</em> paste the value above into your local
    <code class="mono">node tools/decrypt-result.js</code> session.
    Once you've reviewed the plaintext,
    <a href="index.php">return to the users list</a> and click
    <strong>Mark Decrypted</strong> on this row.
</p>

<script>
    // Copy-to-clipboard with a graceful fallback. The Clipboard API is
    // permission-gated in some browsers; if the navigator path throws we
    // fall back to the legacy execCommand('copy') path, which still works
    // inside a textarea selection.
    (function () {
        var btn      = document.getElementById('copy-btn');
        var ta       = document.getElementById('ciphertext');
        var status   = document.getElementById('copy-status');

        function flash(msg) {
            status.textContent = msg;
            setTimeout(function () { status.textContent = ''; }, 2500);
        }

        btn.addEventListener('click', function () {
            ta.select();
            ta.setSelectionRange(0, ta.value.length);

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(ta.value)
                    .then(function () { flash('Copied ✓'); })
                    .catch(function () {
                        // Permission denied or non-secure context — fall back.
                        try {
                            document.execCommand('copy');
                            flash('Copied ✓');
                        } catch (e) {
                            flash('Copy failed — select and copy manually.');
                        }
                    });
            } else {
                try {
                    document.execCommand('copy');
                    flash('Copied ✓');
                } catch (e) {
                    flash('Copy failed — select and copy manually.');
                }
            }
        });
    })();
</script>

<?php render_footer(); ?>
