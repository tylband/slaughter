<?php
require_once 'configuration.php';

// Also check for system .env file if it exists
$systemEnvFile = '../system/.env';
$systemApiUrl = null;

if (file_exists($systemEnvFile)) {
    $lines = file($systemEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Skip comments
        if (strpos($line, '#') === 0) {
            continue;
        }

        // Split by first = sign
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);

            // Remove quotes if present
            $value = trim($value, '"\'');

            if ($key === 'API_BASE_URL') {
                $systemApiUrl = rtrim($value, '/');
                break;
            }
        }
    }
}

header("Content-Type: application/javascript");
?>
const API_BASE_URL = "<?php echo $systemApiUrl ?: rtrim($_ENV['API_BASE_URL'] ?? '/api', '/'); ?>";

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

    // If unauthorized, redirect to login
    if (response.status === 401) {
        localStorage.removeItem('auth_token');
        localStorage.removeItem('user_info');
        window.location.href = '../system/login.php';
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