<?php
class TokenAuth {
    private static $token_expiry = 86400; // 24 hours in seconds

    /**
     * Generate a simple random token for a user
     */
    public static function generateToken($user_id, $username, $role = 'user') {
        $token = bin2hex(random_bytes(32)); // 64 character hex token
        $expiry = time() + self::$token_expiry;
        return $token . '|' . $expiry;
    }

    /**
     * Validate a simple token
     */
    public static function validateToken($token) {
        $parts = explode('|', $token);
        if (count($parts) !== 2) {
            return false;
        }

        $token_value = $parts[0];
        $expiry = (int)$parts[1];

        if ($expiry < time()) {
            return false;
        }

        return true;
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

        if (!self::validateToken($token)) {
            return false;
        }

        // Verify token exists in database
        $stmt = $pdo->prepare("SELECT UID, Username, roles FROM tbl_users WHERE token = ?");
        $stmt->execute([$token]);
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
     * Get user information from token
     */
    public static function getUserFromToken($pdo, $token) {
        if (!self::validateToken($token)) {
            return false;
        }

        // Get user info from database
        $stmt = $pdo->prepare("SELECT UID, Username, roles FROM tbl_users WHERE token = ?");
        $stmt->execute([$token]);
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
     * Extract basic user info from token (not available in simple token)
     */
    public static function getBasicUserFromToken($token) {
        if (!self::validateToken($token)) {
            return false;
        }

        // No user info stored in simple token
        return false;
    }
}
?>