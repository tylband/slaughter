<?php
class TokenAuth {
    private static $secret_key = 'sk_slaughter_sakatamalaybalay_prod_2024_a8f9k2m5n7p3r6t8v1w4x9z2c5b8';
    private static $token_expiry = 86400; // 24 hours in seconds

    /**
     * Generate a JWT-like token for a user
     */
    public static function generateToken($user_id, $username, $role = 'user') {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => $user_id,
            'username' => $username,
            'role' => $role,
            'iat' => time(),
            'exp' => time() + self::$token_expiry
        ]);

        $header_encoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $payload_encoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

        $signature = hash_hmac('sha256', $header_encoded . "." . $payload_encoded, self::$secret_key, true);
        $signature_encoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        $token = $header_encoded . "." . $payload_encoded . "." . $signature_encoded;

        return $token;
    }

    /**
     * Validate a JWT-like token
     */
    public static function validateToken($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        $header = $parts[0];
        $payload = $parts[1];
        $signature = $parts[2];

        // Verify signature
        $expected_signature = hash_hmac('sha256', $header . "." . $payload, self::$secret_key, true);
        $expected_signature_encoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expected_signature));

        if (!hash_equals($signature, $expected_signature_encoded)) {
            return false;
        }

        // Decode payload
        $payload_decoded = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload)), true);

        // Check expiry
        if ($payload_decoded['exp'] < time()) {
            return false;
        }

        return $payload_decoded;
    }

    /**
     * Get token from Authorization header
     */
    public static function getTokenFromHeader() {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $auth_header = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    /**
     * Get token from cookie
     */
    public static function getTokenFromCookie() {
        return isset($_COOKIE['auth_token']) ? $_COOKIE['auth_token'] : null;
    }

    /**
     * Get token from request (header or cookie)
     */
    public static function getToken() {
        $token = self::getTokenFromHeader();
        if (!$token) {
            $token = self::getTokenFromCookie();
        }
        return $token;
    }

    /**
     * Authenticate user from token
     */
    public static function authenticate($pdo) {
        $token = self::getToken();
        if (!$token) {
            return false;
        }

        $payload = self::validateToken($token);
        if (!$payload) {
            return false;
        }

        // Verify token exists in database and matches user
        $stmt = $pdo->prepare("SELECT UID, Username, roles FROM tbl_users WHERE UID = ? AND token = ?");
        $stmt->execute([$payload['user_id'], $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return false;
        }

        return [
            'user_id' => $user['UID'],
            'username' => $user['Username'],
            'role' => $user['roles'],
            'token_data' => $payload
        ];
    }

    /**
     * Store token in database
     */
    public static function storeToken($pdo, $user_id, $token) {
        $stmt = $pdo->prepare("UPDATE tbl_users SET token = ? WHERE UID = ?");
        return $stmt->execute([$token, $user_id]);
    }

    /**
     * Remove token from database (logout)
     */
    public static function removeToken($pdo, $user_id) {
        $stmt = $pdo->prepare("UPDATE tbl_users SET token = NULL WHERE UID = ?");
        return $stmt->execute([$user_id]);
    }

    /**
     * Require authentication middleware
     */
    public static function requireAuth($pdo) {
        $user = self::authenticate($pdo);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit();
        }
        return $user;
    }

    /**
     * Check if user has required role
     */
    public static function requireRole($user, $required_role) {
        if ($user['role'] !== $required_role && $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit();
        }
    }

    /**
     * Get user information from token without database validation
     * Useful for logging before token removal
     */
    public static function getUserFromToken($pdo, $token) {
        $payload = self::validateToken($token);
        if (!$payload) {
            return false;
        }

        // Get user info from database
        $stmt = $pdo->prepare("SELECT UID, Username, roles FROM tbl_users WHERE UID = ?");
        $stmt->execute([$payload['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return false;
        }

        return [
            'user_id' => $user['UID'],
            'username' => $user['Username'],
            'role' => $user['roles']
        ];
    }

    /**
     * Extract basic user info from token payload (without database lookup)
     * Use only when you don't need full validation
     */
    public static function getBasicUserFromToken($token) {
        $payload = self::validateToken($token);
        if (!$payload) {
            return false;
        }

        return [
            'user_id' => $payload['user_id'],
            'username' => $payload['username'],
            'role' => $payload['role']
        ];
    }
}
?>