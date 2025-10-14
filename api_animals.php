<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config.php';
require_once '../api/redis_session_handler.php';
require_once 'redis_cache.php';

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
            // List animals with pagination, search, and fee count
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';

            // Create cache key
            $cache_key = 'animals_list_' . $page . '_' . $limit . '_' . md5($search);

            // Try to get from cache first
            $cached_result = RedisCache::get($cache_key);
            if ($cached_result !== null) {
                echo json_encode($cached_result);
                break;
            }

            $offset = ($page - 1) * $limit;

            // Build query
            $whereClause = '';
            $params = [];

            if (!empty($search)) {
                $whereClause = "WHERE a.Animal LIKE ?";
                $params = ["%$search%"];
            }

            // Get total count
            $countStmt = $conn->prepare("
                SELECT COUNT(*) as total
                FROM tbl_animals a
                $whereClause
            ");
            if (!empty($params)) {
                $countStmt->execute($params);
            } else {
                $countStmt->execute();
            }
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get animals with fee count
            $query = "
                SELECT a.AID, a.Animal, COUNT(f.Fee_ID) as fee_count
                FROM tbl_animals a
                LEFT JOIN tbl_fees f ON a.AID = f.AID
                $whereClause
                GROUP BY a.AID, a.Animal
                ORDER BY a.Animal
                LIMIT $limit OFFSET $offset
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $animals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [
                'success' => true,
                'data' => $animals,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil($total / $limit)
                ]
            ];

            // Cache the result for 5 minutes
            RedisCache::set($cache_key, $result, 300);

            echo json_encode($result);
            break;

        case 'POST':
            // Create new animal
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                throw new Exception('Invalid JSON data');
            }

            // Validate required fields
            if (!isset($data['Animal']) || trim($data['Animal']) === '') {
                throw new Exception('Animal name is required');
            }

            $stmt = $conn->prepare("
                INSERT INTO tbl_animals (Animal)
                VALUES (?)
            ");
            $stmt->execute([trim($data['Animal'])]);

            $newId = $conn->lastInsertId();

            // Clear animals cache after creating new animal
            RedisCache::invalidatePattern('animals_list_*');

            echo json_encode([
                'success' => true,
                'message' => 'Animal type created successfully',
                'data' => ['AID' => $newId]
            ]);
            break;

        case 'PUT':
            // Update animal
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['AID'])) {
                throw new Exception('Invalid JSON data or missing AID');
            }

            $aid = (int)$data['AID'];

            // Check if animal exists
            $checkStmt = $conn->prepare("SELECT AID FROM tbl_animals WHERE AID = ?");
            $checkStmt->execute([$aid]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Animal type not found');
            }

            // Validate animal name
            if (!isset($data['Animal']) || trim($data['Animal']) === '') {
                throw new Exception('Animal name is required');
            }

            $stmt = $conn->prepare("UPDATE tbl_animals SET Animal = ? WHERE AID = ?");
            $stmt->execute([trim($data['Animal']), $aid]);

            // Clear animals cache after updating animal
            RedisCache::invalidatePattern('animals_list_*');

            echo json_encode([
                'success' => true,
                'message' => 'Animal type updated successfully'
            ]);
            break;

        case 'DELETE':
            // Delete animal
            if (!isset($_GET['id'])) {
                throw new Exception('Animal ID is required');
            }

            $aid = (int)$_GET['id'];

            // Check if animal exists
            $checkStmt = $conn->prepare("SELECT AID FROM tbl_animals WHERE AID = ?");
            $checkStmt->execute([$aid]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Animal type not found');
            }

            // Check if animal has associated fees
            $feeCheck = $conn->prepare("SELECT COUNT(*) as count FROM tbl_fees WHERE AID = ?");
            $feeCheck->execute([$aid]);
            if ($feeCheck->fetch()['count'] > 0) {
                throw new Exception('Cannot delete animal type with associated fees');
            }

            // Check if animal has associated slaughter records
            $slaughterCheck = $conn->prepare("SELECT COUNT(*) as count FROM tbl_slaughter_details WHERE AID = ?");
            $slaughterCheck->execute([$aid]);
            if ($slaughterCheck->fetch()['count'] > 0) {
                throw new Exception('Cannot delete animal type with associated slaughter records');
            }

            $stmt = $conn->prepare("DELETE FROM tbl_animals WHERE AID = ?");
            $stmt->execute([$aid]);

            // Clear animals cache after deleting animal
            RedisCache::invalidatePattern('animals_list_*');

            echo json_encode([
                'success' => true,
                'message' => 'Animal type deleted successfully'
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
