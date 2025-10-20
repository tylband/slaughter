<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';
require_once 'token_auth.php';
// System logger is optional
if (file_exists('system_logger.php')) {
    require_once 'system_logger.php';
}


// Authenticate user with token-based authentication
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $user_auth = TokenAuth::authenticate($conn);
    if (!$user_auth) {
        // For development/testing purposes, allow without authentication
        // In production, you should remove this fallback
        if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development') {
            $user_auth = [
                'user_id' => 1,
                'username' => 'dev_user',
                'role' => 'admin'
            ];
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
    }
}

// Set error mode to exception for better error handling
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // List fees from slaughter_details with animal information, pagination, and search
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';

            // Debug logging
            error_log("API Fees GET - Page: $page, Limit: $limit, Search: '$search'");

            $offset = ($page - 1) * $limit;

            // Build query
            $whereClause = '';
            $params = [];

            // Always exclude deleted records (isdeleted != '1' and isdeleted IS NOT NULL)
            $deletedClause = "(s.isdeleted != '1' AND (s.isdeleted IS NULL OR s.isdeleted = '0'))";
            if (!empty($search)) {
                $whereClause = "WHERE ($deletedClause) AND (a.Animal LIKE ? OR s.Slaughter_Date LIKE ? OR CONCAT_WS(' ', c.Firstname, COALESCE(c.Middlename, ''), c.Surname) LIKE ?)";
                $params = ["%$search%", "%$search%", "%$search%"];
            } else {
                $whereClause = "WHERE $deletedClause";
            }

            // Get total count
            $countStmt = $conn->prepare("
                SELECT COUNT(*) as total
                FROM tbl_slaughter_details sd
                JOIN tbl_animals a ON sd.AID = a.AID
                JOIN tbl_slaughter s ON sd.SID = s.SID
                LEFT JOIN tbl_clients c ON s.CID = c.CID
                LEFT JOIN tbl_client_business cb ON s.BID = cb.BID
                $whereClause
            ");
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get slaughter operations grouped by SID with aggregated animal details
            $query = "
                SELECT s.SID, s.Slaughter_Date, s.Slaughter_Date as Date,
                       s.CID, s.BID, s.MID, s.isAddOn, s.payment_status,
                       CONCAT_WS(' ', c.Firstname, COALESCE(c.Middlename, ''), c.Surname) as client_name,
                       cb.Business_Name,
                       DATE_FORMAT(s.Slaughter_Date, '%Y-%m-%d %H:%i') as slaughter_date_formatted,
                       GROUP_CONCAT(DISTINCT a.Animal ORDER BY a.Animal SEPARATOR ', ') as animals,
                       SUM(sd.No_of_Heads) as total_heads,
                       SUM(sd.No_of_Kilos) as total_kilos,
                       SUM(sd.Slaughter_Fee) as total_slaughter_fee,
                       SUM(sd.Corral_Fee) as total_corral_fee,
                       SUM(sd.Ante_Mortem_Fee) as total_ante_mortem_fee,
                       SUM(sd.Post_Mortem_Fee) as total_post_mortem_fee,
                       SUM(sd.Delivery_Fee) as total_delivery_fee,
                       COUNT(sd.Detail_ID) as animal_count
                FROM tbl_slaughter s
                LEFT JOIN tbl_slaughter_details sd ON s.SID = sd.SID
                LEFT JOIN tbl_animals a ON sd.AID = a.AID
                LEFT JOIN tbl_clients c ON s.CID = c.CID
                LEFT JOIN tbl_client_business cb ON s.BID = cb.BID
                $whereClause
                GROUP BY s.SID, s.Slaughter_Date, s.CID, s.BID, s.MID, s.isAddOn, s.payment_status, c.Firstname, c.Middlename, c.Surname, cb.Business_Name
                ORDER BY s.Slaughter_Date DESC
                LIMIT $limit OFFSET $offset
            ";
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $fees = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format the data for fee management display
            foreach ($fees as &$fee) {
                // Calculate total fee amount
                $totalAmount = (float)$fee['total_slaughter_fee'] + (float)$fee['total_corral_fee'] +
                              (float)$fee['total_ante_mortem_fee'] + (float)$fee['total_post_mortem_fee'] +
                              (float)$fee['total_delivery_fee'];

                $fee['Fee_ID'] = $fee['SID']; // Use SID as Fee_ID for operations
                $fee['Fee_Name'] = 'Slaughter Operation Fee'; // Generic name
                $fee['Amount'] = number_format($totalAmount, 2);
                $fee['Date'] = date('Y-m-d', strtotime($fee['Slaughter_Date']));

                // Add usage stats
                $fee['total_usage'] = 1; // Each record represents one operation
                $fee['total_heads'] = (int)$fee['total_heads'];
                $fee['total_kilos'] = number_format((float)$fee['total_kilos'], 2);
                $fee['last_used'] = date('Y-m-d', strtotime($fee['Slaughter_Date']));

                // Add individual fee components for compatibility
                $fee['Slaughter_Fee'] = $fee['total_slaughter_fee'];
                $fee['Corral_Fee'] = $fee['total_corral_fee'];
                $fee['Ante_Mortem_Fee'] = $fee['total_ante_mortem_fee'];
                $fee['Post_Mortem_Fee'] = $fee['total_post_mortem_fee'];
                $fee['Delivery_Fee'] = $fee['total_delivery_fee'];
                $fee['No_of_Heads'] = $fee['total_heads'];
                $fee['No_of_Kilos'] = $fee['total_kilos'];
                $fee['Animal'] = $fee['animals'];
                $fee['Add_On_Flag'] = $fee['isAddOn'];
            }

            echo json_encode([
                'success' => true,
                'data' => $fees,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;

        case 'POST':
            // Create new slaughter operation with multiple details (fee recording)
            $input = file_get_contents('php://input');

            // Debug logging
            error_log('API Fees POST Raw Input: ' . $input);
            error_log('API Fees POST Input bytes: ' . strlen($input));
            error_log('API Fees POST Input MD5: ' . md5($input));
            error_log('API Fees POST Input hex: ' . bin2hex(substr($input, 0, 50)));

            // Try different JSON parsing approaches
            $data = json_decode($input, true);

            // Check for the specific edge case where json_decode returns false but no error
            if ($data === null && json_last_error() === JSON_ERROR_NONE) {
                error_log('json_decode returned null but no error - possible BOM or encoding issue');
                // Try removing BOM if present
                $bom = pack('H*','EFBBBF');
                $clean_input = preg_replace("/^$bom/", '', $input);
                $data = json_decode($clean_input, true);
                if ($data !== null) {
                    error_log('BOM removal fixed the issue');
                    $input = $clean_input;
                } else {
                    error_log('Trying with json_decode options');
                    $data = json_decode($input, true, 512, JSON_INVALID_UTF8_IGNORE);
                }
            }

            // If still no data, try a more aggressive approach
            if (!$data) {
                error_log('Aggressive JSON parsing attempt...');
                // Try to fix common issues
                $fixed_input = str_replace(['\n', '\r', '\t'], '', $input);
                $data = json_decode($fixed_input, true);
                if ($data) {
                    error_log('Whitespace removal fixed the issue');
                } else {
                    // Last resort - try to manually validate and fix JSON
                    error_log('Last resort: manual JSON validation');
                    $json_start = strpos($input, '{');
                    $json_end = strrpos($input, '}');
                    if ($json_start !== false && $json_end !== false && $json_end > $json_start) {
                        $json_subset = substr($input, $json_start, $json_end - $json_start + 1);
                        error_log('Extracted JSON subset: ' . $json_subset);
                        $data = json_decode($json_subset, true);
                        if ($data) {
                            error_log('JSON subset parsing succeeded');
                        }
                    }
                }
            }

            // Final check - if still no data, try a simpler test
            if (!$data) {
                error_log('Final attempt: testing JSON validity');
                $test_json = '{"test": "value"}';
                $test_result = json_decode($test_json, true);
                error_log('Test JSON decode result: ' . ($test_result ? 'success' : 'failed'));
                error_log('Test JSON error: ' . json_last_error_msg());

                // Try to understand what's wrong with our input
                error_log('Checking input format...');
                error_log('Input starts with: ' . substr($input, 0, 1));
                error_log('Input ends with: ' . substr($input, -1));
                error_log('Input contains CID: ' . (strpos($input, 'CID') !== false ? 'yes' : 'no'));
                error_log('Input contains details: ' . (strpos($input, 'details') !== false ? 'yes' : 'no'));

                // Try to manually parse the JSON structure
                error_log('Attempting to manually parse JSON structure...');
                $input_copy = $input;
                // Remove any potential issues
                $input_copy = str_replace(['\x00', '\x01', '\x02'], '', $input_copy);
                $data = json_decode($input_copy, true);
                if ($data) {
                    error_log('Manual cleanup fixed the issue');
                }
            }

            // Debug logging
            error_log('API Fees POST Decoded Data: ' . json_encode($data));
            error_log('API Fees POST Decoded Data Type: ' . gettype($data));
            if ($data) {
                error_log('API Fees POST Decoded Data Keys: ' . implode(', ', array_keys($data)));
            } else {
                error_log('API Fees POST Decoded Data is null/empty');
                error_log('JSON last error: ' . json_last_error_msg() . ' (Code: ' . json_last_error() . ')');
                error_log('PHP version: ' . phpversion());
                error_log('JSON extension loaded: ' . (extension_loaded('json') ? 'yes' : 'no'));
            }

            if (!$data) {
                $error_msg = json_last_error_msg();
                $error_code = json_last_error();
                error_log('JSON decode error: ' . $error_msg . ' (Code: ' . $error_code . ')');
                error_log('Raw input length: ' . strlen($input));
                error_log('Raw input (first 200 chars): ' . substr($input, 0, 200));
                error_log('Raw input (as JSON): ' . json_encode($input));

                // Try to fix common JSON issues
                $cleaned_input = trim($input);
                if (substr($cleaned_input, 0, 1) !== '{' || substr($cleaned_input, -1) !== '}') {
                    error_log('Input does not start with { or end with }');
                }

                // Try manual parsing for debugging
                error_log('Attempting manual JSON validation...');
                $test_data = @json_decode($input, true);
                if ($test_data) {
                    error_log('Manual parsing succeeded, data keys: ' . implode(', ', array_keys($test_data)));
                } else {
                    error_log('Manual parsing also failed');
                }

                throw new Exception('Invalid JSON data: ' . $error_msg . ' (Code: ' . $error_code . ')');
            }

            // Validate data structure
            if (!isset($data['CID'])) {
                throw new Exception('Missing required field: CID');
            }
            if (!isset($data['Slaughter_Date'])) {
                throw new Exception('Missing required field: Slaughter_Date');
            }
            if (!isset($data['details']) || !is_array($data['details'])) {
                throw new Exception('Missing or invalid field: details (must be an array)');
            }

            // Validate required fields
            if (!isset($data['CID']) || !is_numeric($data['CID'])) {
                throw new Exception('Valid client ID is required');
            }
            if (!isset($data['details']) || !is_array($data['details']) || empty($data['details'])) {
                throw new Exception('At least one animal detail is required');
            }
            if (!isset($data['Slaughter_Date']) || trim($data['Slaughter_Date']) === '') {
                throw new Exception('Slaughter date is required');
            }

            // Validate and format date without timezone conversion
            try {
                $dateTime = new DateTime($data['Slaughter_Date']);
                $slaughterDate = $dateTime->format('Y-m-d H:i:s');
                error_log('Formatted date: ' . $slaughterDate);
            } catch (Exception $e) {
                error_log('Date parsing error: ' . $e->getMessage());
                throw new Exception('Invalid date format: ' . $e->getMessage());
            }

            // Check if client exists
            error_log('Checking if client exists: ' . $data['CID']);
            $clientCheck = $conn->prepare("SELECT CID FROM tbl_clients WHERE CID = ?");
            $clientCheck->execute([(int)$data['CID']]);
            $client = $clientCheck->fetch();
            if (!$client) {
                error_log('Client not found: ' . $data['CID']);
                throw new Exception('Selected client does not exist');
            }
            error_log('Client found: ' . $client['CID']);

            // Validate each detail
            foreach ($data['details'] as $detail) {
                if (!isset($detail['AID']) || !is_numeric($detail['AID'])) {
                    throw new Exception('Valid animal ID is required for each detail');
                }
                if (!isset($detail['No_of_Heads']) || !is_numeric($detail['No_of_Heads']) || $detail['No_of_Heads'] < 0) {
                    throw new Exception('Number of heads must be a valid non-negative number');
                }
                if (!isset($detail['No_of_Kilos']) || !is_numeric($detail['No_of_Kilos']) || $detail['No_of_Kilos'] < 0) {
                    throw new Exception('Weight in kilos must be a valid non-negative number');
                }

                // Validate that at least one of heads or kilos is greater than 0
                if ($detail['No_of_Heads'] <= 0 && $detail['No_of_Kilos'] <= 0) {
                    throw new Exception('Either number of heads or weight in kilos must be greater than 0');
                }

                // Check if animal exists
                $animalCheck = $conn->prepare("SELECT AID FROM tbl_animals WHERE AID = ?");
                $animalCheck->execute([(int)$detail['AID']]);
                if (!$animalCheck->fetch()) {
                    throw new Exception('Selected animal type does not exist');
                }
            }

            // Add-on flag removed from form, default to 0
            $isAddOn = 0;

            // Handle custom code marking (MID) - create if it doesn't exist
            $originalMidValue = isset($data['MID']) ? trim($data['MID']) : '';
            error_log("POST DEBUG: Original MID value from client: '{$originalMidValue}'");

            if (!empty($originalMidValue)) {
                try {
                    // Check if code marking exists
                    $codeCheck = $conn->prepare("SELECT MID FROM tbl_codemarkings WHERE CODE = ?");
                    $codeCheck->execute([$originalMidValue]);
                    $existingCode = $codeCheck->fetch(PDO::FETCH_ASSOC);

                    if (!$existingCode) {
                        // Create new code marking
                        error_log("POST DEBUG: Code '{$originalMidValue}' not found, creating new record");
                        $insertCode = $conn->prepare("INSERT INTO tbl_codemarkings (CODE) VALUES (?)");
                        $insertCode->execute([$originalMidValue]);
                        $newMid = $conn->lastInsertId();

                        error_log("POST DEBUG: Inserted code '{$originalMidValue}', lastInsertId returned: {$newMid}");

                        if ($newMid && $newMid > 0) {
                            $data['MID'] = $newMid;
                            error_log("POST SUCCESS: Created new code marking ID {$newMid} for code '{$originalMidValue}'");
                        } else {
                            error_log("POST ERROR: lastInsertId returned {$newMid} or 0, setting MID to null");
                            $data['MID'] = null;
                        }
                    } else {
                        $data['MID'] = $existingCode['MID'];
                        error_log("POST SUCCESS: Found existing code marking ID {$data['MID']} for code '{$originalMidValue}'");
                    }
                } catch (Exception $e) {
                    error_log("POST ERROR: Exception while handling code marking '{$originalMidValue}': " . $e->getMessage() . " - Setting MID to null");
                    $data['MID'] = null;
                }
            } else {
                $data['MID'] = null;
                error_log("POST INFO: MID is empty, setting to null");
            }

            error_log("POST FINAL: MID value to be inserted: " . ($data['MID'] ?? 'null') . " for original code: '{$originalMidValue}'");

            // Begin transaction
            $conn->beginTransaction();

            try {
                // Create slaughter operation record
                $slaughterStmt = $conn->prepare("
                    INSERT INTO tbl_slaughter (CID, BID, MID, Slaughter_Date, isAddOn, payment_status, Added_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $slaughterStmt->execute([
                    (int)$data['CID'], // Selected client
                    isset($data['BID']) ? (int)$data['BID'] : null,
                    isset($data['MID']) && !empty($data['MID']) ? (int)$data['MID'] : null,
                    $slaughterDate,
                    $isAddOn ? 1 : 0,
                    'unpaid', // Default payment status
                    isset($data['Added_by']) ? (int)$data['Added_by'] : 1 // User who added the record
                ]);

                $newSlaughterId = $conn->lastInsertId();

                // Create slaughter details records for each animal
                $detailsStmt = $conn->prepare("
                    INSERT INTO tbl_slaughter_details (SID, AID, No_of_Heads, No_of_Kilos,
                                                      Slaughter_Fee, Corral_Fee, Ante_Mortem_Fee,
                                                      Post_Mortem_Fee, Delivery_Fee, Add_On_Flag, code_mark)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $successfulInserts = 0;
                $firstDetailId = null;
                foreach ($data['details'] as $detail) {
                    try {
                        $detailsStmt->execute([
                            $newSlaughterId,
                            (int)$detail['AID'],
                            (int)$detail['No_of_Heads'],
                            (float)$detail['No_of_Kilos'],
                            isset($detail['Slaughter_Fee']) ? (float)$detail['Slaughter_Fee'] : 0.00,
                            isset($detail['Corral_Fee']) ? (float)$detail['Corral_Fee'] : 0.00,
                            isset($detail['Ante_Mortem_Fee']) ? (float)$detail['Ante_Mortem_Fee'] : 0.00,
                            isset($detail['Post_Mortem_Fee']) ? (float)$detail['Post_Mortem_Fee'] : 0.00,
                            isset($detail['Delivery_Fee']) ? (float)$detail['Delivery_Fee'] : 0.00,
                            $isAddOn ? 1 : 0,
                            isset($detail['code_mark']) && !empty($detail['code_mark']) ? trim($detail['code_mark']) : null
                        ]);
                        $successfulInserts++;
                        if ($firstDetailId === null) {
                            $firstDetailId = $conn->lastInsertId();
                        }
                    } catch (Exception $e) {
                        error_log("Failed to insert detail for AID " . $detail['AID'] . ": " . $e->getMessage());
                        // Continue with other details
                    }
                }

                if ($successfulInserts == 0) {
                    throw new Exception('No operations were saved');
                }

                if ($successfulInserts < count($data['details'])) {
                    throw new Exception("Only $successfulInserts out of " . count($data['details']) . " operations were saved");
                }

                $conn->commit();

                // Log the fee entry creation
                if (class_exists('SystemLogger')) {
                    $logger = new SystemLogger($conn, $user_auth['user_id'], $user_auth['username']);
                    $logger->logFeeEntryCreated($newSlaughterId, $feeData ?? []);
                }

                // Calculate total amount for logging
                $totalAmount = 0;
                foreach ($data['details'] as $detail) {
                    $totalAmount += (isset($detail['Slaughter_Fee']) ? (float)$detail['Slaughter_Fee'] : 0) +
                                   (isset($detail['Corral_Fee']) ? (float)$detail['Corral_Fee'] : 0) +
                                   (isset($detail['Ante_Mortem_Fee']) ? (float)$detail['Ante_Mortem_Fee'] : 0) +
                                   (isset($detail['Post_Mortem_Fee']) ? (float)$detail['Post_Mortem_Fee'] : 0) +
                                   (isset($detail['Delivery_Fee']) ? (float)$detail['Delivery_Fee'] : 0);
                }

                // Get client name for logging
                $clientStmt = $conn->prepare("SELECT CONCAT_WS(' ', Firstname, COALESCE(Middlename, ''), Surname) as client_name FROM tbl_clients WHERE CID = ?");
                $clientStmt->execute([(int)$data['CID']]);
                $clientInfo = $clientStmt->fetch(PDO::FETCH_ASSOC);
                $clientName = $clientInfo ? $clientInfo['client_name'] : 'Unknown Client';

                // Prepare fee data for logging
                $feeData = [
                    'total_amount' => number_format($totalAmount, 2),
                    'client_name' => $clientName,
                    'slaughter_date' => $slaughterDate,
                    'details_count' => count($data['details']),
                    'total_heads' => array_sum(array_column($data['details'], 'No_of_Heads')),
                    'total_kilos' => number_format(array_sum(array_column($data['details'], 'No_of_Kilos')), 2)
                ];

                // Log the fee entry creation
                $logger->logFeeEntryCreated($newSlaughterId, $feeData);

                echo json_encode([
                    'success' => true,
                    'message' => 'Slaughter operation recorded successfully',
                    'data' => ['Fee_ID' => $firstDetailId, 'SID' => $newSlaughterId]
                ]);

            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;

        case 'PUT':
            // Handle soft delete action
            if (isset($_GET['action']) && $_GET['action'] === 'soft_delete') {
                $sid = isset($_GET['sid']) ? (int)$_GET['sid'] : null;
                if (!$sid) {
                    throw new Exception('Operation ID is required');
                }

                // Check if operation exists
                $checkStmt = $conn->prepare("SELECT SID FROM tbl_slaughter WHERE SID = ?");
                $checkStmt->execute([$sid]);
                $operation = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$operation) {
                    throw new Exception('Slaughter operation not found');
                }

                // Soft delete the operation by setting isdeleted = '1'
                $updateStmt = $conn->prepare("UPDATE tbl_slaughter SET isdeleted = '1' WHERE SID = ?");
                $updateStmt->execute([$sid]);

                // Log the soft delete action
                if (class_exists('SystemLogger')) {
                    $logger = new SystemLogger($conn, $user_auth['user_id'], $user_auth['username']);
                    $logger->logSlaughterDeleted($sid, 'Soft delete performed');
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Fee entry marked as deleted successfully'
                ]);
                break;
            }

            // Handle payment status toggle
            if (isset($_GET['action']) && $_GET['action'] === 'toggle_payment') {
                $sid = isset($_GET['sid']) ? (int)$_GET['sid'] : null;
                if (!$sid) {
                    throw new Exception('Operation ID is required');
                }

                // Check if operation exists
                $checkStmt = $conn->prepare("SELECT payment_status FROM tbl_slaughter WHERE SID = ?");
                $checkStmt->execute([$sid]);
                $currentStatus = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$currentStatus) {
                    throw new Exception('Slaughter operation not found');
                }

                // Toggle status: unpaid -> paid, paid -> unpaid, partial stays partial for now
                $newStatus = $currentStatus['payment_status'] === 'paid' ? 'unpaid' : 'paid';

                $updateStmt = $conn->prepare("UPDATE tbl_slaughter SET payment_status = ? WHERE SID = ?");
                $updateStmt->execute([$newStatus, $sid]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Payment status updated successfully',
                    'new_status' => $newStatus
                ]);
                break;
            }

            // Update slaughter operation with multiple details
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            // Check for the specific edge case where json_decode returns false but no error
            if ($data === null && json_last_error() === JSON_ERROR_NONE) {
                error_log('PUT json_decode returned null but no error - possible BOM or encoding issue');
                // Try removing BOM if present
                $bom = pack('H*','EFBBBF');
                $clean_input = preg_replace("/^$bom/", '', $input);
                $data = json_decode($clean_input, true);
                if ($data !== null) {
                    error_log('PUT BOM removal fixed the issue');
                } else {
                    $data = json_decode($input, true, 512, JSON_INVALID_UTF8_IGNORE);
                }
            }

            if (!$data) {
                throw new Exception('Invalid JSON data');
            }

            // Check if this is updating a single detail or the whole operation
            if (isset($data['Fee_ID']) && !isset($data['details'])) {
                // Legacy single detail update
                $detailId = (int)$data['Fee_ID'];

                // Check if detail exists and get old data for logging
                $oldDetailStmt = $conn->prepare("
                    SELECT sd.*, a.Animal, s.SID, s.CID,
                           CONCAT_WS(' ', c.Firstname, COALESCE(c.Middlename, ''), c.Surname) as client_name,
                           cb.Business_Name, s.Slaughter_Date as slaughter_date_formatted
                    FROM tbl_slaughter_details sd
                    JOIN tbl_animals a ON sd.AID = a.AID
                    JOIN tbl_slaughter s ON sd.SID = s.SID
                    LEFT JOIN tbl_clients c ON s.CID = c.CID
                    LEFT JOIN tbl_client_business cb ON s.BID = cb.BID
                    WHERE sd.Detail_ID = ?
                ");
                $oldDetailStmt->execute([$detailId]);
                $oldDetailData = $oldDetailStmt->fetch(PDO::FETCH_ASSOC);

                if (!$oldDetailData) {
                    throw new Exception('Slaughter detail not found');
                }

                // Validate required fields
                if (!isset($data['AID']) || !is_numeric($data['AID'])) {
                    throw new Exception('Valid animal ID is required');
                }
                if (!isset($data['No_of_Heads']) || !is_numeric($data['No_of_Heads']) || $data['No_of_Heads'] < 0) {
                    throw new Exception('Number of heads must be a valid non-negative number');
                }
                if (!isset($data['No_of_Kilos']) || !is_numeric($data['No_of_Kilos']) || $data['No_of_Kilos'] < 0) {
                    throw new Exception('Weight in kilos must be a valid non-negative number');
                }

                // Validate that at least one of heads or kilos is greater than 0
                if ($data['No_of_Heads'] <= 0 && $data['No_of_Kilos'] <= 0) {
                    throw new Exception('Either number of heads or weight in kilos must be greater than 0');
                }

                // Check if animal exists
                $animalCheck = $conn->prepare("SELECT AID FROM tbl_animals WHERE AID = ?");
                $animalCheck->execute([(int)$data['AID']]);
                if (!$animalCheck->fetch()) {
                    throw new Exception('Selected animal type does not exist');
                }

                // Update single detail
                $stmt = $conn->prepare("
                    UPDATE tbl_slaughter_details
                    SET AID = ?, No_of_Heads = ?, No_of_Kilos = ?,
                        Slaughter_Fee = ?, Corral_Fee = ?, Ante_Mortem_Fee = ?,
                        Post_Mortem_Fee = ?, Delivery_Fee = ?, Add_On_Flag = ?, code_mark = ?
                    WHERE Detail_ID = ?
                ");

                // Get the current slaughter operation to find the MID
                $getSlaughterStmt = $conn->prepare("SELECT MID FROM tbl_slaughter WHERE SID = (SELECT SID FROM tbl_slaughter_details WHERE Detail_ID = ?)");
                $getSlaughterStmt->execute([$detailId]);
                $slaughterData = $getSlaughterStmt->fetch(PDO::FETCH_ASSOC);

                $stmt->execute([
                    (int)$data['AID'],
                    (int)$data['No_of_Heads'],
                    (float)$data['No_of_Kilos'],
                    isset($data['Slaughter_Fee']) ? (float)$data['Slaughter_Fee'] : 0.00,
                    isset($data['Corral_Fee']) ? (float)$data['Corral_Fee'] : 0.00,
                    isset($data['Ante_Mortem_Fee']) ? (float)$data['Ante_Mortem_Fee'] : 0.00,
                    isset($data['Post_Mortem_Fee']) ? (float)$data['Post_Mortem_Fee'] : 0.00,
                    isset($data['Delivery_Fee']) ? (float)$data['Delivery_Fee'] : 0.00,
                    isset($data['Add_On_Flag']) ? (int)$data['Add_On_Flag'] : 0,
                    isset($data['code_mark']) && !empty($data['code_mark']) ? trim($data['code_mark']) : (isset($slaughterData['MID']) ? $slaughterData['MID'] : null),
                    $detailId
                ]);

                // Log the fee entry update for single detail
                if (class_exists('SystemLogger')) {
                    $logger = new SystemLogger($conn, $user_auth['user_id'], $user_auth['username']);
                    $logger->logFeeEntryUpdated($oldDetailData['SID'], $oldFeeData ?? [], $newFeeData ?? []);
                }

                // Calculate old and new totals
                $oldTotal = (float)$oldDetailData['Slaughter_Fee'] + (float)$oldDetailData['Corral_Fee'] +
                          (float)$oldDetailData['Ante_Mortem_Fee'] + (float)$oldDetailData['Post_Mortem_Fee'] +
                          (float)$oldDetailData['Delivery_Fee'];

                $newTotal = (isset($data['Slaughter_Fee']) ? (float)$data['Slaughter_Fee'] : 0) +
                          (isset($data['Corral_Fee']) ? (float)$data['Corral_Fee'] : 0) +
                          (isset($data['Ante_Mortem_Fee']) ? (float)$data['Ante_Mortem_Fee'] : 0) +
                          (isset($data['Post_Mortem_Fee']) ? (float)$data['Post_Mortem_Fee'] : 0) +
                          (isset($data['Delivery_Fee']) ? (float)$data['Delivery_Fee'] : 0);

                // Prepare old data for logging
                $oldFeeData = [
                    'total_amount' => number_format($oldTotal, 2),
                    'client_name' => $oldDetailData['client_name'] ?: 'Unknown Client',
                    'business_name' => $oldDetailData['Business_Name'] ?: null,
                    'slaughter_date' => $oldDetailData['slaughter_date_formatted'],
                    'animal' => $oldDetailData['Animal'],
                    'heads' => $oldDetailData['No_of_Heads'],
                    'kilos' => number_format($oldDetailData['No_of_Kilos'], 2),
                    'slaughter_fee' => number_format($oldDetailData['Slaughter_Fee'], 2),
                    'corral_fee' => number_format($oldDetailData['Corral_Fee'], 2),
                    'ante_mortem_fee' => number_format($oldDetailData['Ante_Mortem_Fee'], 2),
                    'post_mortem_fee' => number_format($oldDetailData['Post_Mortem_Fee'], 2),
                    'delivery_fee' => number_format($oldDetailData['Delivery_Fee'], 2)
                ];

                // Prepare new data for logging
                $newFeeData = [
                    'total_amount' => number_format($newTotal, 2),
                    'client_name' => $oldDetailData['client_name'] ?: 'Unknown Client',
                    'business_name' => $oldDetailData['Business_Name'] ?: null,
                    'slaughter_date' => $oldDetailData['slaughter_date_formatted'],
                    'animal' => isset($data['AID']) ? 'Updated Animal' : $oldDetailData['Animal'], // We don't have the new animal name here
                    'heads' => isset($data['No_of_Heads']) ? $data['No_of_Heads'] : $oldDetailData['No_of_Heads'],
                    'kilos' => isset($data['No_of_Kilos']) ? number_format($data['No_of_Kilos'], 2) : number_format($oldDetailData['No_of_Kilos'], 2),
                    'slaughter_fee' => isset($data['Slaughter_Fee']) ? number_format($data['Slaughter_Fee'], 2) : number_format($oldDetailData['Slaughter_Fee'], 2),
                    'corral_fee' => isset($data['Corral_Fee']) ? number_format($data['Corral_Fee'], 2) : number_format($oldDetailData['Corral_Fee'], 2),
                    'ante_mortem_fee' => isset($data['Ante_Mortem_Fee']) ? number_format($data['Ante_Mortem_Fee'], 2) : number_format($oldDetailData['Ante_Mortem_Fee'], 2),
                    'post_mortem_fee' => isset($data['Post_Mortem_Fee']) ? number_format($data['Post_Mortem_Fee'], 2) : number_format($oldDetailData['Post_Mortem_Fee'], 2),
                    'delivery_fee' => isset($data['Delivery_Fee']) ? number_format($data['Delivery_Fee'], 2) : number_format($oldDetailData['Delivery_Fee'], 2)
                ];

                // Log the fee entry update
                $logger->logFeeEntryUpdated($oldDetailData['SID'], $oldFeeData, $newFeeData);

                echo json_encode([
                    'success' => true,
                    'message' => 'Slaughter detail updated successfully'
                ]);
            } elseif (isset($data['details']) && is_array($data['details'])) {
                // Update whole operation with multiple details
                if (!isset($data['SID']) || !is_numeric($data['SID'])) {
                    throw new Exception('Valid operation SID is required for multi-detail update');
                }

                $sid = (int)$data['SID'];

                // Check if operation exists
                $checkStmt = $conn->prepare("SELECT SID FROM tbl_slaughter WHERE SID = ?");
                $checkStmt->execute([$sid]);
                if (!$checkStmt->fetch()) {
                    throw new Exception('Slaughter operation not found');
                }

                // Get old data for logging before making changes
                $oldDataStmt = $conn->prepare("
                    SELECT s.*, CONCAT_WS(' ', c.Firstname, COALESCE(c.Middlename, ''), c.Surname) as client_name,
                           cb.Business_Name, s.Slaughter_Date as slaughter_date_formatted
                    FROM tbl_slaughter s
                    LEFT JOIN tbl_clients c ON s.CID = c.CID
                    LEFT JOIN tbl_client_business cb ON s.BID = cb.BID
                    WHERE s.SID = ?
                ");
                $oldDataStmt->execute([$sid]);
                $oldSlaughterData = $oldDataStmt->fetch(PDO::FETCH_ASSOC);

                // Get old details for logging
                $oldDetailsStmt = $conn->prepare("
                    SELECT sd.*, a.Animal
                    FROM tbl_slaughter_details sd
                    JOIN tbl_animals a ON sd.AID = a.AID
                    WHERE sd.SID = ?
                ");
                $oldDetailsStmt->execute([$sid]);
                $oldDetails = $oldDetailsStmt->fetchAll(PDO::FETCH_ASSOC);

                // Validate each detail
                foreach ($data['details'] as $detail) {
                    if (!isset($detail['AID']) || !is_numeric($detail['AID'])) {
                        throw new Exception('Valid animal ID is required for each detail');
                    }
                    if (!isset($detail['No_of_Heads']) || !is_numeric($detail['No_of_Heads']) || $detail['No_of_Heads'] < 0) {
                        throw new Exception('Number of heads must be a valid non-negative number');
                    }
                    if (!isset($detail['No_of_Kilos']) || !is_numeric($detail['No_of_Kilos']) || $detail['No_of_Kilos'] < 0) {
                        throw new Exception('Weight in kilos must be a valid non-negative number');
                    }
   
                    // Validate that at least one of heads or kilos is greater than 0
                    if ($detail['No_of_Heads'] <= 0 && $detail['No_of_Kilos'] <= 0) {
                        throw new Exception('Either number of heads or weight in kilos must be greater than 0');
                    }

                    // Check if animal exists
                    error_log('Checking if animal exists: ' . $detail['AID']);
                    $animalCheck = $conn->prepare("SELECT AID FROM tbl_animals WHERE AID = ?");
                    $animalCheck->execute([(int)$detail['AID']]);
                    $animal = $animalCheck->fetch();
                    if (!$animal) {
                        error_log('Animal not found: ' . $detail['AID']);
                        throw new Exception('Selected animal type does not exist');
                    }
                    error_log('Animal found: ' . $animal['AID']);
                }

                // Handle custom code marking (MID) - create if it doesn't exist
                $originalMidValue = isset($data['MID']) ? trim($data['MID']) : '';
                error_log("DEBUG: Original MID value from client: '{$originalMidValue}'");

                if (!empty($originalMidValue)) {
                    try {
                        // Check if code marking exists
                        $codeCheck = $conn->prepare("SELECT MID FROM tbl_codemarkings WHERE CODE = ?");
                        $codeCheck->execute([$originalMidValue]);
                        $existingCode = $codeCheck->fetch(PDO::FETCH_ASSOC);

                        if (!$existingCode) {
                            // Create new code marking
                            error_log("DEBUG: Code '{$originalMidValue}' not found, creating new record");
                            $insertCode = $conn->prepare("INSERT INTO tbl_codemarkings (CODE) VALUES (?)");
                            $insertCode->execute([$originalMidValue]);
                            $newMid = $conn->lastInsertId();

                            error_log("DEBUG: Inserted code '{$originalMidValue}', lastInsertId returned: {$newMid}");

                            if ($newMid && $newMid > 0) {
                                $data['MID'] = $newMid;
                                error_log("SUCCESS: Created new code marking ID {$newMid} for code '{$originalMidValue}'");
                            } else {
                                error_log("ERROR: lastInsertId returned {$newMid} or 0, setting MID to null");
                                $data['MID'] = null;
                            }
                        } else {
                            $data['MID'] = $existingCode['MID'];
                            error_log("SUCCESS: Found existing code marking ID {$data['MID']} for code '{$originalMidValue}'");
                        }
                    } catch (Exception $e) {
                        error_log("ERROR: Exception while handling code marking '{$originalMidValue}': " . $e->getMessage() . " - Setting MID to null");
                        $data['MID'] = null;
                    }
                } else {
                    $data['MID'] = null;
                    error_log("INFO: MID is empty, setting to null");
                }

                error_log("FINAL: MID value to be inserted: " . ($data['MID'] ?? 'null') . " for original code: '{$originalMidValue}'");

                // Begin transaction
                $conn->beginTransaction();

                try {
                    // Update slaughter operation basic info
                    $slaughterUpdate = $conn->prepare("
                        UPDATE tbl_slaughter
                        SET CID = ?, BID = ?, MID = ?, Slaughter_Date = ?, isAddOn = ?, payment_status = ?
                        WHERE SID = ?
                    ");
                    $slaughterUpdate->execute([
                        (int)$data['CID'],
                        isset($data['BID']) ? (int)$data['BID'] : null,
                        isset($data['MID']) && !empty($data['MID']) ? (int)$data['MID'] : null,
                        $data['Slaughter_Date'] ? date('Y-m-d H:i:s', strtotime($data['Slaughter_Date'])) : $oldSlaughterData['Slaughter_Date'],
                        0, // Add-on flag removed
                        isset($data['payment_status']) ? $data['payment_status'] : 'unpaid',
                        $sid
                    ]);

                    // Delete existing details
                    $deleteStmt = $conn->prepare("DELETE FROM tbl_slaughter_details WHERE SID = ?");
                    $deleteStmt->execute([$sid]);

                    // Insert new details
                    $detailsStmt = $conn->prepare("
                        INSERT INTO tbl_slaughter_details (SID, AID, No_of_Heads, No_of_Kilos,
                                                          Slaughter_Fee, Corral_Fee, Ante_Mortem_Fee,
                                                          Post_Mortem_Fee, Delivery_Fee, Add_On_Flag, code_mark)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($data['details'] as $detail) {
                        $detailsStmt->execute([
                            $sid,
                            (int)$detail['AID'],
                            (int)$detail['No_of_Heads'],
                            (float)$detail['No_of_Kilos'],
                            isset($detail['Slaughter_Fee']) ? (float)$detail['Slaughter_Fee'] : 0.00,
                            isset($detail['Corral_Fee']) ? (float)$detail['Corral_Fee'] : 0.00,
                            isset($detail['Ante_Mortem_Fee']) ? (float)$detail['Ante_Mortem_Fee'] : 0.00,
                            isset($detail['Post_Mortem_Fee']) ? (float)$detail['Post_Mortem_Fee'] : 0.00,
                            isset($detail['Delivery_Fee']) ? (float)$detail['Delivery_Fee'] : 0.00,
                            isset($data['Add_On_Flag']) && (int)$data['Add_On_Flag'] === 1 ? 1 : 0,
                            isset($detail['code_mark']) && !empty($detail['code_mark']) ? trim($detail['code_mark']) : null
                        ]);
                    }

                    $conn->commit();

                    // Log the fee entry update
                    if (class_exists('SystemLogger')) {
                        $logger = new SystemLogger($conn, $user_auth['user_id'], $user_auth['username']);
                        $logger->logFeeEntryUpdated($sid, $oldFeeData ?? [], $newFeeData ?? []);
                    }

                    // Calculate totals for old and new data
                    $oldTotalAmount = 0;
                    foreach ($oldDetails as $detail) {
                        $oldTotalAmount += (float)$detail['Slaughter_Fee'] + (float)$detail['Corral_Fee'] +
                                         (float)$detail['Ante_Mortem_Fee'] + (float)$detail['Post_Mortem_Fee'] +
                                         (float)$detail['Delivery_Fee'];
                    }

                    $newTotalAmount = 0;
                    foreach ($data['details'] as $detail) {
                        $newTotalAmount += (isset($detail['Slaughter_Fee']) ? (float)$detail['Slaughter_Fee'] : 0) +
                                         (isset($detail['Corral_Fee']) ? (float)$detail['Corral_Fee'] : 0) +
                                         (isset($detail['Ante_Mortem_Fee']) ? (float)$detail['Ante_Mortem_Fee'] : 0) +
                                         (isset($detail['Post_Mortem_Fee']) ? (float)$detail['Post_Mortem_Fee'] : 0) +
                                         (isset($detail['Delivery_Fee']) ? (float)$detail['Delivery_Fee'] : 0);
                    }

                    // Prepare old data for logging
                    $oldFeeData = [
                        'total_amount' => number_format($oldTotalAmount, 2),
                        'client_name' => $oldSlaughterData['client_name'] ?: 'Unknown Client',
                        'business_name' => $oldSlaughterData['Business_Name'] ?: null,
                        'slaughter_date' => $oldSlaughterData['slaughter_date_formatted'],
                        'details_count' => count($oldDetails),
                        'total_heads' => array_sum(array_column($oldDetails, 'No_of_Heads')),
                        'total_kilos' => number_format(array_sum(array_column($oldDetails, 'No_of_Kilos')), 2),
                        'details' => $oldDetails
                    ];

                    // Prepare new data for logging
                    $newFeeData = [
                        'total_amount' => number_format($newTotalAmount, 2),
                        'client_name' => $oldSlaughterData['client_name'] ?: 'Unknown Client', // Client name doesn't change in update
                        'business_name' => $oldSlaughterData['Business_Name'] ?: null,
                        'slaughter_date' => $data['Slaughter_Date'] ? date('Y-m-d H:i:s', strtotime($data['Slaughter_Date'])) : $oldSlaughterData['slaughter_date_formatted'],
                        'details_count' => count($data['details']),
                        'total_heads' => array_sum(array_column($data['details'], 'No_of_Heads')),
                        'total_kilos' => number_format(array_sum(array_column($data['details'], 'No_of_Kilos')), 2),
                        'details' => $data['details']
                    ];

                    // Log the fee entry update
                    $logger->logFeeEntryUpdated($sid, $oldFeeData, $newFeeData);

                    echo json_encode([
                        'success' => true,
                        'message' => 'Slaughter operation updated successfully'
                    ]);

                } catch (Exception $e) {
                    $conn->rollBack();
                    throw $e;
                }
            } else {
                throw new Exception('Invalid update data format');
            }
            break;

        case 'DELETE':
            // Delete slaughter operation (all details for the SID)
            if (!isset($_GET['id'])) {
                throw new Exception('Operation ID is required');
            }

            $operationId = (int)$_GET['id'];

            // Check if this is a SID (slaughter operation) or Detail_ID
            $checkSlaughter = $conn->prepare("SELECT SID FROM tbl_slaughter WHERE SID = ?");
            $checkSlaughter->execute([$operationId]);
            $isSlaughterId = $checkSlaughter->fetch();

            if ($isSlaughterId) {
                // Delete whole operation
                $sid = $operationId;
            } else {
                // Legacy: delete single detail
                $checkDetail = $conn->prepare("SELECT SID FROM tbl_slaughter_details WHERE Detail_ID = ?");
                $checkDetail->execute([$operationId]);
                $detailRow = $checkDetail->fetch();
                if (!$detailRow) {
                    throw new Exception('Operation or detail not found');
                }
                $sid = $detailRow['SID'];
            }

            // Begin transaction
            $conn->beginTransaction();

            try {
                if ($isSlaughterId) {
                    // Delete all details for this operation
                    $stmt = $conn->prepare("DELETE FROM tbl_slaughter_details WHERE SID = ?");
                    $stmt->execute([$sid]);

                    // Delete the slaughter record
                    $slaughterStmt = $conn->prepare("DELETE FROM tbl_slaughter WHERE SID = ?");
                    $slaughterStmt->execute([$sid]);
                } else {
                    // Legacy single detail delete
                    $stmt = $conn->prepare("DELETE FROM tbl_slaughter_details WHERE Detail_ID = ?");
                    $stmt->execute([$operationId]);

                    // Check if this was the only detail
                    $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_slaughter_details WHERE SID = ?");
                    $countStmt->execute([$sid]);
                    if ($countStmt->fetch()['count'] == 0) {
                        $slaughterStmt = $conn->prepare("DELETE FROM tbl_slaughter WHERE SID = ?");
                        $slaughterStmt->execute([$sid]);
                    }
                }

                $conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Slaughter operation deleted successfully'
                ]);

            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;

        case 'OPTIONS':
            // Handle preflight requests
            http_response_code(200);
            break;

        default:
            throw new Exception('Method not allowed');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
