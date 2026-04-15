<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/db_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method.'
    ]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

$token = trim((string)($data['token'] ?? ''));
$moduleKey = strtolower(trim((string)($data['module_key'] ?? '')));

if ($token === '' || $moduleKey === '') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Token and module key are required.'
    ]);
    exit;
}

$_POST['auth_token'] = $token;
$userData = validateToken();

if (!$userData) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid or expired token.'
    ]);
    exit;
}

echo json_encode([
    'status' => 'success',
    'allowed' => userHasModuleAccess($userData, $moduleKey),
    'user' => [
        'id' => $userData['id'] ?? null,
        'username' => $userData['username'] ?? null,
        'role' => $userData['role'] ?? null
    ]
]);
?>
