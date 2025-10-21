<?php
// === CORS HEADERS ===
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// === Preflight Request Handling ===
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// === Now include other logic ===
require_once 'config.php';
require_once 'token_auth.php';


// Authenticate user with token-based authentication
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $user_auth = TokenAuth::authenticate($conn);
    if (!$user_auth) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // List code markings with pagination and search
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';

            $offset = ($page - 1) * $limit;

            // Build query
            $whereClause = '';
            $params = [];

            if (!empty($search)) {
                $whereClause = "WHERE CODE LIKE ?";
                $params = ["%$search%"];
            }

            // Get total count
            $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_codemarkings $whereClause");
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get code markings
            $stmt = $conn->prepare("
                SELECT MID, CODE
                FROM tbl_codemarkings
                $whereClause
                ORDER BY CODE
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
            ");
            $stmt->execute($params);
            $codes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => $codes,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;

        case 'POST':
            // Create new code marking
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                throw new Exception('Invalid JSON data');
            }

            // Validate required fields
            if (!isset($data['CODE']) || trim($data['CODE']) === '') {
                throw new Exception('Code is required');
            }

            // Check if code already exists
            $checkStmt = $conn->prepare("SELECT MID FROM tbl_codemarkings WHERE CODE = ?");
            $checkStmt->execute([trim($data['CODE'])]);
            if ($checkStmt->fetch()) {
                throw new Exception('Code marking already exists');
            }

            $stmt = $conn->prepare("INSERT INTO tbl_codemarkings (CODE) VALUES (?)");
            $stmt->execute([trim($data['CODE'])]);

            $newId = $conn->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Code marking created successfully',
                'data' => ['MID' => $newId]
            ]);
            break;

        case 'PUT':
            // Update code marking
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['MID'])) {
                throw new Exception('Invalid JSON data or missing MID');
            }

            $mid = (int)$data['MID'];

            // Check if code marking exists
            $checkStmt = $conn->prepare("SELECT MID FROM tbl_codemarkings WHERE MID = ?");
            $checkStmt->execute([$mid]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Code marking not found');
            }

            // Validate code if provided
            if (isset($data['CODE']) && trim($data['CODE']) !== '') {
                // Check if new code already exists (excluding current)
                $dupCheck = $conn->prepare("SELECT MID FROM tbl_codemarkings WHERE CODE = ? AND MID != ?");
                $dupCheck->execute([trim($data['CODE']), $mid]);
                if ($dupCheck->fetch()) {
                    throw new Exception('Code marking already exists');
                }
            }

            $stmt = $conn->prepare("UPDATE tbl_codemarkings SET CODE = ? WHERE MID = ?");
            $stmt->execute([trim($data['CODE']), $mid]);

            echo json_encode([
                'success' => true,
                'message' => 'Code marking updated successfully'
            ]);
            break;

        case 'DELETE':
            // Delete code marking
            if (!isset($_GET['id'])) {
                throw new Exception('Code marking ID is required');
            }

            $mid = (int)$_GET['id'];

            // Check if code marking exists
            $checkStmt = $conn->prepare("SELECT MID FROM tbl_codemarkings WHERE MID = ?");
            $checkStmt->execute([$mid]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Code marking not found');
            }

            // Check if code is used in slaughter records
            $usageCheck = $conn->prepare("SELECT COUNT(*) as count FROM tbl_slaughter WHERE MID = ?");
            $usageCheck->execute([$mid]);
            if ($usageCheck->fetch()['count'] > 0) {
                throw new Exception('Cannot delete code marking that is referenced in slaughter records');
            }

            $stmt = $conn->prepare("DELETE FROM tbl_codemarkings WHERE MID = ?");
            $stmt->execute([$mid]);

            echo json_encode([
                'success' => true,
                'message' => 'Code marking deleted successfully'
            ]);
            break;

        case 'OPTIONS':
            // Handle preflight requests
            http_response_code(200);
            break;

        default:
            throw new Exception('Method not allowed');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
