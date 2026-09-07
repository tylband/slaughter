<?php
// === CORS HEADERS (fixed) ===
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// === HANDLE OPTIONS PRE-FLIGHT REQUEST ===
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// === INCLUDE FILES ===
require_once 'config.php';
require_once 'token_auth.php';

// Suppress PHP errors that might corrupt JSON response
error_reporting(0);
ini_set('display_errors', 0);
// System logger is optional
$logger_available = false;
if (file_exists("system_logger.php")) {
    require_once "system_logger.php";
    $logger_available = true;
}


$action = $_GET['action'] ?? 'get_recent';

// Set JSON headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Authenticate user with token-based authentication
$user_auth = TokenAuth::authenticate($conn);
if (!$user_auth) {
    // For get_recent action, allow guest access for viewing recent logs only
    if ($action === 'get_recent') {
        $user_data = [
            'user_id' => null,
            'username' => 'guest',
            'role' => 'guest'
        ];
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
} else {
    $user_data = [
        'user_id' => $user_auth['user_id'],
        'username' => $user_auth['username'],
        'role' => $user_auth['role']
    ];

    // Only allow admin users for non-get_recent actions
    if ($action !== 'get_recent' && $user_data['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required.']);
        exit();
    }
}

if (class_exists('SystemLogger')) {
    $logger = new SystemLogger($conn, $user_data['user_id'], $user_data['username']);
} else {
    // Create a simple logger fallback
    $logger = null;
}

switch ($action) {
    case 'get_recent':
        $limit = intval($_GET['limit'] ?? 50);
        $activity_type = $_GET['activity_type'] ?? null;

        if ($logger_available && class_exists('SystemLogger') && method_exists($logger, 'getRecentLogs')) {
            $logs = $logger->getRecentLogs($limit, $activity_type);
        } else {
            // Fallback: Direct database query when SystemLogger is not available
            $logs = getLogsFromDatabase($conn, $limit, $activity_type);
        }

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

        if ($logger_available && class_exists('SystemLogger') && method_exists($logger, 'getLogsByUser')) {
            $logs = $logger->getLogsByUser($user_id, $limit);
        } else {
            // Fallback: Direct database query
            $logs = getLogsByUserFromDatabase($conn, $user_id, $limit);
        }

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

        if ($logger_available && class_exists('SystemLogger') && method_exists($logger, 'getLogsByDateRange')) {
            $logs = $logger->getLogsByDateRange($start_date, $end_date, $activity_type);
        } else {
            // Fallback: Direct database query
            $logs = getLogsByDateRangeFromDatabase($conn, $start_date, $end_date, $activity_type);
        }

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

/**
 * Fallback function to get logs directly from database when SystemLogger is not available
 */
function getLogsFromDatabase($conn, $limit, $activity_type = null) {
    try {
        $sql = "
            SELECT l.*,
                   COALESCE(u.Name, 'System') as username,
                   COALESCE(u.Username, 'system') as username_alt
            FROM tbl_logs l
            LEFT JOIN tbl_users u ON l.user_id = u.UID
        ";

        $params = [];
        $conditions = [];

        if ($activity_type) {
            $conditions[] = "l.activity_type = ?";
            $params[] = $activity_type;
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY l.created_at DESC LIMIT " . intval($limit);

        $stmt = $conn->prepare($sql);

        // Bind parameters (excluding the limit)
        for ($i = 0; $i < count($params); $i++) {
            $stmt->bindValue($i + 1, $params[$i]);
        }

        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return formatLogs($logs);

    } catch (PDOException $e) {
        error_log('Error fetching logs from database: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get logs for a specific user
 */
function getLogsByUserFromDatabase($conn, $user_id, $limit) {
    try {
        $sql = "
            SELECT l.*,
                   COALESCE(u.Name, 'System') as username,
                   COALESCE(u.Username, 'system') as username_alt
            FROM tbl_logs l
            LEFT JOIN tbl_users u ON l.user_id = u.UID
            WHERE l.user_id = ?
            ORDER BY l.created_at DESC
            LIMIT " . intval($limit) . "
        ";

        $stmt = $conn->prepare($sql);
        $stmt->execute([$user_id]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return formatLogs($logs);

    } catch (PDOException $e) {
        error_log('Error fetching user logs from database: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get logs within a date range
 */
function getLogsByDateRangeFromDatabase($conn, $start_date, $end_date, $activity_type = null) {
    try {
        $sql = "
            SELECT l.*,
                   COALESCE(u.Name, 'System') as username,
                   COALESCE(u.Username, 'system') as username_alt
            FROM tbl_logs l
            LEFT JOIN tbl_users u ON l.user_id = u.UID
            WHERE DATE(l.created_at) BETWEEN ? AND ?
        ";

        $params = [$start_date, $end_date];

        if ($activity_type) {
            $sql .= " AND l.activity_type = ?";
            $params[] = $activity_type;
        }

        $sql .= " ORDER BY l.created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return formatLogs($logs);

    } catch (PDOException $e) {
        error_log('Error fetching logs by date range from database: ' . $e->getMessage());
        return [];
    }
}

/**
 * Format logs to match expected structure
 */
function formatLogs($logs) {
    $formatted_logs = [];
    foreach ($logs as $log) {
        $formatted_logs[] = [
            'id' => $log['id'] ?? $log['log_id'] ?? null,
            'user_id' => $log['user_id'],
            'username' => $log['username'] ?: $log['username_alt'] ?: 'System',
            'activity_type' => $log['activity_type'],
            'activity_description' => $log['activity_description'] ?: $log['description'] ?: 'Activity performed',
            'table_affected' => $log['table_affected'],
            'record_id' => $log['record_id'],
            'old_values' => $log['old_values'] ? json_decode($log['old_values'], true) : null,
            'new_values' => $log['new_values'] ? json_decode($log['new_values'], true) : null,
            'ip_address' => $log['ip_address'],
            'created_at' => $log['created_at'],
            'updated_at' => $log['updated_at'] ?? $log['created_at']
        ];
    }
    return $formatted_logs;
}
?>
