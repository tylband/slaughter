<?php
// API endpoint for inventory dashboard data

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include database authentication functions
require_once 'db_auth.php';

function getTableColumns($conn, $table) {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $conn->query("DESCRIBE {$table}");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $columns = [];
    foreach ($rows as $row) {
        if (isset($row['Field'])) {
            $columns[] = $row['Field'];
        }
    }
    $cache[$table] = $columns;
    return $columns;
}

function pickColumn($columns, $candidates) {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function normalizeWorkArea($value, $default = 'CHO') {
    $value = strtoupper(trim((string)$value));
    if ($value === '') {
        return $default;
    }
    $value = preg_replace('/[^A-Z0-9 _-]/', '', $value);
    return $value !== '' ? $value : $default;
}

function sqlQuote($value) {
    return "'" . str_replace("'", "''", (string)$value) . "'";
}

function buildWorkAreaCondition($alias, $column, $workArea) {
    if (!$column) {
        return '';
    }

    $qualified = $alias !== '' ? "{$alias}.{$column}" : $column;
    return " AND UPPER(COALESCE(NULLIF(TRIM({$qualified}), ''), 'CHO')) = " . sqlQuote($workArea);
}

function buildEmptyMonthlySpend($months) {
    $result = [];
    $cursor = new DateTime('first day of this month');
    $cursor->modify('-' . max(0, $months - 1) . ' months');
    for ($i = 0; $i < $months; $i++) {
        $result[] = [
            'month' => $cursor->format('Y-m'),
            'label' => $cursor->format('M Y'),
            'purchased' => 0,
            'donated' => 0
        ];
        $cursor->modify('+1 month');
    }
    return $result;
}

// Validate token first
$userData = validateToken();
if (!$userData) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid or expired token."
    ]);
    exit;
}

// Connect to cho_inventory database for inventory data
$inventory_conn = null;
try {
    $inv_host = getenv('DB_HOST');
    $inv_dbname = getenv('DB_NAME');
    $inv_username = getenv('DB_USER');
    $inv_password = getenv('DB_PASS');

    if (!$inv_host || !$inv_dbname || !$inv_username) {
        throw new Exception("Database configuration missing from .env file");
    }

    $inventory_conn = new PDO("mysql:host=$inv_host;dbname=$inv_dbname;charset=utf8", $inv_username, $inv_password);
    $inventory_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $inventory_conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Database connection failed: " . $e->getMessage()
    ]);
    exit;
}

$itemWorkAreaColumns = [
    'tbl_item_medicine' => pickColumn(getTableColumns($inventory_conn, 'tbl_item_medicine'), ['Work_Area', 'work_area', 'WorkArea', 'workarea']),
    'tbl_item_medical_supplies' => pickColumn(getTableColumns($inventory_conn, 'tbl_item_medical_supplies'), ['Work_Area', 'work_area', 'WorkArea', 'workarea']),
    'tbl_item_vaccines' => pickColumn(getTableColumns($inventory_conn, 'tbl_item_vaccines'), ['Work_Area', 'work_area', 'WorkArea', 'workarea']),
    'tbl_item_lab_reagents' => pickColumn(getTableColumns($inventory_conn, 'tbl_item_lab_reagents'), ['Work_Area', 'work_area', 'WorkArea', 'workarea'])
];
$checkedoutColumns = getTableColumns($inventory_conn, 'tbl_checkedout_items');
$checkedoutDeletedColumn = pickColumn($checkedoutColumns, ['isDeleted', 'isdeleted', 'IsDeleted', 'Isdeleted']);
$checkedoutWorkAreaColumn = pickColumn($checkedoutColumns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);

$queryContext = [
    'item_work_area' => $itemWorkAreaColumns,
    'checkedout_deleted' => $checkedoutDeletedColumn,
    'checkedout_work_area' => $checkedoutWorkAreaColumn
];

// Fetch data based on type parameter
$type = $_GET['type'] ?? 'all';
$months = isset($_GET['months']) ? (int)$_GET['months'] : 12;
if ($months <= 0) {
    $months = 12;
}
if ($months > 36) {
    $months = 36;
}

$userLocationWorkArea = normalizeWorkArea($userData['location'] ?? 'CHO');
$requestedWorkArea = normalizeWorkArea($_GET['work_area'] ?? $userLocationWorkArea);

$response = [
    "status" => "success",
    "user" => $userData['username'],
    "data" => [
        'work_area' => $requestedWorkArea
    ]
];

