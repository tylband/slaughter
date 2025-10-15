<?php
// Suppress PHP errors that might corrupt JSON response
error_reporting(0);
ini_set('display_errors', 0);

require_once "config.php";
require_once "redis_session_handler.php";
require_once "system_logger.php";

// Initialize Redis session handler (mandatory)
try {
    initRedisSessionHandler();
    session_start();
} catch (RedisException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Session server unavailable']);
    exit;
}

$action = $_GET['action'] ?? 'get_recent';

// Set JSON headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// For get_recent action, try to authenticate but don't fail if no session
if ($action === 'get_recent') {
    // If no valid Redis session, create a guest user for viewing recent logs only
    if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
        $user_data = [
            'user_id' => null,
            'username' => 'guest',
            'role' => 'guest'
        ];
    } else {
        $user_data = [
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role']
        ];
    }
} else {
    // For other actions, require authentication
    if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }

    $user_data = [
        'user_id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role']
    ];

    // Only allow admin users for non-get_recent actions
    if ($user_data['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required.']);
        exit();
    }
}

$logger = new SystemLogger($conn, $user_data['user_id'], $user_data['username']);

switch ($action) {
    case 'get_recent':
        $limit = intval($_GET['limit'] ?? 50);
        $activity_type = $_GET['activity_type'] ?? null;

        $logs = $logger->getRecentLogs($limit, $activity_type);

        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'total' => count($logs)
        ]);
        break;

    case 'get_by_user':
        $user_id = intval($_GET['user_id'] ?? 0);
        $limit = intval($_GET['limit'] ?? 100);

        if ($user_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
            break;
        }

        $logs = $logger->getLogsByUser($user_id, $limit);

        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'total' => count($logs)
        ]);
        break;

    case 'get_by_date_range':
        $start_date = $_GET['start_date'] ?? '';
        $end_date = $_GET['end_date'] ?? '';
        $activity_type = $_GET['activity_type'] ?? null;

        if (!$start_date || !$end_date) {
            echo json_encode(['success' => false, 'message' => 'Start date and end date are required']);
            break;
        }

        $logs = $logger->getLogsByDateRange($start_date, $end_date, $activity_type);

        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'total' => count($logs)
        ]);
        break;

    case 'get_activity_types':
         // Get distinct activity types for filtering
         $stmt = $conn->prepare("SELECT DISTINCT activity_type FROM tbl_logs ORDER BY activity_type");
         $stmt->execute();
         $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

         $activity_types = [];
         foreach ($result as $row) {
             $activity_types[] = $row['activity_type'];
         }

         echo json_encode([
             'success' => true,
             'activity_types' => $activity_types
         ]);
         break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

?>
?>
