<?php
// ==============================
// CORS & JSON HEADERS
// ==============================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Requested-With');
header('Content-Type: application/json');

// ==============================
// VALIDATE BP PARAMETER
// ==============================
if (!isset($_GET['BP'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing BP parameter'
    ]);
    exit;
}

$bp = trim($_GET['BP']);

// ==============================
// SELECT CONFIG BASED ON BP
// ==============================
$hyphenPos = strpos($bp, '-');

if ($hyphenPos !== false && substr($bp, $hyphenPos + 1, 1) === '8') {
    require_once 'business_verifier_config_upper.php';
} else {
    require_once 'business_verifier_config_local.php';
}

// ==============================
// START SESSION
// ==============================
session_start();
error_log("Business Verifier API called: " . date('Y-m-d H:i:s'));

// ==============================
// VALIDATE CN PARAMETER
// ==============================
if (!isset($_GET['CN'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing CN parameter'
    ]);
    exit;
}

// ==============================
// CLEAN & DECODE CN
// ==============================
$encryptedData = trim(urldecode($_GET['CN']));
$encryptedData = str_replace(' ', '+', $encryptedData);

if (!base64_decode($encryptedData, true)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid CN: not proper Base64',
        'debug' => $encryptedData
    ]);
    exit;
}

// ==============================
// SET DECRYPTION KEY
// ==============================
$key = "MISDreamTeam2024"; // Must match VB.NET key
$key = substr($key, 0, 16); // AES-128 requires 16 bytes

// ==============================
// DECRYPT FUNCTION (AES-128-ECB)
// ==============================
function decryptData($encryptedData, $key) {
    $decoded = base64_decode($encryptedData, true);
    if ($decoded === false) {
        return false;
    }
    $decrypted = openssl_decrypt($decoded, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
    return $decrypted !== false ? trim($decrypted) : false;
}

// ==============================
// DECRYPT CN VALUE
// ==============================
$decryptedData = decryptData($encryptedData, $key);

if ($decryptedData === false || empty($decryptedData)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid QR code data',
        'debug' => [
            'encryptedCN' => $encryptedData,
            'keyUsed' => $key
        ]
    ]);
    exit;
}

// ==============================
// VALIDATE DATABASE CONNECTION
// ==============================
if (!isset($CN) || !$CN) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed'
    ]);
    exit;
}

// ==============================
// DATABASE QUERY
// ==============================
$query = "
 SELECT 
    CONCAT(tc.lastName, ', ', tc.firstName, ' ', tc.middleName) AS NAME,
    bp.paymentMode,
    bp.permitDt,
    bp.businessAddress,
    bpi.issueNo,
    bp.permitStatus,
    bpi.endValidityDt,
    b.businessName AS businessName
FROM tblbusinesspermit bp
INNER JOIN tblbusinesspermitissuance bpi 
    ON bpi.permitId = bp.permitId
INNER JOIN tblconstituent tc 
    ON bp.applicantId = tc.constituentId
INNER JOIN tblbusiness b
    ON b.businessId = bp.businessId
WHERE bp.permitId = ?
";

$stmt = mysqli_prepare($CN, $query);

if (!$stmt) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database query preparation failed: ' . mysqli_error($CN)
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "s", $decryptedData);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($result)) {
    $response = [
        'status' => 'success',
        'data' => $row,
        // 'decryptionStatus' => 'success'
    ];
} else {
    $response = [
        'status' => 'error',
        'message' => 'No record found',
        'decryptionStatus' => 'success'
    ];
}

mysqli_stmt_close($stmt);
mysqli_close($CN);

echo json_encode($response);
exit;
?>