try {
    switch ($type) {
        case 'low_stock':
            $response['data']['lowStockItems'] = getLowStockItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['lowStockCount'] = count($response['data']['lowStockItems']);
            break;

        case 'most_checked_out':
            $response['data']['mostCheckedOut'] = getMostCheckedOutItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['mostCheckedOutCount'] = count($response['data']['mostCheckedOut']);
            break;

        case 'near_expiry':
            $response['data']['expiryRed'] = getExpiryRedItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryGreen'] = getExpiryGreenItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryYellow'] = getExpiryYellowItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryOrange'] = getExpiryOrangeItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryRedCount'] = count($response['data']['expiryRed']);
            $response['data']['expiryGreenCount'] = count($response['data']['expiryGreen']);
            $response['data']['expiryYellowCount'] = count($response['data']['expiryYellow']);
            $response['data']['expiryOrangeCount'] = count($response['data']['expiryOrange']);
            break;

        case 'all':
        default:
            $response['data']['lowStockItems'] = getLowStockItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['mostCheckedOut'] = getMostCheckedOutItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryRed'] = getExpiryRedItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryGreen'] = getExpiryGreenItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryYellow'] = getExpiryYellowItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['expiryOrange'] = getExpiryOrangeItems($inventory_conn, $requestedWorkArea, $queryContext);
            $response['data']['monthlySpend'] = getMonthlySpend($inventory_conn, $months, $requestedWorkArea, $queryContext);
            $response['data']['lowStockCount'] = count($response['data']['lowStockItems']);
            $response['data']['mostCheckedOutCount'] = count($response['data']['mostCheckedOut']);
            $response['data']['expiryRedCount'] = count($response['data']['expiryRed']);
            $response['data']['expiryGreenCount'] = count($response['data']['expiryGreen']);
            $response['data']['expiryYellowCount'] = count($response['data']['expiryYellow']);
            $response['data']['expiryOrangeCount'] = count($response['data']['expiryOrange']);
            $response['data']['actionRequired'] = $response['data']['lowStockCount']
                + $response['data']['expiryRedCount']
                + $response['data']['expiryGreenCount']
                + $response['data']['expiryYellowCount']
                + $response['data']['expiryOrangeCount'];
            break;
    }

    echo json_encode($response);
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Error fetching data: " . $e->getMessage()
    ]);
}

/**
 * Get Low Stock Items (less than 100)
 */
