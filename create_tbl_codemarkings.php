<?php
require_once 'config.php';

try {
    // Create tbl_codemarkings table
    $sql = "CREATE TABLE IF NOT EXISTS tbl_codemarkings (
        MID INT PRIMARY KEY AUTO_INCREMENT,
        CODE VARCHAR(50) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_code (CODE)
    )";

    $conn->exec($sql);
    echo "✅ tbl_codemarkings table created successfully!\n";

    // Insert some sample data
    $sampleCodes = [
        'CM001', 'CM002', 'CM003', 'CM004', 'CM005',
        'A001', 'A002', 'B001', 'B002', 'C001'
    ];

    foreach ($sampleCodes as $code) {
        try {
            $stmt = $conn->prepare("INSERT INTO tbl_codemarkings (CODE) VALUES (?)");
            $stmt->execute([$code]);
            echo "✅ Inserted code: $code\n";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Duplicate entry error
                echo "ℹ️ Code $code already exists\n";
            } else {
                echo "❌ Error inserting code $code: " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\n🎉 Database setup completed successfully!\n";
    echo "You can now access the API endpoints without errors.\n";

} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    echo "Please make sure your database is running and credentials are correct.\n";
}
?>