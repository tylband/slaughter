<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';
require_once 'redis_session_handler.php';
require_once 'system_logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// ============================================================================
// IMMEDIATE DEBUGGING - Shows info directly in response
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Create immediate debug output
$debugInfo = [
    'timestamp' => date('Y-m-d H:i:s'),
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not-set',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'not-set',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'not-set',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'not-set',
    'raw_input' => file_get_contents('php://input'),
    'raw_input_length' => strlen(file_get_contents('php://input')),
    'post_data' => $_POST,
    'server_vars' => [
        'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? 'not-set',
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'not-set',
        'HTTP_CONTENT_TYPE' => $_SERVER['HTTP_CONTENT_TYPE'] ?? 'not-set'
    ]
];

// Log to error log AND create debug response
error_log('API_AUTH_DEBUG: ' . json_encode($debugInfo));

$input = null;
$rawInput = file_get_contents('php://input');

// Try to get JSON input first (for API calls)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $debugInfo['attempting_json'] = true;
    if (!empty($rawInput)) {
        $jsonDecoded = json_decode($rawInput, true);
        $debugInfo['json_last_error'] = json_last_error();
        $debugInfo['json_error_msg'] = json_last_error_msg();

        if (json_last_error() === JSON_ERROR_NONE) {
            $input = $jsonDecoded;
            $debugInfo['json_success'] = true;
        } else {
            $debugInfo['json_failed'] = true;
        }
    } else {
        $debugInfo['empty_raw_input'] = true;
    }
} else {
    $debugInfo['content_type_not_json'] = true;
}

// If JSON parsing failed or no JSON content type, try POST data (form data)
if (!$input && isset($_POST['username']) && isset($_POST['password'])) {
    $input = $_POST;
    $debugInfo['using_post_data'] = true;
}

// Manual parsing as last resort
if (!$input && !empty($rawInput)) {
    $debugInfo['attempting_manual_parse'] = true;
    parse_str($rawInput, $parsedData);
    if (isset($parsedData['username']) && isset($parsedData['password'])) {
        $input = $parsedData;
        $debugInfo['manual_parse_success'] = true;
    }
}

// Log debug information
error_log('API_AUTH_DEBUG: ' . json_encode($debugInfo));

// Final validation
if (!$input || !isset($input['username']) || !isset($input['password'])) {
    error_log('API_AUTH_ERROR: No valid input found. Input: ' . json_encode($input));
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Username and password are required',
        'debug' => $debugInfo
    ]);
    exit;
}

$debugInfo['final_input'] = $input;
error_log('API_AUTH_SUCCESS: Valid input received: ' . json_encode($debugInfo));
// ============================================================================

$username = trim($input['username']);
$password = $input['password'];

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Username and password cannot be empty']);
    exit;
}

try {
    // Query tbl_users with token and roles columns
    $stmt = $conn->prepare("SELECT UID, Name, Username, Password, roles FROM tbl_users WHERE Username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Log failed login attempt for non-existent user
        $logger = new SystemLogger($conn);
        $logger->logActivity(
            'login_failed',
            "Failed login attempt for non-existent username: {$username}",
            'users',
            null,
            null,
            ['username' => $username, 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown']
        );

        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid username or password']);
        exit;
    }

    // Verify password with MD5 hash
    if (md5($password) !== $user['Password']) {
        // Log failed login attempt
        $logger = new SystemLogger($conn);
        $logger->logActivity(
            'login_failed',
            "Failed login attempt for username: {$username}",
            'users',
            null,
            null,
            ['username' => $username, 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown']
        );

        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid username or password']);
        exit;
    }

    // Initialize Redis session handler (mandatory)
    initRedisSessionHandler();
    session_start();

    // Create Redis session for user
    $_SESSION['username'] = $user['Username'];
    $_SESSION['user_id'] = $user['UID'];
    $_SESSION['role'] = $user['roles'];
    $_SESSION['name'] = $user['Name'];
    $_SESSION['created'] = time();
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    // Log successful login
    $logger = new SystemLogger($conn, $user['UID'], $user['Username']);
    $logger->logLogin($user['UID'], $user['Username']);

    echo json_encode([
        'status' => 'success',
        'message' => 'Login successful',
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
} catch(RedisException $e) {
    error_log('Redis session error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Session server error occurred']);
}
?>
