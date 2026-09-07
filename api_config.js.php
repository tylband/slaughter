<?php
require_once __DIR__ . '/configuration.php';

$configuredApiUrl = rtrim($_ENV['API_BASE_URL'] ?? '', '/');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/api_config.js.php')), '/');
$derivedApiUrl = $host ? "{$scheme}://{$host}{$scriptDir}" : '/api';

if ($configuredApiUrl) {
    $configuredHost = parse_url($configuredApiUrl, PHP_URL_HOST);
    $requestHost = explode(':', $host)[0] ?? '';
    $isPrivateConfiguredHost = $configuredHost === 'localhost'
        || $configuredHost === '127.0.0.1'
        || preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|192\.168\.)/', (string) $configuredHost);

    if ($isPrivateConfiguredHost && $requestHost && $configuredHost && strcasecmp($configuredHost, $requestHost) !== 0) {
        $configuredApiUrl = $derivedApiUrl;
    }
}

$apiBaseUrl = $configuredApiUrl ?: $derivedApiUrl;

header("Content-Type: application/javascript");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
?>
const API_BASE_URL = "<?php echo htmlspecialchars($apiBaseUrl, ENT_QUOTES, 'UTF-8'); ?>";

// Function to get auth headers with token
function getAuthHeaders() {
    const token = localStorage.getItem('auth_token');
    const headers = {
        'Content-Type': 'application/json'
    };

    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }

    return headers;
}

// Function to make authenticated API calls
async function apiCall(url, options = {}) {
    const defaultOptions = {
        headers: getAuthHeaders(),
        mode: 'cors'
    };

    const mergedOptions = { ...defaultOptions, ...options };
    if (options.headers) {
        mergedOptions.headers = { ...defaultOptions.headers, ...options.headers };
    }

    const response = await fetch(url, mergedOptions);

    // If unauthorized, keep the page open and let the caller show its normal error state.
    if (response.status === 401) {
        console.warn('API request was unauthorized:', url);
        return;
    }

    return response;
}

// Safe JSON parsing function that handles HTML error pages
async function safeJsonParse(response) {
    const contentType = response.headers.get('content-type');

    // If the response is JSON, parse it normally
    if (contentType && contentType.includes('application/json')) {
        return response.json();
    }

    // If not JSON, it might be an HTML error page
    const text = await response.text();
    console.error('API returned non-JSON response:', {
        url: response.url,
        status: response.status,
        contentType: contentType,
        response: text.substring(0, 500) // First 500 chars for debugging
    });

    // Try to parse as JSON anyway, if it fails, throw a proper error
    try {
        return JSON.parse(text);
    } catch (parseError) {
        throw new Error(`API returned non-JSON response (Status: ${response.status}). Response: ${text.substring(0, 200)}...`);
    }
}