function getLowStockItems($conn, $workArea, $context) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $checkedoutDeletedColumn = $context['checkedout_deleted'] ?? null;
    $checkedoutWorkAreaColumn = $context['checkedout_work_area'] ?? null;
    $checkedoutDeletedFilter = $checkedoutDeletedColumn ? " AND tc.{$checkedoutDeletedColumn} = 0" : '';

    $medStockArea = buildWorkAreaCondition('', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);
    $medCheckoutItemArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);
    $medCheckoutTxArea = buildWorkAreaCondition('tc', $checkedoutWorkAreaColumn, $workArea);

    $supStockArea = buildWorkAreaCondition('', $itemWorkAreas['tbl_item_medical_supplies'] ?? null, $workArea);
    $supCheckoutItemArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_medical_supplies'] ?? null, $workArea);
    $supCheckoutTxArea = buildWorkAreaCondition('tc', $checkedoutWorkAreaColumn, $workArea);

    $vacStockArea = buildWorkAreaCondition('', $itemWorkAreas['tbl_item_vaccines'] ?? null, $workArea);
    $vacCheckoutItemArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_vaccines'] ?? null, $workArea);
    $vacCheckoutTxArea = buildWorkAreaCondition('tc', $checkedoutWorkAreaColumn, $workArea);

    $labStockArea = buildWorkAreaCondition('', $itemWorkAreas['tbl_item_lab_reagents'] ?? null, $workArea);
    $labCheckoutItemArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_lab_reagents'] ?? null, $workArea);
    $labCheckoutTxArea = buildWorkAreaCondition('tc', $checkedoutWorkAreaColumn, $workArea);

    $stmt = $conn->prepare("
        SELECT item, description, available_quantity AS total_quantity, category
        FROM (
            SELECT
                'Medicine' AS category,
                ml.Item AS item,
                ml.Description AS description,
                COALESCE(stock.total_quantity, 0) - COALESCE(checkout.total_checked_out, 0) AS available_quantity
            FROM tbl_masterlist_medicine ml
            LEFT JOIN (
                SELECT ID, SUM(Quantity) AS total_quantity
                FROM tbl_item_medicine
                WHERE isDeleted = 0{$medStockArea}
                GROUP BY ID
            ) stock ON stock.ID = ml.ID
            LEFT JOIN (
                SELECT im.ID, SUM(tc.Quantity) AS total_checked_out
                FROM tbl_item_medicine im
                INNER JOIN tbl_checkedout_items tc
                    ON im.Barcode_Number = tc.Barcode{$checkedoutDeletedFilter}
                WHERE im.isDeleted = 0{$medCheckoutItemArea}{$medCheckoutTxArea}
                GROUP BY im.ID
            ) checkout ON checkout.ID = ml.ID
            WHERE ml.isdeleted = 0

            UNION ALL

            SELECT
                'Medical Supplies' AS category,
                ml.Item AS item,
                ml.Description AS description,
                COALESCE(stock.total_quantity, 0) - COALESCE(checkout.total_checked_out, 0) AS available_quantity
            FROM tbl_masterlist_medical_supplies ml
            LEFT JOIN (
                SELECT ID, SUM(Quantity) AS total_quantity
                FROM tbl_item_medical_supplies
                WHERE isDeleted = 0{$supStockArea}
                GROUP BY ID
            ) stock ON stock.ID = ml.ID
            LEFT JOIN (
                SELECT im.ID, SUM(tc.Quantity) AS total_checked_out
                FROM tbl_item_medical_supplies im
                INNER JOIN tbl_checkedout_items tc
                    ON im.Barcode_Number = tc.Barcode{$checkedoutDeletedFilter}
                WHERE im.isDeleted = 0{$supCheckoutItemArea}{$supCheckoutTxArea}
                GROUP BY im.ID
            ) checkout ON checkout.ID = ml.ID
            WHERE ml.isdeleted = 0

            UNION ALL

            SELECT
                'Vaccines' AS category,
                ml.Item AS item,
                ml.Description AS description,
                COALESCE(stock.total_quantity, 0) - COALESCE(checkout.total_checked_out, 0) AS available_quantity
            FROM tbl_masterlist_vaccines ml
            LEFT JOIN (
                SELECT ID, SUM(Quantity) AS total_quantity
                FROM tbl_item_vaccines
                WHERE isDeleted = 0{$vacStockArea}
                GROUP BY ID
            ) stock ON stock.ID = ml.ID
            LEFT JOIN (
                SELECT im.ID, SUM(tc.Quantity) AS total_checked_out
                FROM tbl_item_vaccines im
                INNER JOIN tbl_checkedout_items tc
                    ON im.Barcode_Number = tc.Barcode{$checkedoutDeletedFilter}
                WHERE im.isDeleted = 0{$vacCheckoutItemArea}{$vacCheckoutTxArea}
                GROUP BY im.ID
            ) checkout ON checkout.ID = ml.ID
            WHERE ml.isdeleted = 0

            UNION ALL

            SELECT
                'Lab Reagents' AS category,
                ml.Item AS item,
                ml.Description AS description,
                COALESCE(stock.total_quantity, 0) - COALESCE(checkout.total_checked_out, 0) AS available_quantity
            FROM tbl_masterlist_lab_reagents ml
            LEFT JOIN (
                SELECT ID, SUM(Quantity) AS total_quantity
                FROM tbl_item_lab_reagents
                WHERE isDeleted = 0{$labStockArea}
                GROUP BY ID
            ) stock ON stock.ID = ml.ID
            LEFT JOIN (
                SELECT im.ID, SUM(tc.Quantity) AS total_checked_out
                FROM tbl_item_lab_reagents im
                INNER JOIN tbl_checkedout_items tc
                    ON im.Barcode_Number = tc.Barcode{$checkedoutDeletedFilter}
                WHERE im.isDeleted = 0{$labCheckoutItemArea}{$labCheckoutTxArea}
                GROUP BY im.ID
            ) checkout ON checkout.ID = ml.ID
            WHERE ml.isDeleted = 0
        ) combined
        WHERE available_quantity < 100
        ORDER BY available_quantity ASC, item ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get Most Checked Out Items
 */
function getMostCheckedOutItems($conn, $workArea, $context) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $checkedoutDeletedColumn = $context['checkedout_deleted'] ?? null;
    $checkedoutWorkAreaColumn = $context['checkedout_work_area'] ?? null;

    $medicineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);
    $checkoutArea = buildWorkAreaCondition('coi', $checkedoutWorkAreaColumn, $workArea);
    $checkoutDeletedJoin = $checkedoutDeletedColumn ? " AND coi.{$checkedoutDeletedColumn} = 0" : '';

    $stmt = $conn->prepare("
        SELECT ml.item, ml.description, COUNT(coi.CID) AS checkout_count, SUM(coi.quantity) AS total_checked_out
        FROM tbl_item_medicine im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_checkedout_items coi ON im.Barcode_Number = coi.barcode{$checkoutDeletedJoin}{$checkoutArea}
        WHERE im.isDeleted = 0{$medicineArea}
        GROUP BY ml.id, ml.item, ml.description
        ORDER BY checkout_count DESC, total_checked_out DESC
        LIMIT 10
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Get Near Expiry RED Items (0-3 months)
 * Items that should be used soon - expiring within 3 months
 */
function formatStockCardLocation($row) {
    $parts = array_filter([
        $row['Location_Room'] ?? '',
        $row['Location_Cabinet'] ?? '',
        $row['Location_Shelf'] ?? '',
        $row['Location_Bin'] ?? '',
    ], fn($p) => trim($p) !== '');
    $location = implode(' / ', $parts);
    $note = trim($row['Location_Note'] ?? '');
    if ($note !== '') {
        $location = $location !== '' ? ($location . ' (' . $note . ')') : $note;
    }
    return $location;
}

function getExpiryRedItems($conn, $workArea, $context) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $medicineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);
    $labArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_lab_reagents'] ?? null, $workArea);
    $vaccineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_vaccines'] ?? null, $workArea);

    $query = "
        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_medicine im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= CURDATE()
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        AND im.quantity > 0{$medicineArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_lab_reagents im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= CURDATE()
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        AND im.quantity > 0{$labArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_vaccines im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= CURDATE()
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        AND im.quantity > 0{$vaccineArea}

        ORDER BY expiry_date ASC
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['stock_card_location'] = formatStockCardLocation($row);
    }
    return $rows;
}

