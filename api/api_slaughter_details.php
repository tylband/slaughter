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

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // List slaughter details
            $sid = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

            $offset = ($page - 1) * $limit;

            // Build query
            $whereClause = '';
            $params = [];

            if ($sid > 0) {
                $whereClause = "WHERE sd.SID = ?";
                $params[] = $sid;
            }

            // Get total count
            $countStmt = $conn->prepare("
                SELECT COUNT(*) as total FROM tbl_slaughter_details sd
                $whereClause
            ");
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get details with client and operation information
            $stmt = $conn->prepare("
                SELECT sd.Detail_ID, sd.SID, sd.AID, sd.No_of_Heads, sd.No_of_Kilos,
                       sd.Slaughter_Fee, sd.Corral_Fee, sd.Ante_Mortem_Fee,
                       sd.Post_Mortem_Fee, sd.Delivery_Fee, sd.Add_On_Flag,
                       a.Animal,
                       s.Slaughter_Date as slaughter_date_formatted,
                       s.CID, s.BID, s.MID,
                       CONCAT_WS(' ', c.Firstname, COALESCE(c.Middlename, ''), c.Surname) as client_name,
                       cb.Business_Name
                FROM tbl_slaughter_details sd
                JOIN tbl_animals a ON sd.AID = a.AID
                JOIN tbl_slaughter s ON sd.SID = s.SID
                LEFT JOIN tbl_clients c ON s.CID = c.CID
                LEFT JOIN tbl_client_business cb ON s.BID = cb.BID
                $whereClause
                ORDER BY sd.Detail_ID
                LIMIT $limit OFFSET $offset
            ");
            $stmt->execute($params);
            $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format data
            foreach ($details as &$detail) {
                $detail['total_fees'] = (float)$detail['Slaughter_Fee'] + (float)$detail['Corral_Fee'] +
                                       (float)$detail['Ante_Mortem_Fee'] + (float)$detail['Post_Mortem_Fee'] +
                                       (float)$detail['Delivery_Fee'];
                $detail['No_of_Heads'] = (int)$detail['No_of_Heads'];
                $detail['No_of_Kilos'] = (float)$detail['No_of_Kilos'];
            }

            echo json_encode([
                'success' => true,
                'data' => $details,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => (int)$total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;

        case 'POST':
            // Create slaughter details
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data) {
                throw new Exception('Invalid JSON data');
            }

            // Expect array of details
            if (!is_array($data)) {
                $data = [$data];
            }

            $conn->beginTransaction();

            try {
                $created = [];

                foreach ($data as $detail) {
                    // Validate required fields
                    if (!isset($detail['SID']) || !is_numeric($detail['SID'])) {
                        throw new Exception('Valid SID is required for each detail');
                    }
                    if (!isset($detail['AID']) || !is_numeric($detail['AID'])) {
                        throw new Exception('Valid AID is required for each detail');
                    }

                    // Check if slaughter operation exists
                    $slaughterCheck = $conn->prepare("SELECT SID FROM tbl_slaughter WHERE SID = ?");
                    $slaughterCheck->execute([$detail['SID']]);
                    if (!$slaughterCheck->fetch()) {
                        throw new Exception('Slaughter operation not found');
                    }

                    // Check if animal exists
                    $animalCheck = $conn->prepare("SELECT AID FROM tbl_animals WHERE AID = ?");
                    $animalCheck->execute([$detail['AID']]);
                    if (!$animalCheck->fetch()) {
                        throw new Exception('Animal type not found');
                    }

                    // Get latest fees for this animal
                    $feesStmt = $conn->prepare("
                        SELECT Fee_Name, Amount
                        FROM tbl_fees
                        WHERE AID = ?
                        ORDER BY Date DESC
                    ");
                    $feesStmt->execute([$detail['AID']]);
                    $fees = $feesStmt->fetchAll(PDO::FETCH_ASSOC);

                    // Map fees to columns
                    $slaughterFee = 0.00;
                    $corralFee = 0.00;
                    $anteMortemFee = 0.00;
                    $postMortemFee = 0.00;
                    $deliveryFee = 0.00;

                    foreach ($fees as $fee) {
                        $amount = (float)$fee['Amount'];
                        $name = strtolower(trim($fee['Fee_Name']));

                        if (strpos($name, 'slaughter') !== false) {
                            $slaughterFee = $amount;
                        } elseif (strpos($name, 'corral') !== false) {
                            $corralFee = $amount;
                        } elseif (strpos($name, 'ante') !== false && strpos($name, 'mortem') !== false) {
                            $anteMortemFee = $amount;
                        } elseif (strpos($name, 'post') !== false && strpos($name, 'mortem') !== false) {
                            $postMortemFee = $amount;
                        } elseif (strpos($name, 'delivery') !== false) {
                            $deliveryFee = $amount;
                        }
                    }

                    $stmt = $conn->prepare("
                        INSERT INTO tbl_slaughter_details
                        (SID, AID, No_of_Heads, No_of_Kilos, Slaughter_Fee, Corral_Fee,
                         Ante_Mortem_Fee, Post_Mortem_Fee, Delivery_Fee, Add_On_Flag)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $detail['SID'],
                        $detail['AID'],
                        isset($detail['No_of_Heads']) ? (int)$detail['No_of_Heads'] : 0,
                        isset($detail['No_of_Kilos']) ? (float)$detail['No_of_Kilos'] : 0.00,
                        $slaughterFee,
                        $corralFee,
                        $anteMortemFee,
                        $postMortemFee,
                        $deliveryFee,
                        isset($detail['Add_On_Flag']) ? (int)$detail['Add_On_Flag'] : 0
                    ]);

                    $created[] = $conn->lastInsertId();
                }

                $conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Slaughter details created successfully',
                    'data' => ['Detail_IDs' => $created]
                ]);

            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;

        case 'PUT':
            // Update slaughter detail
            $data = json_decode(file_get_contents('php://input'), true);

            if (!$data || !isset($data['Detail_ID'])) {
                throw new Exception('Invalid JSON data or missing Detail_ID');
            }

            $detailId = (int)$data['Detail_ID'];

            // Check if detail exists
            $checkStmt = $conn->prepare("SELECT Detail_ID FROM tbl_slaughter_details WHERE Detail_ID = ?");
            $checkStmt->execute([$detailId]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Slaughter detail not found');
            }

            // Validate AID if provided
            if (isset($data['AID'])) {
                $animalCheck = $conn->prepare("SELECT AID FROM tbl_animals WHERE AID = ?");
                $animalCheck->execute([$data['AID']]);
                if (!$animalCheck->fetch()) {
                    throw new Exception('Animal type not found');
                }
            }

            // Build update query
            $updateFields = [];
            $params = [];

            $fields = ['SID', 'AID', 'No_of_Heads', 'No_of_Kilos', 'Slaughter_Fee', 'Corral_Fee',
                      'Ante_Mortem_Fee', 'Post_Mortem_Fee', 'Delivery_Fee', 'Add_On_Flag'];
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = is_numeric($data[$field]) ? (float)$data[$field] : $data[$field];
                }
            }

            if (empty($updateFields)) {
                throw new Exception('No fields to update');
            }

            $params[] = $detailId;
            $stmt = $conn->prepare("UPDATE tbl_slaughter_details SET " . implode(', ', $updateFields) . " WHERE Detail_ID = ?");
            $stmt->execute($params);

            echo json_encode([
                'success' => true,
                'message' => 'Slaughter detail updated successfully'
            ]);
            break;

        case 'DELETE':
            // Delete slaughter detail
            if (!isset($_GET['id'])) {
                throw new Exception('Detail ID is required');
            }

            $detailId = (int)$_GET['id'];

            // Check if detail exists
            $checkStmt = $conn->prepare("SELECT Detail_ID FROM tbl_slaughter_details WHERE Detail_ID = ?");
            $checkStmt->execute([$detailId]);
            if (!$checkStmt->fetch()) {
                throw new Exception('Slaughter detail not found');
            }

            $stmt = $conn->prepare("DELETE FROM tbl_slaughter_details WHERE Detail_ID = ?");
            $stmt->execute([$detailId]);

            echo json_encode([
                'success' => true,
                'message' => 'Slaughter detail deleted successfully'
            ]);
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