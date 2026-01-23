<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once('config.php'); // config.php should define $conn

try {
    // 1️⃣ Handle search keyword if passed via GET
    $search = $_GET['search'] ?? '';
    $search = trim($search);

    // 2️⃣ Base query: select claims with offense and personal info
    $sql = "
      SELECT 
            c.claim_id,
            c.offenseid AS claim_offenseid,
            c.claimed_date,
            c.or_number,
            c.court_number,
            c.status,
            COALESCE(NULLIF(TRIM(o.u_address), ''), 'N/A') AS address,
            COALESCE(p.full_name, 'N/A') AS full_name,
            o.model,
            o.Plate_no
        FROM tbl_claim c
        LEFT JOIN tbl_offense o ON c.offenseid = o.offenseid
        LEFT JOIN tbl_personal_info p ON o.piid = p.piid
        WHERE c.isdeleted = 0
    ";

    // 3️⃣ Add search filtering if keyword exists
    $params = [];
    if ($search !== '') {
        $sql .= " AND (
            COALESCE(p.full_name, '') LIKE ? OR
            COALESCE(o.u_address, '') LIKE ? OR
            COALESCE(c.or_number, '') LIKE ? OR
            COALESCE(c.court_number, '') LIKE ?
        )";
        $like = "%$search%";
        $params = [$like, $like, $like, $like];
    }

    $stmt = $conn->prepare($sql);

    // Bind parameters dynamically
    if (!empty($params)) {
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $claims = [];
    while ($row = $result->fetch_assoc()) {
        $claims[] = $row;
    }

    echo json_encode($claims);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    $conn->close();
}
?>
