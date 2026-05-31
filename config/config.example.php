<?php
/**
 * config.example.php — TEMPLATE.
 *
 * This file is committed to source control as `config.example.php`. The
 * real `config.php` is created on the server with production credentials
 * and is listed in .gitignore so it never reaches the repo.
 *
 * Deployment workflow on cPanel:
 *   1. SFTP-upload the api/ tree under public_html/api/.
 *   2. Create a sibling directory ONE LEVEL ABOVE public_html (e.g.
 *      /home/<cpanel_user>/assessment-api-config/). cPanel's default
 *      .htaccess does not serve files outside public_html, so credentials
 *      live there.
 *   3. Copy this file to that directory as `config.php` and fill in real
 *      values. The bootstrap looks for it at the path defined by
 *      MMIT_CONFIG_PATH (see _bootstrap.php).
 *
 * If you prefer to keep the config inside the docroot, the bundled
 * .htaccess explicitly denies access to the config/ directory and any
 * .php file inside it — but outside-docroot is the safer default and is
 * what the README documents.
 */

return [
    // ── Database ────────────────────────────────────────────────────────
    // Created via cPanel → MySQL Databases. cPanel prefixes both the
    // database name and the user with your cPanel account name, e.g.
    // mmitnet_assessment / mmitnet_apiuser. Use the full prefixed name.
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'mmitnet_assessment',
        'user'     => 'mmitnet_apiuser',
        'password' => 'REPLACE_WITH_REAL_DB_PASSWORD',
        'charset'  => 'utf8mb4',
    ],

    // ── Shared secret ───────────────────────────────────────────────────
    // 32-byte (64 hex-char) random string. The Electron app sends this in
    // the X-MMIT-Shared-Secret header on every authenticated request.
    // Generate fresh values with:  php -r "echo bin2hex(random_bytes(32));"
    // or:                          openssl rand -hex 32
    'shared_secret' => 'REPLACE_WITH_REAL_SHARED_SECRET',

    // ── Notification email ──────────────────────────────────────────────
    // Submissions notify this address via PHP mail(). On Namecheap shared
    // hosting, mail() routes through the local Exim relay.
    'notify_email' => 'snoyes@mmitnetwork.com',

    // ── Operational toggles ─────────────────────────────────────────────
    // 'debug' = true exposes PDO/mail errors in HTTP responses. NEVER on
    // in production — set to false before going live.
    'debug' => false,
];
