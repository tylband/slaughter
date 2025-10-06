<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config.php';
require_once '../token_auth.php';
require_once '../system_logger.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['username']) || !isset($input['password'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Username and password are required']);
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

    // Generate JWT-like token
    $token = TokenAuth::generateToken($user['UID'], $user['Username'], $user['roles']);

    // Store token in database
    TokenAuth::storeToken($conn, $user['UID'], $token);

    // Log successful login
    $logger = new SystemLogger($conn, $user['UID'], $user['Username']);
    $logger->logLogin($user['UID'], $user['Username']);

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
}
?>