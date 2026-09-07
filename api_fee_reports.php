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

try {
    // Get date range parameters
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    $client_id = isset($_GET['client_id']) ? $_GET['client_id'] : null;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 5;
    $is_export = isset($_GET['export']) && $_GET['export'] == '1';

    // Build WHERE clause for date filtering and exclude deleted records
    $deleted_clause = "(s.isdeleted != '1' AND (s.isdeleted IS NULL OR s.isdeleted = '0'))";
    $date_where = "";
    $client_where = "";
    $params = [];

    // Handle client filter - add to params last (comes last in WHERE clause)
    if ($client_id) {
        $client_where = " AND s.CID = ?";
    }

    // Handle date filter - add to params first (comes first in WHERE clause after deleted_clause)
    if ($start_date && $end_date) {
        $date_where = "AND DATE(s.Slaughter_Date) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    } elseif ($start_date) {
        $date_where = "AND DATE(s.Slaughter_Date) >= ?";
        $params[] = $start_date;
    } elseif ($end_date) {
        $date_where = "AND DATE(s.Slaughter_Date) <= ?";
        $params[] = $end_date;
    }

    // Add client_id to params after date params
    if ($client_id) {
        $params[] = $client_id;
    }

    // Combine where clauses
    $where_clause = "WHERE {$deleted_clause} {$date_where} {$client_where}";

    // Get total rows first for pagination metadata
    $count_stmt = $conn->prepare("
        SELECT COUNT(*) as total
        FROM tbl_slaughter s
        LEFT JOIN tbl_slaughter_details sd ON s.SID = sd.SID
        LEFT JOIN tbl_clients c ON s.CID = c.CID
        LEFT JOIN tbl_animals a ON sd.AID = a.AID
        {$where_clause}
    ");
    $count_stmt->execute($params);
    $total_operations = (int)$count_stmt->fetchColumn();

    $total_pages = $is_export ? 1 : max(1, (int)ceil($total_operations / $limit));
    if (!$is_export && $page > $total_pages) {
        $page = $total_pages;
    }

    $query = "
        SELECT s.Slaughter_Date as date,
               s.SID,
               c.Firstname, c.Surname,
               a.Animal,
               sd.No_of_Heads, sd.No_of_Kilos,
               sd.Slaughter_Fee, sd.Corral_Fee, sd.Ante_Mortem_Fee, sd.Post_Mortem_Fee, sd.Delivery_Fee,
               (sd.Slaughter_Fee + sd.Corral_Fee + sd.Ante_Mortem_Fee + sd.Post_Mortem_Fee + sd.Delivery_Fee) as total_fee
        FROM tbl_slaughter s
        LEFT JOIN tbl_slaughter_details sd ON s.SID = sd.SID
        LEFT JOIN tbl_clients c ON s.CID = c.CID
        LEFT JOIN tbl_animals a ON sd.AID = a.AID
        {$where_clause}
        ORDER BY s.Slaughter_Date DESC, s.SID DESC
    ";

    if (!$is_export) {
        $offset = ($page - 1) * $limit;
        $query .= " LIMIT {$limit} OFFSET {$offset}";
    }

    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $operations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate summary statistics
    $total_fees = 0;
    foreach ($operations as $operation) {
        $total_fees += $operation['total_fee'];
    }

    echo json_encode([
        'success' => true,
        'date_range' => [
            'start_date' => $start_date,
            'end_date' => $end_date
        ],
        'summary' => [
            'total_operations' => $total_operations,
            'total_fees' => $total_fees
        ],
        'pagination' => [
            'page' => $page,
            'limit' => $is_export ? $total_operations : $limit,
            'total' => $total_operations,
            'total_pages' => $total_pages
        ],
        'operations' => $operations
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
