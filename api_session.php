<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://portal.malaybalaycity.gov.ph');
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once 'config.php';
require_once 'token_auth.php';
$user = TokenAuth::authenticate($conn);
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    TokenAuth::removeToken($conn, $user['user_id']);
    echo json_encode(['success' => true]);
    exit;
}
$statement = $conn->prepare('SELECT Name, position FROM tbl_users WHERE UID = ?');
$statement->execute([$user['user_id']]);
$profile = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
$user['name'] = $profile['Name'] ?? $user['username'];
$user['position'] = $profile['position'] ?? '';
echo json_encode(['success' => true, 'user' => $user]);
