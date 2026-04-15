<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

require_once 'db_auth.php';
require_once 'transaction_logger.php';

function sendResponse($status, $message, $data = null, $code = 200, $meta = null) {
    http_response_code($code);
    $response = [
        'status' => $status,
        'message' => $message
    ];
    if ($data !== null) {
        $response['data'] = $data;
    }
    if ($meta !== null) {
        $response['meta'] = $meta;
    }
    echo json_encode($response);
    exit;
}

function normalizeDateTime($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    if (strlen($value) === 10) {
        return $value . ' 00:00:00';
    }
    if (strlen($value) === 16) {
        return $value . ':00';
    }
    return $value;
}

function getNextIid($conn, $itemTable) {
    $stmt = $conn->query("SELECT MAX(IID) AS max_iid FROM {$itemTable}");
    $max = $stmt ? $stmt->fetchColumn() : null;
    return $max ? ((int)$max + 1) : 1;
}

function buildBarcode($itemName, $expiryDate, $iid) {
    $cleanName = preg_replace('/\s+/', '', trim((string)$itemName));
    $prefix = $cleanName !== '' ? substr($cleanName, 0, 3) : 'ITM';
    try {
        $date = new DateTime($expiryDate ?: 'now');
    } catch (Exception $e) {
        $date = new DateTime('now');
    }
    $year = $date->format('Y');
    $month = (int)$date->format('n');
    $day = (int)$date->format('j');
    return $prefix . $year . $month . $day . $iid . 'sys';
}

function getTableColumns($conn, $table) {
    $stmt = $conn->query("DESCRIBE {$table}");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $columns = [];
    foreach ($rows as $row) {
        if (isset($row['Field'])) {
            $columns[] = $row['Field'];
        }
    }
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

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}

if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}

$categories = [
    'medicine' => [
        'master_table' => 'tbl_masterlist_medicine',
        'item_table' => 'tbl_item_medicine',
        'master_is_deleted' => 'isdeleted'
    ],
    'medical_supplies' => [
        'master_table' => 'tbl_masterlist_medical_supplies',
        'item_table' => 'tbl_item_medical_supplies',
        'master_is_deleted' => 'isdeleted'
    ],
    'vaccines' => [
        'master_table' => 'tbl_masterlist_vaccines',
        'item_table' => 'tbl_item_vaccines',
        'master_is_deleted' => 'isdeleted'
    ],
    'lab_reagents' => [
        'master_table' => 'tbl_masterlist_lab_reagents',
        'item_table' => 'tbl_item_lab_reagents',
        'master_is_deleted' => 'isDeleted'
    ]
];

$payload = [];
$rawInput = file_get_contents("php://input");
if ($rawInput !== '') {
    $json = json_decode($rawInput, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        $payload = $json;
    } elseif (!empty($_POST)) {
        $payload = $_POST;
    } else {
        sendResponse('error', 'Invalid JSON format.', null, 400);
    }
} else {
    $payload = $_POST;
}

$categoryKey = $_GET['category'] ?? ($payload['category'] ?? '');
if (!isset($categories[$categoryKey])) {
    sendResponse('error', 'Invalid category.', null, 400);
}

