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

require_once "config.php";
require_once "system_logger.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    $name = trim($data['name'] ?? '');
    $roles = trim($data['roles'] ?? 'user');

    // Validate input
    if (empty($username) || empty($password) || empty($name)) {
        echo json_encode([
            "status" => "error",
            "message" => "Username, password, and name are required."
        ]);
        exit;
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
        $stmt = $conn->prepare("SELECT UID FROM tbl_users WHERE Username = :username");
        $stmt->bindParam(":username", $username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            echo json_encode([
                "status" => "error",
                "message" => "Username already exists. Please choose a different username."
            ]);
            exit;
        }

        // Hash password with MD5
        $hashedPassword = md5($password);

        // Insert new user
        $stmt = $conn->prepare("INSERT INTO tbl_users (Name, Username, Password, roles) VALUES (:name, :username, :password, :roles)");
        $stmt->bindParam(":name", $name);
        $stmt->bindParam(":username", $username);
        $stmt->bindParam(":password", $hashedPassword);
        $stmt->bindParam(":roles", $roles);

        if ($stmt->execute()) {
            $new_user_id = $conn->lastInsertId();

            // Log successful user registration
            $logger = new SystemLogger($conn);
            $logger->logActivity(
                'user_registered',
                "New user registered: {$name} ({$username}) with role: {$roles}",
                'tbl_users',
                $new_user_id,
                null,
                [
                    'name' => $name,
                    'username' => $username,
                    'role' => $roles
                ]
            );

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
    } catch (PDOException $e) {
        error_log("Registration error: " . $e->getMessage());
        echo json_encode([
            "status" => "error",
            "message" => "Database error occurred. Please try again."
        ]);
    }
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid request method."
    ]);
}
?>

