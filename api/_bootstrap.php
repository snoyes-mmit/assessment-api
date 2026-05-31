<?php
/**
 * _bootstrap.php — shared bootstrap for every endpoint.
 *
 * Responsibilities, in the order they execute:
 *   1. Force a JSON response posture (error handler, content-type, no
 *      stray HTML from PHP warnings).
 *   2. Locate and load the real config.php from outside public_html.
 *   3. Set permissive CORS headers (* origin, narrow methods/headers).
 *   4. Open a PDO connection with exceptions and named placeholders.
 *   5. Expose helper functions for JSON I/O and input validation.
 *   6. Provide require_shared_secret() — constant-time header check.
 *   7. Provide enforce_rate_limit() — IP-wide + per-device sliding window.
 *
 * Every endpoint script does:
 *     require __DIR__ . '/_bootstrap.php';
 *     require_shared_secret();
 *     enforce_rate_limit('endpointname');
 *     // ... business logic ...
 *
 * health.php deliberately skips require_shared_secret() so the desktop
 * client can pre-flight the API before sending real data.
 */

declare(strict_types=1);

// ────────────────────────────────────────────────────────────────────────
// Error posture
// ────────────────────────────────────────────────────────────────────────
// On a public API we never want a PHP warning to land in the response
// body alongside (or instead of) our JSON. error_reporting is full so
// errors land in the cPanel error log; display_errors is off so they
// never leak to the client. The shutdown handler catches fatal errors
// (parse errors in required files, OOM, etc.) and returns a clean 500.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e) {
    error_log('[assessment-api] uncaught: ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $debug = defined('MMIT_DEBUG') && MMIT_DEBUG === true;
    echo json_encode([
        'error'   => 'internal_error',
        'message' => $debug ? $e->getMessage() : 'An unexpected error occurred.',
    ]);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'internal_error', 'message' => 'Fatal error.']);
        }
    }
});

// ────────────────────────────────────────────────────────────────────────
// Load configuration
// ────────────────────────────────────────────────────────────────────────
// MMIT_CONFIG_PATH lets the SysAdmin override the default location via
// an Apache SetEnv directive without editing PHP. The default assumes
// the cPanel layout documented in README-SERVER.md:
//   /home/<cpanel_user>/public_html/api/_bootstrap.php   ← this file
//   /home/<cpanel_user>/assessment-api-config/config.php ← real config
$configPath = getenv('MMIT_CONFIG_PATH') ?: dirname(__DIR__, 2) . '/assessment-api-config/config.php';

if (!is_readable($configPath)) {
    // Fall back to the in-repo config/config.php for local dev. If neither
    // exists we have to abort — no point continuing without DB creds.
    $fallback = dirname(__DIR__) . '/config/config.php';
    if (is_readable($fallback)) {
        $configPath = $fallback;
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'config_missing', 'message' => 'Server configuration not found.']);
        exit;
    }
}

$MMIT_CONFIG = require $configPath;
if (!is_array($MMIT_CONFIG) || !isset($MMIT_CONFIG['db'], $MMIT_CONFIG['shared_secret'])) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'config_invalid', 'message' => 'Server configuration is malformed.']);
    exit;
}

define('MMIT_DEBUG', !empty($MMIT_CONFIG['debug']));

// ────────────────────────────────────────────────────────────────────────
// CORS
// ────────────────────────────────────────────────────────────────────────
// The desktop client runs in Electron, which by default sends Origin:
// file:// (or a custom protocol). Some renderer configurations also send
// `null`. Rather than maintain an allowlist that breaks every time we
// adjust the Electron build, we open the API to any origin — and rely
// on the shared-secret header (which a browser can't replay across
// origins because it isn't a CORS-safelisted header) to gate access.
// The honest threat model: a malicious page in someone's browser would
// need our secret to use this API, and we never ship that secret to a
// browser. CORS here is convenience, not a security boundary.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-MMIT-Shared-Secret');
header('Access-Control-Max-Age: 600');
header('Vary: Origin');

// Pre-flight: short-circuit OPTIONS before doing any DB work or auth.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ────────────────────────────────────────────────────────────────────────
// PDO connection
// ────────────────────────────────────────────────────────────────────────
$db = $MMIT_CONFIG['db'];
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'], (int)($db['port'] ?? 3306), $db['name'], $db['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        // Real prepares (not emulated) so 1062 duplicate-key errors come
        // through cleanly and integer placeholders bind as integers.
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT         => false,
    ]);
    // Lock the session to UTC so DATETIME comparisons in rate-limit
    // queries match the email timestamps we generate in PHP.
    $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $e) {
    error_log('[assessment-api] DB connect failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error'   => 'db_unavailable',
        'message' => MMIT_DEBUG ? $e->getMessage() : 'Database connection failed.',
    ]);
    exit;
}

