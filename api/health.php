<?php
/**
 * health.php — liveness/version probe.
 *
 * GET https://assessment-api.mmitnetwork.com/api/health.php
 *   → 200 { "ok": true, "ver": 1 }
 *
 * Intentionally unauthenticated so the desktop client can pre-flight
 * the API before sending real data. Pre-flighting matters because the
 * Electron app encrypts the result envelope before transmission — if
 * the API is reachable but misconfigured, we want the client to
 * discover that BEFORE the user spends 20 minutes on the assessment.
 *
 * Rate-limit: we still log the request to api_requests so the per-IP
 * 30/5min cap applies, but we skip the per-device-id daily limit (no
 * device_id is sent on health). This is enough to keep a misbehaving
 * client from hammering the endpoint.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(405, ['error' => 'method_not_allowed', 'message' => 'GET required.']);
}

enforce_rate_limit('health');

json_response(200, ['ok' => true, 'ver' => 1]);
