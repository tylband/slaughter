<?php
declare(strict_types=1);
require_once __DIR__ . '/scoreboard_storage.php';
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
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload.']);
    exit;
}

$user = authTokenUser((string) ($input['token'] ?? ''));
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$data = $input['data'] ?? null;
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
    exit;
}

try {
    saveScoreboard($data);
    echo json_encode(['success' => true]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Save failed.']);
}