// ────────────────────────────────────────────────────────────────────────
// Helpers
// ────────────────────────────────────────────────────────────────────────

/**
 * Emit a JSON response and terminate. Always use this from endpoint code —
 * never echo JSON directly — so we get a consistent shape and never leak
 * a stray trailing newline or whitespace.
 *
 * @param int   $status HTTP status code
 * @param array $data   Response payload
 */
function json_response(int $status, array $data): void {
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Read and JSON-decode the POST body. Enforces Content-Type so a misbehaving
 * client (or a script that forgot to set the header) gets a clean 415
 * instead of a confusing validation error.
 *
 * @return array<string,mixed>
 */
function read_json_body(): array {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        json_response(405, ['error' => 'method_not_allowed', 'message' => 'POST required.']);
    }
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    // Tolerate `; charset=utf-8` and similar parameter suffixes.
    if (stripos($ct, 'application/json') !== 0) {
        json_response(415, ['error' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json.']);
    }
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        json_response(400, ['error' => 'empty_body', 'message' => 'Request body is empty.']);
    }
    // 256 KB hard cap. The submit envelope is well under 64 KB in
    // practice; anything materially larger is either a bug or an attack.
    if (strlen($raw) > 262144) {
        json_response(413, ['error' => 'payload_too_large', 'message' => 'Request body exceeds 256 KB.']);
    }
    try {
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        json_response(400, ['error' => 'invalid_json', 'message' => 'Body is not valid JSON.']);
    }
    if (!is_array($data)) {
        json_response(400, ['error' => 'invalid_json', 'message' => 'Body must be a JSON object.']);
    }
    return $data;
}

/**
 * Validate the device_id format. The Electron main process produces
 * lowercase 64-char hex (SHA-256 of MachineGuid:hostname:salt). Reject
 * anything that doesn't match exactly — wrong-length values are almost
 * certainly bugs or probes, not legitimate clients.
 */
function validate_device_id($s): string {
    if (!is_string($s) || !preg_match('/^[0-9a-f]{64}$/', $s)) {
        json_response(400, ['error' => 'invalid_device_id', 'message' => 'device_id must be 64 lowercase hex characters.']);
    }
    return $s;
}

/**
 * Validate the app_version string. Semver-ish: digits and dots, plus an
 * optional `-` pre-release suffix (e.g. "1.2.3-beta.4"). Hard-cap length
 * so a runaway string can't bloat the users table.
 */
function validate_app_version($s): string {
    if (!is_string($s) || $s === '' || strlen($s) > 32) {
        json_response(400, ['error' => 'invalid_app_version', 'message' => 'app_version is required and must be ≤32 chars.']);
    }
    if (!preg_match('/^[0-9]+(?:\.[0-9]+){0,3}(?:-[0-9A-Za-z\.\-]+)?$/', $s)) {
        json_response(400, ['error' => 'invalid_app_version', 'message' => 'app_version must look like 1.2.3 or 1.2.3-beta.']);
    }
    return $s;
}

/**
 * Validate a base64 string and return it unchanged. We decode-and-recheck
 * rather than just regex-matching because the original-variant alphabet
 * is permissive enough that a regex pass can let through truncated or
 * padding-mangled data which then explodes downstream.
 *
 * @param string $s        Candidate base64 (original variant: + /, padded with =)
 * @param int    $maxBytes Max decoded length in bytes
 */
function validate_b64($s, int $maxBytes): string {
    if (!is_string($s) || $s === '') {
        json_response(400, ['error' => 'invalid_b64', 'message' => 'Value must be a non-empty base64 string.']);
    }
    // Length cap on the encoded form to short-circuit obviously oversize
    // payloads before we pay the decode cost.
    if (strlen($s) > intdiv($maxBytes * 4, 3) + 8) {
        json_response(413, ['error' => 'invalid_b64', 'message' => 'Base64 value exceeds allowed size.']);
    }
    if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $s)) {
        json_response(400, ['error' => 'invalid_b64', 'message' => 'Base64 contains invalid characters.']);
    }
    $decoded = base64_decode($s, true);  // strict mode
    if ($decoded === false) {
        json_response(400, ['error' => 'invalid_b64', 'message' => 'Base64 failed to decode.']);
    }
    if (strlen($decoded) > $maxBytes) {
        json_response(413, ['error' => 'invalid_b64', 'message' => 'Decoded payload exceeds allowed size.']);
    }
    return $s;
}

/**
 * Constant-time check of the X-MMIT-Shared-Secret header. hash_equals is
 * the right primitive here — a naive `===` comparison short-circuits at
 * the first differing byte and would let a sufficiently-patient attacker
 * recover the secret one byte at a time via timing.
 */
