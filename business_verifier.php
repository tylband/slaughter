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
    error_log("Error: Missing BP parameter");
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
// START SESSION (AFTER CONFIG)
// ==============================
session_start();
error_log("Business Verifier API called: " . date('Y-m-d H:i:s'));

// ==============================
// VALIDATE CN PARAMETER
// ==============================
if (!isset($_GET['CN'])) {
    error_log("Error: Missing CN parameter");
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing CN parameter'
    ]);
    exit;
}

// IMPORTANT: URL-DECODE CN
$encryptedData = urldecode($_GET['CN']);
error_log("Received CN (decoded): " . $encryptedData);

// ==============================
// SET DECRYPTION KEY (HARDCODED)
// ==============================
$key = "MISDreamTeam2024"; // <-- Hard-coded key from VB.NET

// ==============================
// DECRYPT FUNCTION (VB.NET AES-128-ECB)
// ==============================
function decryptData($encryptedData, $key) {
    $method = 'AES-128-ECB';
    $key = substr($key, 0, 16); // AES-128 requires 16 bytes

    return openssl_decrypt(
        base64_decode($encryptedData),
        $method,
        $key
    );
}

// ==============================
// DECRYPT CN VALUE
// ==============================
$decryptedData = decryptData($encryptedData, $key);

if ($decryptedData === false) {
    error_log("Error: Failed to decrypt CN");
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid QR code data'
    ]);
    exit;
}

$decryptedData = trim($decryptedData);
error_log("Decrypted CN value: " . $decryptedData);

// ==============================
// VALIDATE DATABASE CONNECTION
// ==============================
if (!isset($CN) || !$CN) {
    error_log("Database connection failed");
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
        CONCAT(tc.lastname, ', ', tc.firstname, ' ', tc.middlename) AS NAME,
        bp.paymentMode,
        bp.permitDt,
        bp.businessAddress,
        bpi.issueNo,
        bp.permitStatus
    FROM tblbusinesspermit bp
    INNER JOIN tblbusinesspermitissuance bpi 
        ON bpi.permitId = bp.permitId
    INNER JOIN tblconstituent tc 
        ON bp.applicantId = tc.constituentId
    WHERE bp.permitId = ?
";

error_log("Preparing SQL query");

$stmt = mysqli_prepare($CN, $query);

if (!$stmt) {
    error_log("SQL Prepare Error: " . mysqli_error($CN));
    echo json_encode([
        'status' => 'error',
        'message' => 'Database query preparation failed'
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
        'decryptionStatus' => 'Decryption successful'
    ];
} else {
    $response = [
        'status' => 'error',
        'message' => 'No record found',
        'decryptionStatus' => 'Decryption successful'
    ];
}

mysqli_stmt_close($stmt);
mysqli_close($CN);

// ==============================
// RETURN JSON RESPONSE
// ==============================
error_log("API response: " . json_encode($response));
echo json_encode($response);
exit;
?>
