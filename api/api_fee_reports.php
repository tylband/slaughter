<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config.php';
require_once '../token_auth.php';

// Authenticate user with token for all requests except OPTIONS
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $user_data = TokenAuth::authenticate($conn);
    if (!$user_data) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

try {
    // Get date range parameters
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;

    // Build WHERE clause for date filtering
    $date_where = "";
    $params = [];

    if ($start_date && $end_date) {
        $date_where = "WHERE DATE(s.Slaughter_Date) BETWEEN ? AND ?";
        $params = [$start_date, $end_date];
    } elseif ($start_date) {
        $date_where = "WHERE DATE(s.Slaughter_Date) >= ?";
        $params = [$start_date];
    } elseif ($end_date) {
        $date_where = "WHERE DATE(s.Slaughter_Date) <= ?";
        $params = [$end_date];
    }

    // Get all operations within date range
    $stmt = $conn->prepare("
        SELECT DATE(s.Slaughter_Date) as date,
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
        {$date_where}
        ORDER BY s.Slaughter_Date DESC, s.SID DESC
    ");
    $stmt->execute($params);
    $operations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate summary statistics
    $total_operations = count($operations);
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
        'operations' => $operations
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>