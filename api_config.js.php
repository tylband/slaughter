<?php
require_once 'configuration.php';

header("Content-Type: application/javascript");
?>
const API_BASE_URL = "<?php echo rtrim($_ENV['API_BASE_URL'], '/'); ?>";

// Function to get auth headers (no token needed - using Redis sessions)
function getAuthHeaders() {
    return {
        'Content-Type': 'application/json'
    };
}

// Function to make authenticated API calls
async function apiCall(url, options = {}) {
    const defaultOptions = {
        headers: getAuthHeaders(),
        mode: 'cors',
        credentials: 'same-origin' // Important for session cookies
    };

    const mergedOptions = { ...defaultOptions, ...options };
    if (options.headers) {
        mergedOptions.headers = { ...defaultOptions.headers, ...options.headers };
    }

    const response = await fetch(url, mergedOptions);

    // If unauthorized, redirect to login
    if (response.status === 401) {
        localStorage.removeItem('user_info');
        window.location.href = 'login.php';
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