/**
 * Get Near Expiry GREEN Items (9-12 months)
 * Items expiring later this year - 9 to 12 months
 */
function getExpiryGreenItems($conn, $workArea, $context) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $medicineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);
    $labArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_lab_reagents'] ?? null, $workArea);
    $vaccineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_vaccines'] ?? null, $workArea);

    $query = "
        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_medicine im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 9 MONTH)
        AND im.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
        AND im.quantity > 0{$medicineArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_lab_reagents im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 9 MONTH)
        AND im.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
        AND im.quantity > 0{$labArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_vaccines im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 9 MONTH)
        AND im.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 12 MONTH)
        AND im.quantity > 0{$vaccineArea}

        ORDER BY expiry_date ASC
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['stock_card_location'] = formatStockCardLocation($row);
    }
    return $rows;
}

/**
 * Get Near Expiry YELLOW Items (3-6 months)
 * Items expiring in medium term - 3 to 6 months
 */
function getExpiryYellowItems($conn, $workArea, $context) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $medicineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);
    $labArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_lab_reagents'] ?? null, $workArea);
    $vaccineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_vaccines'] ?? null, $workArea);

    $query = "
        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_medicine im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND im.quantity > 0{$medicineArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_lab_reagents im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND im.quantity > 0{$labArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_vaccines im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND im.quantity > 0{$vaccineArea}

        ORDER BY expiry_date ASC
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['stock_card_location'] = formatStockCardLocation($row);
    }
    return $rows;
}

/**
 * Get Near Expiry ORANGE Items (6-9 months)
 * Items expiring in medium term - 6 to 9 months
 */
