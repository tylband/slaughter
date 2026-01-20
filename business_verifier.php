<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Check if BP is set in the query string
if (!isset($_GET['BP'])) {
    error_log("Error: Missing BP parameter");
    $response = [
        'status' => 'error',
        'message' => 'Missing BP parameter',
    ];
    echo json_encode($response);
    exit;
}

$bp = $_GET['BP'];

// Find the position of the hyphen in BP
$hyphenPos = strpos($bp, '-');

// Check if a hyphen exists and the substring after it starts with '8'
if ($hyphenPos !== false && substr($bp, $hyphenPos + 1, 1) === '8') {
    // If BP after the hyphen starts with 8, include the upper config
    require_once 'business_verifier_config_upper.php';
} else {
    // Otherwise, use the local config
    require_once 'business_verifier_config_local.php';
}

// Proceed with the rest of your code for database queries
session_start();
// Use error_log for server-side logging
error_log("API called: " . date('Y-m-d H:i:s'));

// // Retrieve the decryption key from the server environment
// $decryptionKey = $_SERVER['DECRYPTION_KEY'] ?? null;


// if (!$decryptionKey) {
//     error_log("Error: Decryption key not set in server environment");
//     echo json_encode(['status' => 'error', 'message' => 'Decryption key not configured']);
//     exit;
// }

// Check if the CN parameter is set
if (!isset($_GET['CN'])) {
    error_log("Error: Missing CN parameter");
    echo json_encode(['status' => 'error', 'message' => 'Missing CN parameter']);
    exit;
}

$encryptedData = $_GET['CN'];



error_log("Received CN parameter: " . $encryptedData);

// Function to decrypt data
function decryptData($encryptedData, $key) {
    $method = "AES-128-ECB"; // Match the VB.NET cipher mode and block size
    $key = substr($key, 0, 16); // Use the first 16 characters of the key, as in VB.NET

    // Decrypt the data
    return openssl_decrypt(base64_decode($encryptedData), $method, $key, OPENSSL_RAW_DATA);
}

// Decrypt the data
// Decrypt the data
$key = getenv('DECRYPTION_KEY');
$decryptedData = decryptData($encryptedData, $key);
// $decryptedData = decryptData($encryptedData, $decryptionKey);

// Log the decrypted data
if ($decryptedData === false) {
    error_log("Error: Failed to decrypt CN parameter");
    echo json_encode(['status' => 'error', 'message' => 'Invalid QR code data']);
    exit;
}

$decryptedData = trim($decryptedData); // Remove any extra spaces
error_log("Decrypted CN parameter: " . $decryptedData);

// Check database connection
if (!$CN) {
    error_log("Database connection failed: " . mysqli_connect_error());
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// Query the database using the decrypted data
$query = "SELECT 
            CONCAT(lastname, ', ', firstname, ' ', middlename, ' ') AS NAME,
            bp.paymentMode,
            bp.permitDt,
            bp.businessAddress,
            bpi.issueNo,
            bp.permitStatus 
          FROM tblbusinesspermit bp
          INNER JOIN tblbusinesspermitissuance bpi ON bpi.permitId = bp.permitId
          INNER JOIN tblconstituent tc ON bp.applicantId = tc.constituentId
          WHERE bp.permitId = ?";
error_log("Query to be executed: " . $query);

$stmt = mysqli_prepare($CN, $query);

if ($stmt) {
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
} else {
    error_log("Error: Failed to prepare statement - " . mysqli_error($CN));
    $response = ['status' => 'error', 'message' => 'Failed to prepare statement'];
}

mysqli_close($CN);

// Log the final API response
error_log("API response: " . json_encode($response));

// Return the response
echo json_encode($response);
?>
