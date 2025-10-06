<?php
require_once 'config.php';
require_once 'system_logger.php';

try {
    $logger = new SystemLogger($conn);

    echo "Testing SystemLogger..." . PHP_EOL;

    $logs = $logger->getRecentLogs(10);

    echo "Found " . count($logs) . " logs" . PHP_EOL;

    foreach($logs as $log) {
        echo "Activity: " . $log['activity_type'] . " - " . $log['activity_description'] . " - " . $log['username'] . PHP_EOL;
    }

} catch(Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>