function getExpiryOrangeItems($conn, $workArea, $context) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $medicineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);
    $labArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_lab_reagents'] ?? null, $workArea);
    $vaccineArea = buildWorkAreaCondition('im', $itemWorkAreas['tbl_item_vaccines'] ?? null, $workArea);

    $query = "
        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_medicine im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 9 MONTH)
        AND im.quantity > 0{$medicineArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_lab_reagents im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 9 MONTH)
        AND im.quantity > 0{$labArea}

        UNION ALL

        SELECT ml.item, ml.description, im.Barcode_Number, im.expiry_date, im.quantity,
               COALESCE(sc.Location_Room, '') AS Location_Room, COALESCE(sc.Location_Cabinet, '') AS Location_Cabinet,
               COALESCE(sc.Location_Shelf, '') AS Location_Shelf, COALESCE(sc.Location_Bin, '') AS Location_Bin,
               COALESCE(sc.Location_Note, '') AS Location_Note
        FROM tbl_item_vaccines im
        INNER JOIN tbl_masterlist_medicine ml ON im.id = ml.id
        LEFT JOIN tbl_stock_cards sc ON im.Barcode_Number = sc.Barcode_Number
        WHERE im.isDeleted = 0
        AND im.expiry_date >= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        AND im.expiry_date < DATE_ADD(CURDATE(), INTERVAL 9 MONTH)
        AND im.quantity > 0{$vaccineArea}

        ORDER BY expiry_date ASC
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['stock_card_location'] = formatStockCardLocation($row);
    }
    return $rows;
}

/**
 * Get Monthly Spend vs Donated Value (last N months)
 */
function getMonthlySpend($conn, $months = 12, $workArea = 'CHO', $context = []) {
    $itemWorkAreas = $context['item_work_area'] ?? [];
    $medicineArea = buildWorkAreaCondition('', $itemWorkAreas['tbl_item_medicine'] ?? null, $workArea);
    $suppliesArea = buildWorkAreaCondition('', $itemWorkAreas['tbl_item_medical_supplies'] ?? null, $workArea);
    $vaccineArea = buildWorkAreaCondition('', $itemWorkAreas['tbl_item_vaccines'] ?? null, $workArea);
    $labArea = buildWorkAreaCondition('', $itemWorkAreas['tbl_item_lab_reagents'] ?? null, $workArea);

    $stmt = $conn->prepare("
        SELECT ym,
               SUM(purchased_amount) AS purchased_amount,
               SUM(donated_amount) AS donated_amount
        FROM (
            SELECT DATE_FORMAT(Date_Added, '%Y-%m') AS ym,
                   SUM(CASE WHEN Donated = 0 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS purchased_amount,
                   SUM(CASE WHEN Donated = 1 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS donated_amount
            FROM tbl_item_medicine
            WHERE isDeleted = 0{$medicineArea} AND Date_Added IS NOT NULL
            GROUP BY ym

            UNION ALL

            SELECT DATE_FORMAT(Date_Added, '%Y-%m') AS ym,
                   SUM(CASE WHEN Donated = 0 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS purchased_amount,
                   SUM(CASE WHEN Donated = 1 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS donated_amount
            FROM tbl_item_medical_supplies
            WHERE isDeleted = 0{$suppliesArea} AND Date_Added IS NOT NULL
            GROUP BY ym

            UNION ALL

            SELECT DATE_FORMAT(Date_Added, '%Y-%m') AS ym,
                   SUM(CASE WHEN Donated = 0 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS purchased_amount,
                   SUM(CASE WHEN Donated = 1 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS donated_amount
            FROM tbl_item_vaccines
            WHERE isDeleted = 0{$vaccineArea} AND Date_Added IS NOT NULL
            GROUP BY ym

            UNION ALL

            SELECT DATE_FORMAT(Date_Added, '%Y-%m') AS ym,
                   SUM(CASE WHEN Donated = 0 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS purchased_amount,
                   SUM(CASE WHEN Donated = 1 THEN COALESCE(Unit_Cost, 0) * COALESCE(Quantity, 0) ELSE 0 END) AS donated_amount
            FROM tbl_item_lab_reagents
            WHERE isDeleted = 0{$labArea} AND Date_Added IS NOT NULL
            GROUP BY ym
        ) combined
        GROUP BY ym
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $byMonth = [];
    foreach ($rows as $row) {
        $monthKey = $row['ym'];
        $byMonth[$monthKey] = [
            'purchased' => (float)($row['purchased_amount'] ?? 0),
            'donated' => (float)($row['donated_amount'] ?? 0)
        ];
    }

    $result = [];
    $cursor = new DateTime('first day of this month');
    $cursor->modify('-' . max(0, $months - 1) . ' months');
    for ($i = 0; $i < $months; $i++) {
        $key = $cursor->format('Y-m');
        $label = $cursor->format('M Y');
        $values = $byMonth[$key] ?? ['purchased' => 0, 'donated' => 0];
        $result[] = [
            'month' => $key,
            'label' => $label,
            'purchased' => $values['purchased'],
            'donated' => $values['donated']
        ];
        $cursor->modify('+1 month');
    }

    return $result;
}
?>
