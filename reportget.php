<?php
ini_set('display_errors', 0); // hide raw errors
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");

require_once 'config.php';

// =====================
// HELPER: RETURN JSON ERROR
// =====================
function jsonError($message) {
    echo json_encode([
        'status' => 'error',
        'message' => $message
    ]);
    exit;
}

// =====================
// CHECK DB CONNECTION
// =====================
if ($conn->connect_error) {
    jsonError('Database connection failed: ' . $conn->connect_error);
}

// =====================
// DATE FILTER
// =====================
$from = $_GET['from'] ?? null;
$to   = $_GET['to'] ?? null;

$dateCond = "";
if ($from && $to) {
    // Correct column name is 'date_impounded'
    $dateCond = " AND DATE(o.date_impounded) BETWEEN '$from' AND '$to'";
}

// =====================
// INIT DATA
// =====================
$byVehicle = [
    'MC' => ['impounded' => 0, 'claimed' => 0],
    'MV' => ['impounded' => 0, 'claimed' => 0],
];

$byType = [];

$totalImpounded = 0;
$totalClaimed = 0;

// =====================
// UNRELEASED (IMPOUNDED)
// =====================
$sqlOffense = "
SELECT vehicle_type, impoundtype, COUNT(*) AS total
FROM tbl_offense o
WHERE o.isdeleted = 0
  AND o.vehicle_type IN (1,2)
  $dateCond
GROUP BY vehicle_type, impoundtype
";

$res = $conn->query($sqlOffense);
if (!$res) {
    jsonError('Query failed (impounded): ' . $conn->error);
}

while ($row = $res->fetch_assoc()) {
    // Vehicle type
    $vehKey = $row['vehicle_type'] == 1 ? 'MC' : 'MV';
    $byVehicle[$vehKey]['impounded'] += (int)$row['total'];
    $totalImpounded += (int)$row['total'];

    // Impound type
    $type = $row['impoundtype'];
    if (!isset($byType[$type])) {
        $byType[$type] = ['impounded' => 0, 'claimed' => 0];
    }
    $byType[$type]['impounded'] += (int)$row['total'];
}

// =====================
// RELEASED (CLAIMED)
// =====================
$sqlClaim = "
SELECT o.vehicle_type, o.impoundtype, COUNT(c.claim_id) AS total
FROM tbl_claim c
JOIN tbl_offense o ON c.offenseid = o.offenseid
WHERE o.vehicle_type IN (1,2)
  $dateCond
GROUP BY o.vehicle_type, o.impoundtype
";

$res = $conn->query($sqlClaim);
if (!$res) {
    jsonError('Query failed (claimed): ' . $conn->error);
}

while ($row = $res->fetch_assoc()) {
    // Vehicle type
    $vehKey = $row['vehicle_type'] == 1 ? 'MC' : 'MV';
    $byVehicle[$vehKey]['claimed'] += (int)$row['total'];
    $totalClaimed += (int)$row['total'];

    // Impound type
    $type = $row['impoundtype'];
    if (!isset($byType[$type])) {
        $byType[$type] = ['impounded' => 0, 'claimed' => 0];
    }
    $byType[$type]['claimed'] += (int)$row['total'];
}

// =====================
// RESPONSE
// =====================
echo json_encode([
    'status' => 'success',
    'total_impounded' => $totalImpounded,
    'total_claimed' => $totalClaimed,
    'by_vehicle' => $byVehicle,
    'by_type' => $byType
]);
exit;
