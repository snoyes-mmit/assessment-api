<?php
/**
 * admin/_bootstrap.php — shared bootstrap for the admin pages.
 *
 * Provides:
 *   - PDO connection (reuses ../config/config.php from the Phase 3 API)
 *   - CSRF token generation + validation helpers
 *   - Session-backed flash message helpers
 *   - HTML header/footer renderers with minimal inline CSS using the MMIT
 *     brand tokens (#000000 black, #F4C430 yellow, #D4AF37 gold). The admin
 *     UI does NOT pull tokens.css directly — keeping it self-contained means
 *     no asset dependency on the renderer app, and a single-file deploy.
 *
 * Direct browser access is denied via .htaccess. This file is include-only.
 */

declare(strict_types=1);

// Session is required for both the CSRF token and flash messages. Cookie
// params are tightened: HTTPS-only (the .htaccess upgrade guarantees the
// request is HTTPS by the time PHP runs), HttpOnly, SameSite=Strict because
// the admin tool is single-origin and never linked from another site.
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/admin/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_name('mmit_admin_sid');
session_start();

// ─────────────────────────────────────────────────────────────────────────────
// PDO connection — reuses the Phase 3 API's config/config.php
// ─────────────────────────────────────────────────────────────────────────────
// config.php is expected to define $DB_DSN, $DB_USER, $DB_PASS as set up
// during Phase 3. If the path differs in the deployed layout, adjust this
// require accordingly. We require_once so multiple includes from sibling
// scripts don't redefine the constants.
$configPath = __DIR__ . '/../config/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    // Generic message to avoid leaking the absolute path in a 500 response.
    exit('Admin configuration missing. See README-ADMIN.md.');
}
require_once $configPath;

/**
 * Return a shared PDO instance configured with the same options the Phase 3
 * API uses: exceptions on error, no emulated prepares, associative fetch.
 */
