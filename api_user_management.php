<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_auth.php';

function sendResponse($status, $message, $data = null, $code = 200) {
    http_response_code($code);
    $response = [
        'status' => $status,
        'message' => $message
    ];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit;
}

function toBool($value) {
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return ((int)$value) === 1;
    }
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

$authUser = requireAuth();
$roleKey = strtolower(trim((string)($authUser['role'] ?? '')));

if (!userHasModuleAccess($authUser, 'user_management')) {
    sendResponse('error', 'Access denied for User Management module.', null, 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $roleMatrix = getRoleModuleAccessMatrix();
    $userMatrix = getUserModuleAccessMatrix($roleMatrix['access'] ?? []);
    $matrix = array_merge($roleMatrix, $userMatrix);
    $matrix['can_edit'] = isSuperAdminRoleKey($roleKey);
    sendResponse('success', 'Access levels loaded.', $matrix);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isSuperAdminRoleKey($roleKey)) {
        sendResponse('error', 'Only Super Admin can update access levels.', null, 403);
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        sendResponse('error', 'Invalid JSON payload.', null, 400);
    }

    $roleId = isset($payload['role_id']) ? (int)$payload['role_id'] : 0;
    $userId = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
    $moduleKey = normalizeModuleKey($payload['module_key'] ?? '');
    $isEnabled = toBool($payload['is_enabled'] ?? false);

    if (($roleId <= 0 && $userId <= 0) || $moduleKey === '') {
        sendResponse('error', 'role_id/user_id and module_key are required.', null, 400);
    }

    if ($userId > 0) {
        $saved = setUserModuleAccess($userId, $moduleKey, $isEnabled, $authUser['id'] ?? null);
        if (!$saved) {
            sendResponse('error', 'Unable to save user module access.', null, 500);
        }

        $managedUsers = getManagedUsersForAccess();
        $targetUser = null;
        foreach ($managedUsers as $userRow) {
            if ((int)($userRow['user_id'] ?? 0) === $userId) {
                $targetUser = $userRow;
                break;
            }
        }

        $effectiveEnabled = $isEnabled ? 1 : 0;
        if ($targetUser) {
            $effectiveEnabled = userHasModuleAccess([
                'id' => $userId,
                'role' => $targetUser['role_key'] ?? 'user',
                'role_id' => (int)($targetUser['role_id'] ?? 0)
            ], $moduleKey) ? 1 : 0;
        }

        sendResponse('success', 'User module access updated.', [
            'user_id' => $userId,
            'module_key' => $moduleKey,
            'is_enabled' => $effectiveEnabled
        ]);
    }

    $saved = setRoleModuleAccess($roleId, $moduleKey, $isEnabled, $authUser['id'] ?? null);
    if (!$saved) {
        sendResponse('error', 'Unable to save role module access.', null, 500);
    }

    $roleLookup = getRoleLookupById();
    $roleResolvedKey = isset($roleLookup[$roleId]['role_key']) ? $roleLookup[$roleId]['role_key'] : '';
    $effectiveEnabled = getRoleModuleAccess($roleId, $roleResolvedKey, $moduleKey) ? 1 : 0;

    sendResponse('success', 'Role module access updated.', [
        'role_id' => $roleId,
        'module_key' => $moduleKey,
        'is_enabled' => $effectiveEnabled
    ]);
}

sendResponse('error', 'Invalid request method.', null, 405);
?>
