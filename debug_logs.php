<?php
require_once 'config.php';

try {
    // Check if tbl_logs exists and count records
    $stmt = $conn->query('SELECT COUNT(*) as count FROM tbl_logs');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo 'Total logs in tbl_logs: ' . $row['count'] . PHP_EOL;

    // Get recent logs
    $stmt = $conn->query('SELECT * FROM tbl_logs ORDER BY created_at DESC LIMIT 5');
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo 'Recent logs:' . PHP_EOL;
    foreach($logs as $log) {
        echo $log['activity_type'] . ' - ' . $log['activity_description'] . ' - ' . $log['username'] . ' - ' . $log['created_at'] . PHP_EOL;
    }

} catch(Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>