<?php
/**
 * Centralised CORS handler for all API endpoints.
 * Include this at the top of db_auth.php so every api_*.php gets it for free.
 *
 * Rules:
 *  - Reflect the request Origin back (required when credentials are involved).
 *  - Fall back to * only for requests that carry no credentials.
 *  - Respond immediately to OPTIONS preflight with 200.
 */
if (headers_sent()) {
    return;
}

$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($requestOrigin !== '') {
    // Reflect the exact requesting origin — required for credentialed requests.
    header("Access-Control-Allow-Origin: $requestOrigin");
    header("Access-Control-Allow-Credentials: true");
} else {
    // Non-browser / same-origin request — wildcard is fine.
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Vary: Origin");

// Answer preflight immediately — no need to touch the database.
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