function require_shared_secret(): void {
    global $MMIT_CONFIG;
    $expected = (string)($MMIT_CONFIG['shared_secret'] ?? '');
    // The placeholder values must never authenticate. Belt-and-braces
    // check in case someone deploys without editing config.php.
    if ($expected === '' || str_starts_with($expected, 'REPLACE_')) {
        error_log('[assessment-api] shared_secret is unset or placeholder');
        json_response(500, ['error' => 'config_invalid', 'message' => 'Server secret is not configured.']);
    }
    // PHP normalizes most headers to $_SERVER['HTTP_<UPPERCASE_UNDERSCORE>'].
    // Some Apache configurations don't forward custom headers when the
    // SCRIPT is invoked via mod_rewrite; the .htaccess sets
    // `RewriteRule ... [E=HTTP_X_MMIT_SHARED_SECRET:%{HTTP:X-MMIT-Shared-Secret}]`
    // as a safety net. We accept either path.
    $provided = $_SERVER['HTTP_X_MMIT_SHARED_SECRET']
        ?? $_SERVER['REDIRECT_HTTP_X_MMIT_SHARED_SECRET']
        ?? '';
    if (!is_string($provided) || $provided === '' || !hash_equals($expected, $provided)) {
        json_response(401, ['error' => 'unauthorized', 'message' => 'Missing or invalid X-MMIT-Shared-Secret.']);
    }
}

/**
 * Best-effort client IP. Behind cPanel's shared front-end we sometimes
 * see X-Forwarded-For; we take the leftmost (original client) entry when
 * present. This is best-effort by design — rate limits are belt-and-
 * braces alongside the shared secret, not the primary access control.
 */
function client_ip(): string {
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $first = trim(explode(',', $xff)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP) !== false) return $first;
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($remote) ? substr($remote, 0, 45) : '';
}

/**
 * Sliding-window rate limiter.
 *
 * Three concurrent limits, all enforced against the api_requests ledger:
 *   - 30 requests in any 5-minute window per IP (any endpoint)
 *   - 5 register calls per day per device_id
 *   - 5 submit   calls per day per device_id
 *
 * The IP limit catches scripted probing and runs first because it doesn't
 * need a device_id. The per-device limits prevent a single client from
 * burning through retry attempts on the auth-gated endpoints.
 *
 * After the checks pass we INSERT a row recording this request — even on
 * eventual failures — so retries count against the window. ~2% of the
 * time we opportunistically prune rows older than 24h; this keeps the
 * table bounded without a cron job.
 *
 * @param string $endpoint  'register' | 'submit' | 'health' | 'other'
 * @param string $deviceId  device_id for per-device limits; '' to skip
 */
function enforce_rate_limit(string $endpoint, string $deviceId = ''): void {
    global $pdo;

    $ip  = client_ip();
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

    // (1) Per-IP, 5-minute window.
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM api_requests
         WHERE ip_address = :ip AND requested_at >= (UTC_TIMESTAMP(3) - INTERVAL 5 MINUTE)'
    );
    $stmt->execute([':ip' => $ip]);
    if ((int)$stmt->fetchColumn() >= 30) {
        header('Retry-After: 300');
        json_response(429, ['error' => 'rate_limited', 'message' => 'Too many requests from your network. Try again in a few minutes.']);
    }

    // (2) Per-device per-endpoint daily limit, but only for the gated
    //     endpoints. We skip this check when $deviceId is empty (health)
    //     or the endpoint is 'other'.
    if ($deviceId !== '' && in_array($endpoint, ['register', 'submit'], true)) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM api_requests
             WHERE device_id = :did AND endpoint = :ep
               AND requested_at >= (UTC_TIMESTAMP(3) - INTERVAL 1 DAY)'
        );
        $stmt->execute([':did' => $deviceId, ':ep' => $endpoint]);
        if ((int)$stmt->fetchColumn() >= 5) {
            header('Retry-After: 86400');
            json_response(429, ['error' => 'rate_limited', 'message' => 'Daily attempt limit reached for this device.']);
        }
    }

    // Record the attempt. response_status stays 0 until the endpoint
    // updates it before returning — that's a nice-to-have, not load-
    // bearing, so we don't bother for now.
    $stmt = $pdo->prepare(
        'INSERT INTO api_requests (endpoint, ip_address, device_id, requested_at)
         VALUES (:ep, :ip, :did, UTC_TIMESTAMP(3))'
    );
    $stmt->execute([
        ':ep'  => $endpoint,
        ':ip'  => $ip,
        ':did' => $deviceId,
    ]);

    // Opportunistic pruning (~2% of requests). Cheap — single indexed
    // range delete on a small table — and avoids needing a cron job.
    if (random_int(0, 49) === 0) {
        $pdo->exec('DELETE FROM api_requests WHERE requested_at < (UTC_TIMESTAMP(3) - INTERVAL 1 DAY)');
    }
}
