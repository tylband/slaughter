<?php

// Database connection settings
$host = '192.168.10.247';
$dbname = 'ppmp_system';
$username = 'prroot';
$password = 'ee20de';

try {
    // Start time tracking
    $startTime = microtime(true);

    // Create a new PDO instance and establish a connection
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Sample query setup (you may replace these values with actual data from a request)
    $orderColumn = 2; // For example, we order by the third column 'Item_Name'
    $searchValue = 'sample'; // Search for 'sample'
    $orderDir = 'ASC'; // Order direction (ASC or DESC)
    $start = 0; // Start of pagination
    $length = 10; // Limit for pagination

    // Map column index to database column
    $columns = ['ID', 'Item_Code', 'Item_Name', 'Items_Description', 'Unit', 'Unit_Cost', 'Category'];
    $orderBy = $columns[$orderColumn] ?? 'Item_Code';

    // Build WHERE clause for search
    $whereClause = '';
    $params = [];
    if (!empty($searchValue)) {
        $whereClause = "WHERE (Item_Code LIKE :search OR Item_Name LIKE :search OR Items_Description LIKE :search OR Category LIKE :search)";
        $params[':search'] = '%' . $searchValue . '%';
    }

    // Get total count
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_ppmp_bac_items $whereClause");
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get filtered count (same as total if no search)
    $filteredCount = $totalCount;

    // Get data with pagination and ordering
    $stmt = $conn->prepare("SELECT ID, Item_Code, Item_Name, Items_Description, Unit, Unit_Cost, Category FROM tbl_ppmp_bac_items $whereClause ORDER BY $orderBy $orderDir LIMIT $length OFFSET $start");
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // End time tracking
    $endTime = microtime(true);

    // Calculate the elapsed time
    $executionTime = ($endTime - $startTime) / 60; // in minutes
    echo "Query executed in " . $executionTime . " minutes.\n";

    // Optionally, you can also display the fetched data
    // print_r($items); // Uncomment this to print the fetched items if needed.

} catch (PDOException $e) {
    // Handle any connection or query errors
    echo "Error: " . $e->getMessage();
}

?>