$category = $categories[$categoryKey];
$masterTable = $category['master_table'];
$itemTable = $category['item_table'];
$masterIsDeleted = $category['master_is_deleted'];
$itemColumns = getTableColumns($conn, $itemTable);
$workAreaColumn = pickColumn($itemColumns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
$checkedoutColumns = getTableColumns($conn, 'tbl_checkedout_items');
$checkedoutDeletedColumn = pickColumn($checkedoutColumns, ['isDeleted', 'isdeleted', 'IsDeleted', 'Isdeleted']);
$checkedoutWorkAreaColumn = pickColumn($checkedoutColumns, ['Work_Area', 'work_area', 'WorkArea', 'workarea']);
$userLocationWorkArea = normalizeWorkArea($userData['location'] ?? 'CHO');
$requestedWorkArea = normalizeWorkArea($_GET['work_area'] ?? ($payload['work_area'] ?? $userLocationWorkArea));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $search = trim($_GET['search'] ?? '');
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

        if ($limit <= 0) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        $where = "im.isDeleted = 0";
        $params = [];
        $masterId = isset($_GET['master_id']) ? (int)$_GET['master_id'] : 0;
        if ($masterId > 0) {
            $where .= " AND im.ID = :master_id";
            $params[':master_id'] = $masterId;
        }
        if ($search !== '') {
            $where .= " AND (im.Item LIKE :search OR im.Description LIKE :search OR im.Barcode LIKE :search OR im.Barcode_Number LIKE :search OR im.Remarks LIKE :search OR im.Entity LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        if ($workAreaColumn) {
            $where .= " AND UPPER(COALESCE(NULLIF(TRIM(im.{$workAreaColumn}), ''), 'CHO')) = :work_area";
            $params[':work_area'] = $requestedWorkArea;
        }

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM {$itemTable} im WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $workAreaSelect = $workAreaColumn
            ? "COALESCE(NULLIF(TRIM(im.{$workAreaColumn}), ''), 'CHO') AS Work_Area,"
            : "'CHO' AS Work_Area,";

        $checkoutWhere = $checkedoutDeletedColumn ? "{$checkedoutDeletedColumn} = 0" : "1=1";
        $checkoutParams = [];
        if ($checkedoutWorkAreaColumn) {
            $checkoutWhere .= " AND UPPER(COALESCE(NULLIF(TRIM({$checkedoutWorkAreaColumn}), ''), 'CHO')) = :checkout_work_area";
            $checkoutParams[':checkout_work_area'] = $requestedWorkArea;
        }

        $stmt = $conn->prepare("
            SELECT
                im.IID,
                im.ID,
                im.Barcode,
                im.Barcode_Number,
                im.Item,
                im.Description,
                im.Entity,
                im.Unit_Cost,
                im.Quantity,
                im.Expiry_Date,
                im.Date_Added,
                im.Added_By,
                im.Remarks,
                im.Donated,
                im.PO_Number,
                {$workAreaSelect}
                COALESCE(checkout.total_checked_out, 0) AS Checked_Out,
                (im.Quantity - COALESCE(checkout.total_checked_out, 0)) AS Available_Quantity
            FROM {$itemTable} im
            LEFT JOIN (
                SELECT Barcode, SUM(Quantity) AS total_checked_out
                FROM tbl_checkedout_items
                WHERE {$checkoutWhere}
                GROUP BY Barcode
            ) checkout ON checkout.Barcode = im.Barcode_Number
            WHERE {$where}
            ORDER BY im.Date_Added DESC, im.IID DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute(array_merge($params, $checkoutParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendResponse('success', 'Stock entries retrieved.', $rows, 200, [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'search' => $search,
            'work_area' => $workAreaColumn ? $requestedWorkArea : 'CHO'
        ]);
    } catch (Exception $e) {
        sendResponse('error', 'Error fetching stock entries: ' . $e->getMessage(), null, 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse('error', 'Invalid request method.', null, 405);
}

$action = $payload['action'] ?? '';

if ($requestedWorkArea !== 'CHO') {
    sendResponse('error', 'Inventory module is available only for CHO location.', null, 403);
}

try {
    switch ($action) {
        case 'create_item':
            $masterId = (int)($payload['masterlist_id'] ?? 0);
            if ($masterId <= 0) {
                sendResponse('error', 'Please select a masterlist item.', null, 400);
            }

            $masterStmt = $conn->prepare("
                SELECT ID, Item, Description, Entity
                FROM {$masterTable}
                WHERE ID = ? AND {$masterIsDeleted} = 0
            ");
            $masterStmt->execute([$masterId]);
            $masterRow = $masterStmt->fetch(PDO::FETCH_ASSOC);
            if (!$masterRow) {
                sendResponse('error', 'Selected masterlist item not found.', null, 404);
            }

            $barcodeNumber = trim($payload['barcode_number'] ?? '');
            $unitCost = trim($payload['unit_cost'] ?? '');
            $quantity = filter_var($payload['quantity'] ?? null, FILTER_VALIDATE_INT);
            $expiryDate = normalizeDateTime($payload['expiry_date'] ?? '');
            $dateAdded = normalizeDateTime($payload['date_added'] ?? '') ?? date('Y-m-d H:i:s');
            $remarks = trim($payload['remarks'] ?? '');
            $donated = !empty($payload['donated']) ? 1 : 0;
            $poNumber = trim($payload['po_number'] ?? '');
            $workArea = normalizeWorkArea($payload['work_area'] ?? 'CHO');

            if ($quantity === false || $quantity < 0) {
                sendResponse('error', 'Quantity must be a valid number.', null, 400);
            }
            if ($barcodeNumber === '') {
                sendResponse('error', 'Barcode number is required.', null, 400);
            }

            $unitCostValue = $unitCost === '' ? 0 : (float)$unitCost;
            $nextIid = getNextIid($conn, $itemTable);
            $barcode = buildBarcode($masterRow['Item'], $expiryDate ?: $dateAdded, $nextIid);

            $insertCols = ['ID', 'Barcode', 'Barcode_Number', 'Item', 'Description', 'Entity', 'Unit_Cost', 'Quantity', 'Expiry_Date', 'Date_Added', 'Added_By', 'Remarks', 'Donated', 'PO_Number', 'isDeleted'];
            $insertVals = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '0'];
            $insertParams = [
                $masterRow['ID'],
                $barcode,
                $barcodeNumber,
                $masterRow['Item'],
                $masterRow['Description'],
                $masterRow['Entity'],
                $unitCostValue,
                $quantity,
                $expiryDate,
                $dateAdded,
                $userData['username'],
                $remarks,
                $donated,
                $poNumber
            ];

            if ($workAreaColumn) {
                $insertCols[] = $workAreaColumn;
                $insertVals[] = '?';
                $insertParams[] = $workArea;
            }

            $stmt = $conn->prepare("
                INSERT INTO {$itemTable}
                (" . implode(', ', $insertCols) . ")
                VALUES (" . implode(', ', $insertVals) . ")
            ");
            $stmt->execute($insertParams);
            logTransactionHistory($conn, [
                'module' => 'INVENTORY',
                'action' => 'CREATE',
                'transaction_type' => 'ADDED',
                'category' => $categoryKey,
                'reference_no' => $barcodeNumber,
                'item_barcode' => $barcodeNumber,
                'item_name' => $masterRow['Item'] ?? '',
                'quantity' => $quantity,
                'performed_by' => $userData['username'] ?? 'system',
                'location' => $userData['location'] ?? '',
                'work_area' => $workArea,
                'details' => [
                    'masterlist_id' => $masterRow['ID'] ?? null,
                    'expiry_date' => $expiryDate,
                    'unit_cost' => $unitCostValue,
                    'remarks' => $remarks,
                    'donated' => $donated,
                    'po_number' => $poNumber
                ]
            ]);
            sendResponse('success', 'Stock entry recorded.');

        case 'update_item':
            $itemId = (int)($payload['item_id'] ?? 0);
            $barcodeNumber = trim($payload['barcode_number'] ?? '');
            $unitCost = trim($payload['unit_cost'] ?? '');
            $quantity = filter_var($payload['quantity'] ?? null, FILTER_VALIDATE_INT);
            $expiryDate = normalizeDateTime($payload['expiry_date'] ?? '');
            $remarks = trim($payload['remarks'] ?? '');
            $donated = !empty($payload['donated']) ? 1 : 0;
            $poNumber = trim($payload['po_number'] ?? '');

            if ($itemId <= 0 || $quantity === false || $quantity < 0) {
                sendResponse('error', 'Invalid item update request.', null, 400);
            }

            $existingStmt = $conn->prepare("SELECT * FROM {$itemTable} WHERE IID = ? LIMIT 1");
            $existingStmt->execute([$itemId]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                sendResponse('error', 'Stock entry not found.', null, 404);
            }

            $masterId = (int)($payload['masterlist_id'] ?? 0);
            if ($masterId > 0) {
                $masterStmt = $conn->prepare("
                    SELECT ID, Item, Description, Entity
                    FROM {$masterTable}
                    WHERE ID = ? AND {$masterIsDeleted} = 0
                ");
                $masterStmt->execute([$masterId]);
                $masterRow = $masterStmt->fetch(PDO::FETCH_ASSOC);
                if (!$masterRow) {
                    sendResponse('error', 'Selected masterlist item not found.', null, 404);
                }
            } else {
                $masterRow = [
                    'ID' => $existing['ID'],
                    'Item' => $existing['Item'],
                    'Description' => $existing['Description'],
                    'Entity' => $existing['Entity']
                ];
            }

            $barcodeNumber = $barcodeNumber !== '' ? $barcodeNumber : (string)$existing['Barcode_Number'];
            if ($barcodeNumber === '') {
                sendResponse('error', 'Barcode number is required.', null, 400);
            }
            $expiryDate = $expiryDate ?: $existing['Expiry_Date'];
            $dateAdded = normalizeDateTime($payload['date_added'] ?? '') ?: $existing['Date_Added'];
            $unitCostValue = $unitCost === '' ? (float)$existing['Unit_Cost'] : (float)$unitCost;
            $existingWorkArea = $workAreaColumn ? normalizeWorkArea($existing[$workAreaColumn] ?? 'CHO') : 'CHO';
            $workArea = $workAreaColumn ? normalizeWorkArea($payload['work_area'] ?? $existingWorkArea) : 'CHO';

            if ($barcodeNumber !== '') {
                if ($workAreaColumn) {
                    $softStmt = $conn->prepare("UPDATE {$itemTable} SET isDeleted = 1 WHERE Barcode_Number = ? AND UPPER(COALESCE(NULLIF(TRIM({$workAreaColumn}), ''), 'CHO')) = ?");
                    $softStmt->execute([$barcodeNumber, $existingWorkArea]);
                } else {
                    $softStmt = $conn->prepare("UPDATE {$itemTable} SET isDeleted = 1 WHERE Barcode_Number = ?");
                    $softStmt->execute([$barcodeNumber]);
                }
            } else {
                $softStmt = $conn->prepare("UPDATE {$itemTable} SET isDeleted = 1 WHERE IID = ?");
                $softStmt->execute([$itemId]);
            }

            $nextIid = getNextIid($conn, $itemTable);
            $barcode = buildBarcode($masterRow['Item'], $expiryDate ?: $dateAdded, $nextIid);

            $insertCols = ['ID', 'Barcode', 'Barcode_Number', 'Item', 'Description', 'Entity', 'Unit_Cost', 'Quantity', 'Expiry_Date', 'Date_Added', 'Added_By', 'Remarks', 'Donated', 'PO_Number', 'isDeleted'];
            $insertVals = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '0'];
            $insertParams = [
                $masterRow['ID'],
                $barcode,
                $barcodeNumber,
                $masterRow['Item'],
                $masterRow['Description'],
                $masterRow['Entity'],
                $unitCostValue,
                $quantity,
                $expiryDate,
                $dateAdded,
                $userData['username'],
                $remarks,
                $donated,
                $poNumber
            ];

            if ($workAreaColumn) {
                $insertCols[] = $workAreaColumn;
                $insertVals[] = '?';
                $insertParams[] = $workArea;
            }

            $insertStmt = $conn->prepare("
                INSERT INTO {$itemTable}
                (" . implode(', ', $insertCols) . ")
                VALUES (" . implode(', ', $insertVals) . ")
            ");
            $insertStmt->execute($insertParams);
            logTransactionHistory($conn, [
                'module' => 'INVENTORY',
                'action' => 'UPDATE',
                'transaction_type' => 'UPDATED',
                'category' => $categoryKey,
                'reference_no' => $barcodeNumber,
                'item_barcode' => $barcodeNumber,
                'item_name' => $masterRow['Item'] ?? '',
                'quantity' => $quantity,
                'performed_by' => $userData['username'] ?? 'system',
                'location' => $userData['location'] ?? '',
                'work_area' => $workArea,
                'details' => [
                    'item_id' => $itemId,
                    'expiry_date' => $expiryDate,
                    'unit_cost' => $unitCostValue,
                    'remarks' => $remarks,
                    'donated' => $donated,
                    'po_number' => $poNumber
                ]
            ]);
            sendResponse('success', 'Stock entry updated.');

        case 'delete_item':
            $itemId = (int)($payload['item_id'] ?? 0);
            if ($itemId <= 0) {
                sendResponse('error', 'Invalid item deletion request.', null, 400);
            }

            $existingStmt = $conn->prepare("SELECT Barcode_Number, Item, Quantity FROM {$itemTable} WHERE IID = ? LIMIT 1");
            $existingStmt->execute([$itemId]);
            $existingRow = $existingStmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $conn->prepare("
                UPDATE {$itemTable}
                SET isDeleted = 1
                WHERE IID = ?
            ");
            $stmt->execute([$itemId]);
            logTransactionHistory($conn, [
                'module' => 'INVENTORY',
                'action' => 'DELETE',
                'transaction_type' => 'ARCHIVED',
                'category' => $categoryKey,
                'reference_no' => (string)$itemId,
                'item_barcode' => $existingRow['Barcode_Number'] ?? '',
                'item_name' => $existingRow['Item'] ?? '',
                'quantity' => isset($existingRow['Quantity']) ? (int)$existingRow['Quantity'] : null,
                'performed_by' => $userData['username'] ?? 'system',
                'location' => $userData['location'] ?? '',
                'work_area' => $payload['work_area'] ?? 'CHO',
                'details' => [
                    'item_id' => $itemId
                ]
            ]);
            sendResponse('success', 'Stock entry archived.');

        default:
            sendResponse('error', 'Unknown action.', null, 400);
    }
} catch (Exception $e) {
    sendResponse('error', 'Error processing request: ' . $e->getMessage(), null, 500);
}
