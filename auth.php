<?php
declare(strict_types=1);
require_once __DIR__ . '/auth_storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = (string) ($input['action'] ?? '');

try {
    switch ($action) {
        case 'login':
            echo json_encode(authLocalLogin(trim((string) ($input['username'] ?? '')), (string) ($input['password'] ?? '')));
            break;
        case 'verify':
            $user = authTokenUser((string) ($input['token'] ?? ''));
            echo json_encode($user ? ['success' => true, 'user' => $user] : ['success' => false]);
            break;
        case 'logout':
            authRevokeToken((string) ($input['token'] ?? ''));
            echo json_encode(['success' => true]);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
