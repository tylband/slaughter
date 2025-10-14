<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';
require_once '../api/redis_session_handler.php';

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
            // List slaughter operations with client/business info
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
            $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
            $client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
            $business_id = isset($_GET['business_id']) ? (int)$_GET['business_id'] : 0;

            $offset = ($page - 1) * $limit;

            // Build query
            $whereClause = '';
            $params = [];

            if (!empty($search)) {
                $whereClause .= " AND (CONCAT(COALESCE(c.Surname, ''), ' ', COALESCE(c.Firstname, ''), ' ', COALESCE(c.Middlename, '')) LIKE ? OR cb.Business_Name LIKE ? OR cm.CODE LIKE ?)";
                $searchParam = "%$search%";
                $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
            }

            if (!empty($date_from)) {
                $whereClause .= " AND s.Slaughter_Date >= ?";
                $params[] = $date_from . ' 00:00:00';
            }

            if (!empty($date_to)) {
                $whereClause .= " AND s.Slaughter_Date <= ?";
                $params[] = $date_to . ' 23:59:59';
            }

            if ($client_id > 0) {
                $whereClause .= " AND s.CID = ?";
                $params[] = $client_id;
            }

            if ($business_id > 0) {
                $whereClause .= " AND s.BID = ?";
                $params[] = $business_id;
            }

            // Get total count
            $countStmt = $conn->prepare("
                SELECT COUNT(*) as total FROM tbl_slaughter s
                LEFT JOIN tbl_clients c ON s.CID = c.CID
                LEFT JOIN tbl_client_business cb ON s.BID = cb.BID
                LEFT JOIN tbl_codemarkings cm ON s.MID = cm.MID
                WHERE 1=1 $whereClause
            ");
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get slaughter operations
            $query = "
                SELECT s.SID, s.CID, s.BID, s.MID, s.Slaughter_Date, s.isAddOn, s.Added_by,
                       c.Surname, c.Firstname, c.Middlename, c.NameExt,
                       cb.Business_Name, cb.Stall_Number, cb.Market_Place,
                       cm.CODE,
                       (SELECT COUNT(*) FROM tbl_slaughter_details WHERE SID = s.SID) as details_count,
                       (SELECT SUM(Slaughter_Fee + Corral_Fee + Ante_Mortem_Fee + Post_Mortem_Fee + Delivery_Fee)
                        FROM tbl_slaughter_details WHERE SID = s.SID) as total_fees
                FROM tbl_slaughter s
                LEFT JOIN tbl_clients c ON s.CID = c.CID
                LEFT JOIN tbl_client_business cb ON s.BID = cb.BID
                LEFT JOIN tbl_codemarkings cm ON s.MID = cm.MID
                WHERE 1=1 $whereClause
                ORDER BY s.Slaughter_Date DESC, s.SID DESC
                LIMIT $limit OFFSET $offset
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $slaughters = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format data
            foreach ($slaughters as &$slaughter) {
                $slaughter['client_name'] = trim($slaughter['Surname'] . ' ' . $slaughter['Firstname'] . ' ' . $slaughter['Middlename'] . ' ' . $slaughter['NameExt']);
                $slaughter['slaughter_date_formatted'] = date('M d, Y H:i', strtotime($slaughter['Slaughter_Date']));
                $slaughter['total_fees'] = (float)$slaughter['total_fees'];
            }

            echo json_encode([
                'success' => true,
                'data' => $slaughters,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;

        case 'POST':
            // Create new slaughter operation
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                throw new Exception('Invalid JSON data');
            }

            // Validate required fields
            $required = ['CID', 'Slaughter_Date', 'Added_by'];
            foreach ($required as $field) {
                if (!isset($data[$field]) || trim($data[$field]) === '') {
                    throw new Exception("Field '$field' is required");
                }
            }

            // Validate CID exists
            $checkClient = $conn->prepare("SELECT CID FROM tbl_clients WHERE CID = ?");
            $checkClient->execute([$data['CID']]);
            if (!$checkClient->fetch()) {
                throw new Exception('Client not found');
            }

            // Validate BID if provided
            if (isset($data['BID']) && $data['BID']) {
                $checkBusiness = $conn->prepare("SELECT BID FROM tbl_client_business WHERE BID = ? AND CID = ?");
                $checkBusiness->execute([$data['BID'], $data['CID']]);
                if (!$checkBusiness->fetch()) {
                    throw new Exception('Business not found or does not belong to the client');
                }
            }

            // Validate MID if provided
            if (isset($data['MID']) && $data['MID']) {
                $checkCode = $conn->prepare("SELECT MID FROM tbl_codemarkings WHERE MID = ?");
                $checkCode->execute([$data['MID']]);
                if (!$checkCode->fetch()) {
                    throw new Exception('Code marking not found');
                }
            }

            $stmt = $conn->prepare("
                INSERT INTO tbl_slaughter (CID, BID, MID, Slaughter_Date, isAddOn, Added_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['CID'],
                isset($data['BID']) ? $data['BID'] : null,
                isset($data['MID']) ? $data['MID'] : null,
                $data['Slaughter_Date'],
                isset($data['isAddOn']) ? (int)$data['isAddOn'] : 0,
                $data['Added_by']
            ]);

            $newId = $conn->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Slaughter operation created successfully',
                'data' => ['SID' => $newId]
            ]);
            break;

        case 'PUT':
            // Update slaughter operation
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['SID'])) {
                throw new Exception('Invalid JSON data or missing SID');
            }

            $sid = (int)$data['SID'];

            // Check if slaughter operation exists
            $checkStmt = $conn->prepare("SELECT SID FROM tbl_slaughter WHERE SID = ?");
            $checkStmt->execute([$sid]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Slaughter operation not found');
            }

            // Validate CID if provided
            if (isset($data['CID'])) {
                $checkClient = $conn->prepare("SELECT CID FROM tbl_clients WHERE CID = ?");
                $checkClient->execute([$data['CID']]);
                if (!$checkClient->fetch()) {
                    throw new Exception('Client not found');
                }
            }

            // Validate BID if provided
            if (isset($data['BID']) && $data['BID']) {
                $cid = isset($data['CID']) ? $data['CID'] : null;
                if (!$cid) {
                    // Get current CID
                    $currentStmt = $conn->prepare("SELECT CID FROM tbl_slaughter WHERE SID = ?");
                    $currentStmt->execute([$sid]);
                    $cid = $currentStmt->fetch()['CID'];
                }
                $checkBusiness = $conn->prepare("SELECT BID FROM tbl_client_business WHERE BID = ? AND CID = ?");
                $checkBusiness->execute([$data['BID'], $cid]);
                if (!$checkBusiness->fetch()) {
                    throw new Exception('Business not found or does not belong to the client');
                }
            }

            // Validate MID if provided
            if (isset($data['MID']) && $data['MID']) {
                $checkCode = $conn->prepare("SELECT MID FROM tbl_codemarkings WHERE MID = ?");
                $checkCode->execute([$data['MID']]);
                if (!$checkCode->fetch()) {
                    throw new Exception('Code marking not found');
                }
            }

            // Build update query
            $updateFields = [];
            $params = [];

            $fields = ['CID', 'BID', 'MID', 'Slaughter_Date', 'isAddOn'];
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $field === 'BID' || $field === 'MID' ? ($data[$field] ?: null) : $data[$field];
                }
            }

            if (empty($updateFields)) {
                throw new Exception('No fields to update');
            }

            $params[] = $sid;
            $stmt = $conn->prepare("UPDATE tbl_slaughter SET " . implode(', ', $updateFields) . " WHERE SID = ?");
            $stmt->execute($params);

            echo json_encode([
                'success' => true,
                'message' => 'Slaughter operation updated successfully'
            ]);
            break;

        case 'DELETE':
            // Delete slaughter operation
            if (!isset($_GET['id'])) {
                throw new Exception('Slaughter operation ID is required');
            }

            $sid = (int)$_GET['id'];

            // Check if slaughter operation exists
            $checkStmt = $conn->prepare("SELECT SID FROM tbl_slaughter WHERE SID = ?");
            $checkStmt->execute([$sid]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Slaughter operation not found');
            }

            // Check if has details
            $detailsCheck = $conn->prepare("SELECT COUNT(*) as count FROM tbl_slaughter_details WHERE SID = ?");
            $detailsCheck->execute([$sid]);
            if ($detailsCheck->fetch()['count'] > 0) {
                throw new Exception('Cannot delete slaughter operation with associated details');
            }

            $stmt = $conn->prepare("DELETE FROM tbl_slaughter WHERE SID = ?");
            $stmt->execute([$sid]);

            echo json_encode([
                'success' => true,
                'message' => 'Slaughter operation deleted successfully'
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
