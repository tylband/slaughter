<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';
require_once 'redis_session_handler.php';

// Initialize Redis session handler (mandatory)
try {
    initRedisSessionHandler();
    session_start();
} catch (RedisException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Session server unavailable']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // List businesses with pagination, search, and optional client filtering
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $clientId = isset($_GET['cid']) ? (int)$_GET['cid'] : null;

            $offset = ($page - 1) * $limit;

            // Build query
            $whereClause = '';
            $params = [];

            $conditions = [];

            if ($clientId) {
                $conditions[] = "cb.CID = ?";
                $params[] = $clientId;
            }

            if (!empty($search)) {
                $conditions[] = "(cb.Business_Name LIKE ? OR CONCAT(COALESCE(c.Surname, ''), ' ', COALESCE(c.Firstname, ''), ' ', COALESCE(c.Middlename, ''), ' ', COALESCE(c.NameExt, '')) LIKE ?)";
                $searchParam = "%$search%";
                $params[] = $searchParam;
                $params[] = $searchParam;
            }

            if (!empty($conditions)) {
                $whereClause = "WHERE " . implode(' AND ', $conditions);
            }

            // Get total count
            $countStmt = $conn->prepare("
                SELECT COUNT(*) as total
                FROM tbl_client_business cb
                LEFT JOIN tbl_clients c ON cb.CID = c.CID
                $whereClause
            ");
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get businesses with client info
            $query = "
                SELECT cb.BID, cb.CID, cb.Business_Name, cb.Stall_Number, cb.Market_Place,
                       CONCAT(COALESCE(c.Surname, ''), ' ', COALESCE(c.Firstname, ''), ' ', COALESCE(c.Middlename, ''), ' ', COALESCE(c.NameExt, '')) as client_name
                FROM tbl_client_business cb
                LEFT JOIN tbl_clients c ON cb.CID = c.CID
                $whereClause
                ORDER BY cb.Business_Name
                LIMIT $limit OFFSET $offset
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data' => $businesses,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;

        case 'POST':
            // Create new business
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                throw new Exception('Invalid JSON data');
            }

            // Validate required fields
            $required = ['CID', 'Business_Name'];
            foreach ($required as $field) {
                if (!isset($data[$field]) || trim($data[$field]) === '') {
                    throw new Exception("Field '$field' is required");
                }
            }

            // Check if client exists
            $clientCheck = $conn->prepare("SELECT CID FROM tbl_clients WHERE CID = ?");
            $clientCheck->execute([$data['CID']]);
            if (!$clientCheck->fetch()) {
                throw new Exception('Client not found');
            }

            $stmt = $conn->prepare("
                INSERT INTO tbl_client_business (CID, Business_Name, Stall_Number, Market_Place)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                (int)$data['CID'],
                strtoupper(trim($data['Business_Name'])),
                isset($data['Stall_Number']) ? strtoupper(trim($data['Stall_Number'])) : null,
                isset($data['Market_Place']) ? strtoupper(trim($data['Market_Place'])) : null
            ]);

            $newId = $conn->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Business created successfully',
                'data' => ['BID' => $newId]
            ]);
            break;

        case 'PUT':
            // Update business
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['BID'])) {
                throw new Exception('Invalid JSON data or missing BID');
            }

            $bid = (int)$data['BID'];

            // Check if business exists
            $checkStmt = $conn->prepare("SELECT BID FROM tbl_client_business WHERE BID = ?");
            $checkStmt->execute([$bid]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Business not found');
            }

            // Check if client exists if CID is being updated
            if (isset($data['CID'])) {
                $clientCheck = $conn->prepare("SELECT CID FROM tbl_clients WHERE CID = ?");
                $clientCheck->execute([$data['CID']]);
                if (!$clientCheck->fetch()) {
                    throw new Exception('Client not found');
                }
            }

            // Build update query
            $updateFields = [];
            $params = [];

            $fields = ['CID', 'Business_Name', 'Stall_Number', 'Market_Place'];
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = ?";
                    if ($field === 'CID') {
                        $params[] = (int)$data[$field];
                    } else {
                        $params[] = strtoupper(trim($data[$field]));
                    }
                }
            }

            if (empty($updateFields)) {
                throw new Exception('No fields to update');
            }

            $params[] = $bid;
            $stmt = $conn->prepare("UPDATE tbl_client_business SET " . implode(', ', $updateFields) . " WHERE BID = ?");
            $stmt->execute($params);

            echo json_encode([
                'success' => true,
                'message' => 'Business updated successfully'
            ]);
            break;

        case 'DELETE':
            // Delete business
            if (!isset($_GET['id'])) {
                throw new Exception('Business ID is required');
            }

            $bid = (int)$_GET['id'];

            // Check if business exists
            $checkStmt = $conn->prepare("SELECT BID FROM tbl_client_business WHERE BID = ?");
            $checkStmt->execute([$bid]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Business not found');
            }

            $stmt = $conn->prepare("DELETE FROM tbl_client_business WHERE BID = ?");
            $stmt->execute([$bid]);

            echo json_encode([
                'success' => true,
                'message' => 'Business deleted successfully'
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
