<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config.php';
require_once '../token_auth.php';

// Authenticate user with token for all requests except OPTIONS
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $user_data = TokenAuth::authenticate($conn);
    if (!$user_data) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
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

            if (!empty($search)) {
                $whereClause = "WHERE a.Animal LIKE ? OR s.Slaughter_Date LIKE ? OR CONCAT_WS(' ', c.Firstname, COALESCE(c.Middlename, ''), c.Surname) LIKE ?";
                $params = ["%$search%", "%$search%", "%$search%"];
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
                       s.Slaughter_Date as slaughter_date_formatted,
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
            $data = json_decode(file_get_contents('php://input'), true);

            // Debug logging
            error_log('API Fees POST Data: ' . json_encode($data));

            if (!$data) {
                throw new Exception('Invalid JSON data');
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

            // Validate date format
            $slaughterDate = date('Y-m-d H:i:s', strtotime($data['Slaughter_Date']));
            if ($slaughterDate === '1970-01-01 00:00:00') {
                throw new Exception('Invalid date format');
            }

            // Check if client exists
            $clientCheck = $conn->prepare("SELECT CID FROM tbl_clients WHERE CID = ?");
            $clientCheck->execute([(int)$data['CID']]);
            if (!$clientCheck->fetch()) {
                throw new Exception('Selected client does not exist');
            }

            // Validate each detail
            foreach ($data['details'] as $detail) {
                if (!isset($detail['AID']) || !is_numeric($detail['AID'])) {
                    throw new Exception('Valid animal ID is required for each detail');
                }
                if (!isset($detail['No_of_Heads']) || !is_numeric($detail['No_of_Heads']) || $detail['No_of_Heads'] <= 0) {
                    throw new Exception('Valid number of heads is required for each detail');
                }
                if (!isset($detail['No_of_Kilos']) || !is_numeric($detail['No_of_Kilos']) || $detail['No_of_Kilos'] <= 0) {
                    throw new Exception('Valid weight in kilos is required for each detail');
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
                    isset($data['MID']) ? (int)$data['MID'] : null,
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
                                                      Post_Mortem_Fee, Delivery_Fee, Add_On_Flag)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                            $isAddOn ? 1 : 0
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
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                throw new Exception('Invalid JSON data');
            }

            // Check if this is updating a single detail or the whole operation
            if (isset($data['Fee_ID']) && !isset($data['details'])) {
                // Legacy single detail update
                $detailId = (int)$data['Fee_ID'];

                // Check if detail exists
                $checkStmt = $conn->prepare("SELECT Detail_ID FROM tbl_slaughter_details WHERE Detail_ID = ?");
                $checkStmt->execute([$detailId]);
                if (!$checkStmt->fetch()) {
                    throw new Exception('Slaughter detail not found');
                }

                // Validate required fields
                if (!isset($data['AID']) || !is_numeric($data['AID'])) {
                    throw new Exception('Valid animal ID is required');
                }
                if (!isset($data['No_of_Heads']) || !is_numeric($data['No_of_Heads']) || $data['No_of_Heads'] <= 0) {
                    throw new Exception('Valid number of heads is required');
                }
                if (!isset($data['No_of_Kilos']) || !is_numeric($data['No_of_Kilos']) || $data['No_of_Kilos'] <= 0) {
                    throw new Exception('Valid weight in kilos is required');
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
                        Post_Mortem_Fee = ?, Delivery_Fee = ?, Add_On_Flag = ?
                    WHERE Detail_ID = ?
                ");
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
                    $detailId
                ]);

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

                // Validate each detail
                foreach ($data['details'] as $detail) {
                    if (!isset($detail['AID']) || !is_numeric($detail['AID'])) {
                        throw new Exception('Valid animal ID is required for each detail');
                    }
                    if (!isset($detail['No_of_Heads']) || !is_numeric($detail['No_of_Heads']) || $detail['No_of_Heads'] <= 0) {
                        throw new Exception('Valid number of heads is required for each detail');
                    }
                    if (!isset($detail['No_of_Kilos']) || !is_numeric($detail['No_of_Kilos']) || $detail['No_of_Kilos'] <= 0) {
                        throw new Exception('Valid weight in kilos is required for each detail');
                    }

                    // Check if animal exists
                    $animalCheck = $conn->prepare("SELECT AID FROM tbl_animals WHERE AID = ?");
                    $animalCheck->execute([(int)$detail['AID']]);
                    if (!$animalCheck->fetch()) {
                        throw new Exception('Selected animal type does not exist');
                    }
                }

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
                        isset($data['MID']) ? (int)$data['MID'] : null,
                        date('Y-m-d H:i:s', strtotime($data['Slaughter_Date'])),
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
                                                          Post_Mortem_Fee, Delivery_Fee, Add_On_Flag)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                            isset($data['Add_On_Flag']) && (int)$data['Add_On_Flag'] === 1 ? 1 : 0
                        ]);
                    }

                    $conn->commit();

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