function admin_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    global $DB_DSN, $DB_USER, $DB_PASS;
    $pdo = new PDO($DB_DSN, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

// ─────────────────────────────────────────────────────────────────────────────
// CSRF helpers
// ─────────────────────────────────────────────────────────────────────────────
// One token per session — rotating per-form would be more defensive but
// causes UX friction with the back button and the modal-style reset/delete
// forms. The Basic Auth gate already filters out unauthenticated callers,
// so the CSRF token primarily defends against an authenticated admin being
// tricked into following a malicious link in another tab.

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * Validate the CSRF token from a POST body. On failure, emit a 400 and
 * terminate — callers should not see a partial action.
 */
function csrf_require_valid(): void {
    $supplied = $_POST['csrf'] ?? '';
    $expected = $_SESSION['csrf'] ?? '';
    if (!is_string($supplied) || $expected === '' || !hash_equals($expected, $supplied)) {
        http_response_code(400);
        exit('Invalid CSRF token. Reload the page and try again.');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Flash message helpers
// ─────────────────────────────────────────────────────────────────────────────
// Flash messages survive the redirect-after-POST pattern used by every
// mutating handler. The flash is consumed (cleared) on first read so a
// browser refresh on index.php doesn't re-show the banner.

function flash_set(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_consume(): ?array {
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

// ─────────────────────────────────────────────────────────────────────────────
// HTML escape shortcut — used heavily in the index table rendering
// ─────────────────────────────────────────────────────────────────────────────
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Page chrome
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Render the page header (open <html>, <head>, banner, flash if present).
 * Keeps inline CSS minimal — the admin tool is intentionally utilitarian
 * and does not pull tokens.css to keep deploys self-contained.
 */
function render_header(string $title): void {
    $authUser = $_SERVER['PHP_AUTH_USER'] ?? '(unknown)';
    $flash    = flash_consume();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= h($title) ?> — MMIT Assessment Admin</title>
    <style>
        /* MMIT brand tokens (from css/tokens.css). The brand palette is
           BLACK + YELLOW/GOLD, not orange — keep this admin UI consistent
           with the rest of the assessment app. */
        :root {
            --mmit-black:        #000000;
            --mmit-yellow:       #F4C430;
            --mmit-gold:         #D4AF37;
            --mmit-dark-gray:    #2C2C2C;
            --mmit-light-gray:   #F5F5F5;
            --mmit-white:        #FFFFFF;
            --mmit-error:        #ef4444;
            --mmit-success:      #16a34a;
            --mmit-border:       rgba(0, 0, 0, .12);
            --mmit-text-strong:  rgba(44, 44, 44, .92);
            --mmit-text-default: rgba(44, 44, 44, .72);
            --mmit-text-muted:   rgba(44, 44, 44, .50);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--mmit-light-gray);
            color: var(--mmit-text-strong);
            font-size: 14px;
            line-height: 1.45;
        }
        header.banner {
            background: var(--mmit-black);
            color: var(--mmit-white);
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 3px solid var(--mmit-yellow);
        }
        header.banner h1 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: var(--mmit-yellow);
            letter-spacing: 0.02em;
        }
        header.banner .who {
            font-size: 12px;
            opacity: .75;
        }
        main { padding: 24px; max-width: 1280px; margin: 0 auto; }
        h2 { margin: 0 0 16px; font-size: 20px; }
        a { color: var(--mmit-dark-gray); }
        a:hover { color: var(--mmit-black); }

        /* Tables */
        table.users {
            width: 100%;
            border-collapse: collapse;
            background: var(--mmit-white);
            border: 1px solid var(--mmit-border);
            border-radius: 8px;
            overflow: hidden;
            font-size: 13px;
        }
        table.users th,
        table.users td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid var(--mmit-border);
            vertical-align: middle;
        }
        table.users thead th {
            background: var(--mmit-dark-gray);
            color: var(--mmit-white);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        table.users thead th a {
            color: var(--mmit-white);
            text-decoration: none;
        }
        table.users thead th a:hover { color: var(--mmit-yellow); }
        table.users tbody tr:hover { background: rgba(244, 196, 48, 0.05); }
        table.users tbody tr:last-child td { border-bottom: 0; }
        td.actions { white-space: nowrap; }
        td .muted { color: var(--mmit-text-muted); }
        code.mono {
            font-family: 'SF Mono', Menlo, Consolas, monospace;
            font-size: 12px;
            background: var(--mmit-light-gray);
            padding: 2px 5px;
            border-radius: 3px;
        }

        /* Buttons */
        .btn {
            display: inline-block;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 4px;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            line-height: 1.2;
            font-family: inherit;
        }
        .btn + .btn { margin-left: 4px; }
        .btn-default {
            background: var(--mmit-white);
            color: var(--mmit-dark-gray);
            border-color: var(--mmit-border);
        }
        .btn-default:hover { background: var(--mmit-light-gray); }
        .btn-primary {
            background: var(--mmit-yellow);
            color: var(--mmit-black);
            border-color: var(--mmit-gold);
        }
        .btn-primary:hover { background: var(--mmit-gold); }
        .btn-warn {
            background: var(--mmit-white);
            color: #b45309;
            border-color: #f59e0b;
        }
        .btn-warn:hover { background: #fef3c7; }
        .btn-danger {
            background: var(--mmit-white);
            color: var(--mmit-error);
            border-color: var(--mmit-error);
        }
        .btn-danger:hover {
            background: var(--mmit-error);
            color: var(--mmit-white);
        }
        .btn-success {
            background: var(--mmit-white);
            color: var(--mmit-success);
            border-color: var(--mmit-success);
        }
        .btn-success:hover {
            background: var(--mmit-success);
            color: var(--mmit-white);
        }

        /* Flash banner */
        .flash {
            margin-bottom: 18px;
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 13px;
            border: 1px solid;
        }
        .flash-success {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #065f46;
        }
        .flash-error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }
        .flash-info {
            background: #fefce8;
            border-color: #fde68a;
            color: #713f12;
        }

        /* Inline confirmation forms (Reset / Delete) */
        details.confirm {
            display: inline-block;
            position: relative;
        }
        details.confirm > summary {
            list-style: none;
            cursor: pointer;
        }
        details.confirm > summary::-webkit-details-marker { display: none; }
        details.confirm[open] > .confirm-box {
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            z-index: 50;
            min-width: 320px;
            background: var(--mmit-white);
            border: 1px solid var(--mmit-border);
            border-radius: 8px;
            padding: 14px;
            box-shadow: 0 10px 25px rgba(0,0,0,.12);
        }
        .confirm-box label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--mmit-text-default);
        }
        .confirm-box textarea,
        .confirm-box input[type=text] {
            width: 100%;
            padding: 6px 8px;
            font: inherit;
            font-size: 13px;
            border: 1px solid var(--mmit-border);
            border-radius: 4px;
            margin-bottom: 8px;
            font-family: inherit;
        }
        .confirm-box textarea { min-height: 72px; resize: vertical; }
        .confirm-box .row-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 6px;
        }
        .confirm-box small {
            display: block;
            color: var(--mmit-text-muted);
            font-size: 11px;
            margin: -4px 0 8px;
        }

        /* View ciphertext page */
        .ciphertext-wrap textarea {
            width: 100%;
            min-height: 360px;
            font-family: 'SF Mono', Menlo, Consolas, monospace;
            font-size: 12px;
            padding: 10px;
            border: 1px solid var(--mmit-border);
            border-radius: 6px;
            background: var(--mmit-white);
        }
        .meta-list {
            background: var(--mmit-white);
            border: 1px solid var(--mmit-border);
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }
        .meta-list dt {
            font-size: 11px;
            text-transform: uppercase;
            color: var(--mmit-text-muted);
            font-weight: 600;
            letter-spacing: 0.04em;
        }
        .meta-list dd {
            margin: 2px 0 10px;
            font-size: 13px;
        }
    </style>
</head>
<body>
<header class="banner">
    <h1>MMIT Assessment Admin</h1>
    <div class="who">Signed in as <strong><?= h($authUser) ?></strong></div>
</header>
<main>
    <?php if ($flash !== null): ?>
        <div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>
    <?php
}

/**
 * Render the page footer (close <main>, </body>, </html>).
 */
function render_footer(): void {
    ?>
</main>
</body>
</html>
    <?php
}
