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
            // Check if requesting a single client by ID
            if (isset($_GET['id'])) {
                $cid = (int)$_GET['id'];

                $stmt = $conn->prepare("
                    SELECT c.CID, c.Surname, c.Firstname, c.Middlename, c.NameExt, 
                           c.Address, c.Contact_No, c.Gender, c.Status, c.MID,
                           b.Business_Name, b.Stall_Number, b.Market_Place
                    FROM tbl_clients c
                    LEFT JOIN tbl_client_business b ON c.CID = b.CID
                    WHERE c.CID = ? AND c.isDeleted = 0
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
                SELECT c.CID, c.Surname, c.Firstname, c.Middlename, c.NameExt, 
                       c.Address, c.Contact_No, c.Gender, c.Status, c.MID,
                       b.Business_Name, b.Stall_Number, b.Market_Place
                FROM tbl_clients c
                LEFT JOIN tbl_client_business b ON c.CID = b.CID
                WHERE c.isDeleted = 0 $whereClause
                ORDER BY c.Surname, c.Firstname
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
            $validStatuses = ['Stall Owner', 'Private', 'Meat Shop', 'Lechoneros'];
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
                INSERT INTO tbl_clients (Surname, Firstname, Middlename, NameExt, Address, Contact_No, Gender, Status, MID)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                strtoupper(trim($data['Surname'])),
                strtoupper(trim($data['Firstname'])),
                isset($data['Middlename']) ? strtoupper(trim($data['Middlename'])) : null,
                isset($data['NameExt']) ? strtoupper(trim($data['NameExt'])) : null,
                strtoupper(trim($data['Address'])),
                strtoupper(trim($data['Contact_No'])),
                isset($data['Gender']) ? $data['Gender'] : null,
                $data['Status'],
                isset($data['MID']) ? strtoupper(trim($data['MID'])) : null
            ]);

            $newId = $conn->lastInsertId();

            // Create business record if classification is Stall Owner or Meat Shop
            if (in_array($data['Status'], ['Stall Owner', 'Meat Shop']) && !empty($data['Business_Name'])) {
                $businessStmt = $conn->prepare("
                    INSERT INTO tbl_client_business (CID, Business_Name, Stall_Number, Market_Place)
                    VALUES (?, ?, ?, ?)
                ");
                $businessStmt->execute([
                    $newId,
                    !empty($data['Business_Name']) ? strtoupper(trim($data['Business_Name'])) : null,
                    !empty($data['Stall_Number']) ? strtoupper(trim($data['Stall_Number'])) : null,
                    !empty($data['Market_Place']) ? strtoupper(trim($data['Market_Place'])) : null
                ]);
            }

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
                $validStatuses = ['Stall Owner', 'Private', 'Meat Shop', 'Lechoneros'];
                if (!in_array($data['Status'], $validStatuses)) {
                    throw new Exception('Invalid status value');
                }
            }

            // Build update query
            $updateFields = [];
            $params = [];

            $fields = ['Surname', 'Firstname', 'Middlename', 'NameExt', 'Address', 'Contact_No', 'Gender', 'Status', 'MID'];
            foreach ($fields as $field) {
                // Use array_key_exists to handle empty strings - we want to update fields to blank if explicitly set
                if (array_key_exists($field, $data)) {
                    $updateFields[] = "$field = ?";
                    if ($field === 'Status' || $field === 'Gender') {
                        $params[] = $data[$field]; // Status and Gender should not be uppercased
                    } else {
                        $params[] = $field === 'Middlename' || $field === 'NameExt' ? (strtoupper(trim($data[$field])) ?: null) : strtoupper(trim($data[$field]));
                    }
                }
            }

            if (!empty($updateFields)) {
                $params[] = $cid;
                $stmt = $conn->prepare("UPDATE tbl_clients SET " . implode(', ', $updateFields) . " WHERE CID = ?");
                $stmt->execute($params);
            }

            // Update or create business record if classification is Stall Owner or Meat Shop
            if (array_key_exists('Status', $data) && in_array($data['Status'], ['Stall Owner', 'Meat Shop'])) {
                // Check if business record exists
                $businessCheck = $conn->prepare("SELECT BID FROM tbl_client_business WHERE CID = ?");
                $businessCheck->execute([$cid]);
                $existingBusiness = $businessCheck->fetch();

                if ($existingBusiness) {
                    // Update existing business - use array_key_exists to allow setting to null/empty
                    $businessUpdate = $conn->prepare("
                        UPDATE tbl_client_business 
                        SET Business_Name = ?, Stall_Number = ?, Market_Place = ?
                        WHERE CID = ?
                    ");
                    $businessUpdate->execute([
                        array_key_exists('Business_Name', $data) ? (strtoupper(trim($data['Business_Name'])) ?: null) : null,
                        array_key_exists('Stall_Number', $data) ? (strtoupper(trim($data['Stall_Number'])) ?: null) : null,
                        array_key_exists('Market_Place', $data) ? (strtoupper(trim($data['Market_Place'])) ?: null) : null,
                        $cid
                    ]);
                } else {
                    // Create new business if any business field is provided
                    if (array_key_exists('Business_Name', $data) && $data['Business_Name'] !== '') {
                        $businessInsert = $conn->prepare("
                            INSERT INTO tbl_client_business (CID, Business_Name, Stall_Number, Market_Place)
                            VALUES (?, ?, ?, ?)
                        ");
                        $businessInsert->execute([
                            $cid,
                            strtoupper(trim($data['Business_Name'])),
                            array_key_exists('Stall_Number', $data) ? (strtoupper(trim($data['Stall_Number'])) ?: null) : null,
                            array_key_exists('Market_Place', $data) ? (strtoupper(trim($data['Market_Place'])) ?: null) : null
                        ]);
                    }
                }
            }

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
            exit(0);

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
