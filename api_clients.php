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

// Authenticate user with Redis session for all requests except OPTIONS
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
            // Check if requesting a single client by ID
            if (isset($_GET['id'])) {
                $cid = (int)$_GET['id'];

                $stmt = $conn->prepare("
                    SELECT CID, Surname, Firstname, Middlename, NameExt, Address, Contact_No, Gender, Status
                    FROM tbl_clients
                    WHERE CID = ? AND isDeleted = 0
                ");
                $stmt->execute([$cid]);
                $client = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($client) {
                    $client['full_name'] = trim($client['Surname'] . ' ' . $client['Firstname'] . ' ' . ($client['Middlename'] ?: '') . ' ' . ($client['NameExt'] ?: ''));
                    echo json_encode([
                        'success' => true,
                        'data' => [$client]
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Client not found'
                    ]);
                }
                break;
            }

            // List clients with pagination and search
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';

            $offset = ($page - 1) * $limit;

            // Build query
            $whereClause = '';
            $params = [];

            if (!empty($search)) {
                $whereClause = "WHERE CONCAT(COALESCE(Surname, ''), ' ', COALESCE(Firstname, ''), ' ', COALESCE(Middlename, ''), ' ', COALESCE(NameExt, '')) LIKE ? OR Address LIKE ? OR Contact_No LIKE ?";
                $searchParam = "%$search%";
                $params = [$searchParam, $searchParam, $searchParam];
            }

            // Get total count (exclude deleted records)
            $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_clients WHERE isDeleted = 0 $whereClause");
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get clients (exclude deleted records)
            $query = "
                SELECT CID, Surname, Firstname, Middlename, NameExt, Address, Contact_No, Gender, Status
                FROM tbl_clients
                WHERE isDeleted = 0 $whereClause
                ORDER BY Surname, Firstname
                LIMIT $limit OFFSET $offset
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format full names
            foreach ($clients as &$client) {
                $client['full_name'] = trim($client['Surname'] . ' ' . $client['Firstname'] . ' ' . $client['Middlename'] . ' ' . $client['NameExt']);
            }

            echo json_encode([
                'success' => true,
                'data' => $clients,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;

        case 'POST':
            // Create new client
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                throw new Exception('Invalid JSON data');
            }

            // Validate required fields
            $required = ['Surname', 'Firstname', 'Address', 'Contact_No', 'Status'];
            foreach ($required as $field) {
                if (!isset($data[$field]) || trim($data[$field]) === '') {
                    throw new Exception("Field '$field' is required");
                }
            }

            // Validate status enum
            $validStatuses = ['Stall Owner', 'Private', 'Individual'];
            if (!in_array($data['Status'], $validStatuses)) {
                throw new Exception('Invalid status value');
            }

            // Check for duplicate client (same name combination, not deleted)
            $surname = strtoupper(trim($data['Surname']));
            $firstname = strtoupper(trim($data['Firstname']));
            $middlename = isset($data['Middlename']) ? strtoupper(trim($data['Middlename'])) : null;

            $duplicateCheck = $conn->prepare("
                SELECT CID FROM tbl_clients
                WHERE UPPER(Surname) = ? AND UPPER(Firstname) = ?
                AND (Middlename IS NULL OR UPPER(Middlename) = ?)
                AND isDeleted = 0
            ");
            $duplicateCheck->execute([$surname, $firstname, $middlename]);
            if ($duplicateCheck->fetch()) {
                throw new Exception('A client with this name already exists');
            }

            $stmt = $conn->prepare("
                INSERT INTO tbl_clients (Surname, Firstname, Middlename, NameExt, Address, Contact_No, Gender, Status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                strtoupper(trim($data['Surname'])),
                strtoupper(trim($data['Firstname'])),
                isset($data['Middlename']) ? strtoupper(trim($data['Middlename'])) : null,
                isset($data['NameExt']) ? strtoupper(trim($data['NameExt'])) : null,
                strtoupper(trim($data['Address'])),
                strtoupper(trim($data['Contact_No'])),
                isset($data['Gender']) ? $data['Gender'] : null,
                $data['Status']
            ]);

            $newId = $conn->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Client created successfully',
                'data' => ['CID' => $newId]
            ]);
            break;

        case 'PUT':
            // Update client
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['CID'])) {
                throw new Exception('Invalid JSON data or missing CID');
            }

            $cid = (int)$data['CID'];

            // Check if client exists and is not deleted
            $checkStmt = $conn->prepare("SELECT CID FROM tbl_clients WHERE CID = ? AND isDeleted = 0");
            $checkStmt->execute([$cid]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Client not found');
            }

            // Validate status if provided
            if (isset($data['Status'])) {
                $validStatuses = ['Stall Owner', 'Private', 'Individual'];
                if (!in_array($data['Status'], $validStatuses)) {
                    throw new Exception('Invalid status value');
                }
            }

            // Build update query
            $updateFields = [];
            $params = [];

            $fields = ['Surname', 'Firstname', 'Middlename', 'NameExt', 'Address', 'Contact_No', 'Gender', 'Status'];
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = ?";
                    if ($field === 'Status' || $field === 'Gender') {
                        $params[] = $data[$field]; // Status and Gender should not be uppercased
                    } else {
                        $params[] = $field === 'Middlename' || $field === 'NameExt' ? (strtoupper(trim($data[$field])) ?: null) : strtoupper(trim($data[$field]));
                    }
                }
            }

            if (empty($updateFields)) {
                throw new Exception('No fields to update');
            }

            $params[] = $cid;
            $stmt = $conn->prepare("UPDATE tbl_clients SET " . implode(', ', $updateFields) . " WHERE CID = ?");
            $stmt->execute($params);

            echo json_encode([
                'success' => true,
                'message' => 'Client updated successfully'
            ]);
            break;

        case 'DELETE':
            // Delete client
            if (!isset($_GET['id'])) {
                throw new Exception('Client ID is required');
            }

            $cid = (int)$_GET['id'];

            // Check if client exists and is not already deleted
            $checkStmt = $conn->prepare("SELECT CID FROM tbl_clients WHERE CID = ? AND isDeleted = 0");
            $checkStmt->execute([$cid]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Client not found');
            }

            // Soft delete the client (set isDeleted = 1)
            $stmt = $conn->prepare("UPDATE tbl_clients SET isDeleted = 1 WHERE CID = ?");
            $stmt->execute([$cid]);

            echo json_encode([
                'success' => true,
                'message' => 'Client deleted successfully'
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
