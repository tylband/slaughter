<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "./config.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    $role = strtolower(trim($data['role'] ?? ''));
    $location = strtolower(trim($data['location'] ?? ''));
    $workArea = strtolower(trim($data['work_area'] ?? ''));
    $fName = trim($data['f_name'] ?? '');
    $mName = trim($data['m_name'] ?? '');
    $lName = trim($data['l_name'] ?? '');

    $roleMap = [
        'super_admin' => 1,
        'admin' => 2,
        'user' => 7
    ];
    $allowedLocations = [
        'all' => 'ALL',
        'cho' => 'CHO',
        'aglayan' => 'Aglayan',
        'uplc' => 'UPLC'
    ];
    $allowedWorkAreas = ['stock_room', 'pharmacy'];

    // Validate input
    if (empty($username) || empty($password)) {
        echo json_encode([
            "status" => "error",
            "message" => "Username and password are required."
        ]);
        exit;
    }

    if (empty($role) || !array_key_exists($role, $roleMap)) {
        echo json_encode([
            "status" => "error",
            "message" => "Please select a valid role."
        ]);
        exit;
    }

    $isSuperAdmin = ($role === 'super_admin');

    if ($isSuperAdmin) {
        $location = 'all';
        $workArea = 'all';
    } else {
        if (empty($location) || !array_key_exists($location, $allowedLocations)) {
            echo json_encode([
                "status" => "error",
                "message" => "Please select a valid location."
            ]);
            exit;
        }

        if (empty($workArea) || !in_array($workArea, $allowedWorkAreas, true)) {
            echo json_encode([
                "status" => "error",
                "message" => "Please select a valid work area."
            ]);
            exit;
        }
    }

    if (strlen($username) < 3) {
        echo json_encode([
            "status" => "error",
            "message" => "Username must be at least 3 characters long."
        ]);
        exit;
    }

    if (strlen($password) < 6) {
        echo json_encode([
            "status" => "error",
            "message" => "Password must be at least 6 characters long."
        ]);
        exit;
    }

    try {
        // Check if username already exists
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE user_name = :username");
        $stmt->bindParam(":username", $username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            echo json_encode([
                "status" => "error",
                "message" => "Username already exists. Please choose a different username."
            ]);
            exit;
        }

        // Hash the password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Insert new user
        $roleId = $roleMap[$role];
        $locationValue = $allowedLocations[$location] ?? 'ALL';
        $workAreaValue = $isSuperAdmin ? 'ALL' : $workArea;

        $stmt = $conn->prepare("
            INSERT INTO users (user_name, password, role_id, Location, work_area, f_name, m_name, l_name)
            VALUES (:username, :password, :role_id, :location, :work_area, :f_name, :m_name, :l_name)
        ");
        $stmt->bindParam(":username", $username);
        $stmt->bindParam(":password", $hashedPassword);
        $stmt->bindParam(":role_id", $roleId);
        $stmt->bindParam(":location", $locationValue);
        $stmt->bindParam(":work_area", $workAreaValue);
        $stmt->bindParam(":f_name", $fName);
        $stmt->bindParam(":m_name", $mName);
        $stmt->bindParam(":l_name", $lName);

        if ($stmt->execute()) {
            echo json_encode([
                "status" => "success",
                "message" => "Registration successful! You can now log in."
            ]);
        } else {
            echo json_encode([
                "status" => "error",
                "message" => "Registration failed. Please try again."
            ]);
        }
    } catch (Exception $e) {
        error_log("Registration error: " . $e->getMessage());
        echo json_encode([
            "status" => "error",
            "message" => "Database error occurred."
        ]);
    }
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid request method."
    ]);
}
?>
