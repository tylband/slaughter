<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://sakatamalaybalay.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';
// System logger is optional
$logger_available = false;
if (file_exists('system_logger.php')) {
    require_once 'system_logger.php';
    $logger_available = true;
}
require_once 'token_auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Production configuration - no debug output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$input = null;
$rawInput = file_get_contents('php://input');

// Try to get JSON input first (for API calls)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    if (!empty($rawInput)) {
        $jsonDecoded = json_decode($rawInput, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $input = $jsonDecoded;
        }
    }
}

// If JSON parsing failed or no JSON content type, try POST data (form data)
if (!$input && isset($_POST['username']) && isset($_POST['password'])) {
    $input = $_POST;
}

// Manual parsing as last resort
if (!$input && !empty($rawInput)) {
    parse_str($rawInput, $parsedData);
    if (isset($parsedData['username']) && isset($parsedData['password'])) {
        $input = $parsedData;
    }
}

// Final validation
if (!$input || !isset($input['username']) || !isset($input['password'])) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Username and password are required'
    ]);
    exit;
}

$username = trim($input['username']);
$password = $input['password'];

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Username and password cannot be empty']);
    exit;
}

try {
    // Query tbl_users with token and roles columns
    $stmt = $conn->prepare("SELECT UID, Name, Username, Password, roles, token FROM tbl_users WHERE Username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Log failed login attempt for non-existent user
        if ($logger_available && class_exists('SystemLogger')) {
            $logger = new SystemLogger($conn);
            $logger->logActivity(
                'login_failed',
                "Failed login attempt for non-existent username: {$username}",
                'users',
                null,
                null,
                ['username' => $username, 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown']
            );
        }

        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid username or password']);
        exit;
    }

    // Verify password with MD5 hash
    if (md5($password) !== $user['Password']) {
        // Log failed login attempt
        if ($logger_available && class_exists('SystemLogger')) {
            $logger = new SystemLogger($conn);
            $logger->logActivity(
                'login_failed',
                "Failed login attempt for username: {$username}",
                'users',
                null,
                null,
                ['username' => $username, 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown']
            );
        }

        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid username or password']);
        exit;
    }


    // Generate token for the user
    $token = TokenAuth::generateToken($user['UID'], $user['Username'], $user['roles']);

    // Store token in database
    TokenAuth::storeToken($conn, $user['UID'], $token);

    // Log successful login
    if ($logger_available && class_exists('SystemLogger')) {
        $logger = new SystemLogger($conn, $user['UID'], $user['Username']);
        $logger->logLogin($user['UID'], $user['Username']);
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Login successful',
        'token' => $token,
        'user' => [
            'id' => $user['UID'],
            'username' => $user['Username'],
            'name' => $user['Name'],
            'role' => $user['roles']
        ]
    ]);

} catch(PDOException $e) {
    error_log('Login error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred']);
} catch(Exception $e) {
    // Handle exceptions
    error_log('Login error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'An error occurred']);
}
?>
