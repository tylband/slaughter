<?php
require_once '../config.php';
require_once '../token_auth.php';
require_once '../system_logger.php';

// Handle token-based logout
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle AJAX logout request
    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);
    $token = $data['token'] ?? null;

    if ($token) {
        // Get user info from token before removing it
        $user_info = TokenAuth::getUserFromToken($conn, $token);
        if ($user_info) {
            $logger = new SystemLogger($conn, $user_info['user_id'], $user_info['username']);
            $logger->logLogout($user_info['user_id'], $user_info['username']);
        }

        // Remove token from database
        TokenAuth::removeToken($conn, $token);
    }

    echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
    exit;
} else {
    // Handle direct page access (GET request)
    // Try to get token from various sources
    $token = null;

    // Check Authorization header
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        }
    }

    // Check query parameter
    if (!$token && isset($_GET['token'])) {
        $token = $_GET['token'];
    }

    // Check cookie
    if (!$token && isset($_COOKIE['auth_token'])) {
        $token = $_COOKIE['auth_token'];
    }

    // Remove token from database if found
    if ($token) {
        // Get user info from token before removing it
        $user_info = TokenAuth::getUserFromToken($conn, $token);
        if ($user_info) {
            $logger = new SystemLogger($conn, $user_info['user_id'], $user_info['username']);
            $logger->logLogout($user_info['user_id'], $user_info['username']);
        }

        TokenAuth::removeToken($conn, $token);
    }

    // Clear any cookies
    if (isset($_COOKIE['auth_token'])) {
        setcookie('auth_token', '', time() - 3600, '/');
    }

    // Redirect to login page with success message
    header("Location: login.php?logout=success");
    exit();
}
?>