<?php
require_once './configuration.php';

header("Content-Type: application/javascript");
?>
const API_BASE_URL = "<?php echo rtrim($_ENV['API_BASE_URL'], '/'); ?>";

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
        document.cookie = 'auth_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
        window.location.href = 'login.php';
        return;
    }

    return response;
}