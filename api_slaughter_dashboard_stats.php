<?php
// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once 'config.php';
require_once 'token_auth.php';


// Authenticate user with token-based authentication
$user_auth = TokenAuth::authenticate($conn);
if (!$user_auth) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    // Check cache first (if Redis cache is available)
    $cache_key = 'dashboard_stats';
    if (class_exists('RedisCache')) {
        $cached_result = RedisCache::get($cache_key);
        if ($cached_result !== null) {
            echo json_encode($cached_result);
            exit();
        }
    }

    // Total clients
    $stmt = $conn->prepare("SELECT COUNT(*) as total_clients FROM tbl_clients");
    $stmt->execute();
    $total_clients = $stmt->fetch(PDO::FETCH_ASSOC)['total_clients'];

    // Active businesses
    $stmt = $conn->prepare("SELECT COUNT(*) as active_businesses FROM tbl_client_business");
    $stmt->execute();
    $active_businesses = $stmt->fetch(PDO::FETCH_ASSOC)['active_businesses'];

    // Animals processed (total heads)
    $stmt = $conn->prepare("SELECT SUM(No_of_Heads) as animals_processed FROM tbl_slaughter_details");
    $stmt->execute();
    $animals_processed = $stmt->fetch(PDO::FETCH_ASSOC)['animals_processed'] ?? 0;

    // Recent slaughter operations (last 10)
    $stmt = $conn->prepare("
        SELECT s.SID, s.Slaughter_Date, c.Firstname, c.Surname, cb.Business_Name,
               SUM(sd.No_of_Heads) as total_heads, SUM(sd.No_of_Kilos) as total_kilos,
               u.Name as added_by
        FROM tbl_slaughter s
        LEFT JOIN tbl_clients c ON s.CID = c.CID
        LEFT JOIN tbl_client_business cb ON s.BID = cb.BID
        LEFT JOIN tbl_slaughter_details sd ON s.SID = sd.SID
        LEFT JOIN tbl_users u ON s.Added_by = u.UID
        GROUP BY s.SID
        ORDER BY s.Slaughter_Date DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recent_operations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Monthly slaughter data for chart (current year)
    $stmt = $conn->prepare("
        SELECT MONTH(Slaughter_Date) as month, SUM(sd.No_of_Heads) as heads
        FROM tbl_slaughter s
        LEFT JOIN tbl_slaughter_details sd ON s.SID = sd.SID
        WHERE YEAR(Slaughter_Date) = YEAR(CURDATE())
        GROUP BY MONTH(Slaughter_Date)
        ORDER BY MONTH(Slaughter_Date)
    ");
    $stmt->execute();
    $monthly_data = array_fill(0, 12, 0); // Initialize with 0s
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $monthly_data[$row['month'] - 1] = (int)$row['heads'];
    }

    // Animal types distribution
    $stmt = $conn->prepare("
        SELECT a.Animal, SUM(sd.No_of_Heads) as total_heads
        FROM tbl_slaughter_details sd
        LEFT JOIN tbl_animals a ON sd.AID = a.AID
        GROUP BY a.AID, a.Animal
        ORDER BY total_heads DESC
        LIMIT 10
    ");
    $stmt->execute();
    $animal_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [
        'success' => true,
        'stats' => [
            'total_clients' => (int)$total_clients,
            'active_businesses' => (int)$active_businesses,
            'animals_processed' => (int)$animals_processed,
            'recent_operations_count' => count($recent_operations)
        ],
        'recent_operations' => $recent_operations,
        'monthly_data' => $monthly_data,
        'animal_distribution' => $animal_distribution
    ];

    // Cache the result for 3 minutes (if Redis cache is available)
    if (class_exists('RedisCache')) {
        RedisCache::set($cache_key, $result, 180);
    }

    echo json_encode($result);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
