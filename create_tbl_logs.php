<?php
require_once "config.php";

// Create tbl_logs table for system audit trail
try {
    $sql = "CREATE TABLE IF NOT EXISTS tbl_logs (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        username VARCHAR(100) NOT NULL,
        activity_type VARCHAR(50) NOT NULL,
        activity_description TEXT NOT NULL,
        table_affected VARCHAR(100),
        record_id VARCHAR(50),
        old_values TEXT,
        new_values TEXT,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_activity_type (activity_type),
        INDEX idx_created_at (created_at),
        INDEX idx_table_affected (table_affected)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // Execute the SQL
    $conn->exec($sql);
    echo "✅ tbl_logs table created successfully!\n";

    // Create additional indexes for better performance
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_logs_user_activity ON tbl_logs(user_id, activity_type)",
        "CREATE INDEX IF NOT EXISTS idx_logs_date_range ON tbl_logs(created_at, activity_type)",
        "CREATE INDEX IF NOT EXISTS idx_logs_table_record ON tbl_logs(table_affected, record_id)"
    ];

    foreach ($indexes as $index_sql) {
        $conn->exec($index_sql);
    }

    echo "✅ Indexes created successfully!\n";
    echo "📋 tbl_logs table is ready for system audit logging!\n";

} catch(PDOException $e) {
    echo "❌ Error creating tbl_logs table: " . $e->getMessage() . "\n";
}
?>