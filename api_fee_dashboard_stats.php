<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';
require_once 'token_auth.php';


// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Authenticate user with token-based authentication
$user_auth = TokenAuth::authenticate($conn);
if (!$user_auth) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Total fees collected
    $stmt = $conn->prepare("
        SELECT SUM(Slaughter_Fee + Corral_Fee + Ante_Mortem_Fee + Post_Mortem_Fee + Delivery_Fee) as total_fees
        FROM tbl_slaughter_details
    ");
    $stmt->execute();
    $total_fees = $stmt->fetch(PDO::FETCH_ASSOC)['total_fees'] ?? 0;

    // Total operations
    $stmt = $conn->prepare("SELECT COUNT(*) as total_operations FROM tbl_slaughter_details");
    $stmt->execute();
    $total_operations = $stmt->fetch(PDO::FETCH_ASSOC)['total_operations'];

    // Average fee per operation
    $average_fee = $total_operations > 0 ? $total_fees / $total_operations : 0;

    // Recent fee operations (last 10)
    $stmt = $conn->prepare("
        SELECT sd.Detail_ID, s.Slaughter_Date, c.Firstname, c.Surname, a.Animal,
               sd.No_of_Heads, sd.No_of_Kilos,
               (sd.Slaughter_Fee + sd.Corral_Fee + sd.Ante_Mortem_Fee + sd.Post_Mortem_Fee + sd.Delivery_Fee) as total_fee,
               u.Name as added_by
        FROM tbl_slaughter_details sd
        LEFT JOIN tbl_slaughter s ON sd.SID = s.SID
        LEFT JOIN tbl_clients c ON s.CID = c.CID
        LEFT JOIN tbl_animals a ON sd.AID = a.AID
        LEFT JOIN tbl_users u ON s.Added_by = u.UID
        ORDER BY s.Slaughter_Date DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recent_operations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Monthly fee data for chart (current year)
    $stmt = $conn->prepare("
        SELECT MONTH(s.Slaughter_Date) as month,
               SUM(sd.Slaughter_Fee + sd.Corral_Fee + sd.Ante_Mortem_Fee + sd.Post_Mortem_Fee + sd.Delivery_Fee) as fees
        FROM tbl_slaughter s
        LEFT JOIN tbl_slaughter_details sd ON s.SID = sd.SID
        WHERE YEAR(s.Slaughter_Date) = YEAR(CURDATE())
        GROUP BY MONTH(s.Slaughter_Date)
        ORDER BY MONTH(s.Slaughter_Date)
    ");
    $stmt->execute();
    $monthly_data = array_fill(0, 12, 0); // Initialize with 0s
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $monthly_data[$row['month'] - 1] = (float)$row['fees'];
    }

    // Fee types distribution (by animal type)
    $stmt = $conn->prepare("
        SELECT a.Animal,
               SUM(sd.Slaughter_Fee + sd.Corral_Fee + sd.Ante_Mortem_Fee + sd.Post_Mortem_Fee + sd.Delivery_Fee) as total_fees,
               COUNT(*) as operations_count
        FROM tbl_slaughter_details sd
        LEFT JOIN tbl_animals a ON sd.AID = a.AID
        GROUP BY a.AID, a.Animal
        ORDER BY total_fees DESC
        LIMIT 10
    ");
    $stmt->execute();
    $fee_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'stats' => [
            'total_fees' => (float)$total_fees,
            'total_operations' => (int)$total_operations,
            'average_fee' => round((float)$average_fee, 2),
            'recent_operations_count' => count($recent_operations)
        ],
        'recent_operations' => $recent_operations,
        'monthly_data' => $monthly_data,
        'fee_distribution' => $fee_distribution
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
