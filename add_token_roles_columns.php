<?php
require_once 'config.php';

try {
    // Add token column
    $sql1 = "ALTER TABLE tbl_users ADD COLUMN token VARCHAR(255) NULL AFTER Password";
    $conn->exec($sql1);
    echo "Token column added successfully.\n";

    // Add roles column
    $sql2 = "ALTER TABLE tbl_users ADD COLUMN roles VARCHAR(50) NOT NULL DEFAULT 'user' AFTER token";
    $conn->exec($sql2);
    echo "Roles column added successfully.\n";

    // Create index on token for faster lookups
    $sql3 = "CREATE INDEX idx_token ON tbl_users(token)";
    $conn->exec($sql3);
    echo "Index on token column created successfully.\n";

    echo "Database migration completed successfully!\n";

} catch (PDOException $e) {
    if ($e->getCode() == '42S21') { // Column already exists
        echo "Columns already exist. Migration may have been run before.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>