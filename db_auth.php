<?php
// Secure Database-Based Token Authentication System with Hashed Tokens
// Implements atomic token rotation with proper database tables

// Apply CORS headers when called from an API endpoint (not from a system page).
$_dba_caller = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
if (strncmp($_dba_caller, 'api_', 4) === 0) {
    require_once __DIR__ . '/cors.php';
}
unset($_dba_caller);

// Load environment variables from API/.env (function already defined in config.php)
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) {
            throw new Exception("Environment file not found: $path");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

loadEnv(__DIR__ . '/.env');

// Database connection using API/.env (no fallbacks - must be in .env)
$host = getenv('DB_HOST');
$dbname = getenv('DB_NAME');
$username = getenv('DB_USER');
$password = getenv('DB_PASS');

if (!$host || !$dbname || !$username) {
    die("Database configuration missing from .env file");
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Force UTC timezone for consistent token expiration handling
    $conn->exec("SET time_zone = '+00:00'");

    // Keep auth requests responsive under lock contention.
    try {
        $conn->exec("SET innodb_lock_wait_timeout = 5");
    } catch (Throwable $timeoutConfigError) {
        error_log("Unable to set innodb_lock_wait_timeout: " . $timeoutConfigError->getMessage());
    }

    error_log("Database connection successful - timezone set to UTC");
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $conn = null; // Set to null instead of dying
}

// Token configuration
define('ACCESS_TOKEN_EXPIRY', 3600); // 1 hour
define('REFRESH_TOKEN_EXPIRY', 2592000); // 30 days
define('TOKEN_LENGTH', 64);
define('HASH_ALGO', PASSWORD_DEFAULT); // Secure hashing algorithm (bcrypt)

/**
 * Normalize role to app-friendly key.
 */
function normalizeRoleKey($roleId, $roleName = '') {
    $roleId = (int)$roleId;
    $roleName = strtolower(trim((string)$roleName));

    if ($roleId === 1 || ($roleName !== '' && strpos($roleName, 'super') !== false)) {
        return 'super_admin';
    }

    if ($roleId === 2 || ($roleName !== '' && strpos($roleName, 'admin') !== false)) {
        return 'admin';
    }

    return 'user';
}

/**
 * Fetch user profile details from users table.
 * Returns null if not found or on error.
 */
function getUserProfile($userId) {
    global $conn;

    if (!$conn) {
        return null;
    }

    try {
        $stmt = $conn->prepare("
            SELECT
                u.user_id,
                u.user_name,
                u.role_id,
                r.role AS role_name,
                u.Location AS location,
                u.work_area
            FROM users u
            LEFT JOIN table_role r ON r.role_id = u.role_id
            WHERE u.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    } catch (Exception $e) {
        error_log("Error fetching user profile: " . $e->getMessage());
        return null;
    }
}

/**
 * Generate a cryptographically secure random token
 */
function generateSecureToken($length = TOKEN_LENGTH) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Hash a token for secure storage
 */
function hashToken($token) {
    return password_hash($token, HASH_ALGO);
}

/**
 * Verify a token against its hash
 */
function verifyTokenHash($token, $hash) {
    return password_verify($token, $hash);
}

/**
 * Generate session token (access token)
 */
function generateSessionToken() {
    return generateSecureToken();
}

/**
 * Generate refresh token
 */
function generateRefreshToken() {
    return generateSecureToken();
}

/**
 * Atomic token rotation: Store new access token and invalidate old ones
 * Uses database transactions for atomicity
 */
function storeUserSession($userId, $username) {
    global $conn;

    if (!$conn) {
        error_log("Error in atomic token rotation: Database connection is not available.");
        return false;
    }

    // Generate tokens outside the transaction to keep lock duration short.
    $accessToken = generateSessionToken();
    $refreshToken = generateRefreshToken();
    $accessTokenHash = hashToken($accessToken);
    $refreshTokenHash = hashToken($refreshToken);

    $accessExpiresAt = date('Y-m-d H:i:s', time() + ACCESS_TOKEN_EXPIRY);
    $refreshExpiresAt = date('Y-m-d H:i:s', time() + REFRESH_TOKEN_EXPIRY);

    // Get client info
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $issueAccessOnlySession = function () use (
        $conn,
        $accessToken,
        $accessTokenHash,
        $accessExpiresAt,
        $ipAddress,
        $userAgent,
        $userId,
        $username
    ) {
        try {
            $conn->beginTransaction();

            $accessStmt = $conn->prepare("
                INSERT INTO access_tokens
                (token, user_id, username, expires_at, ip_address, user_agent, is_active)
                VALUES (?, ?, ?, ?, ?, ?, TRUE)
            ");
            $accessStmt->execute([
                $accessTokenHash,
                $userId,
                $username,
                $accessExpiresAt,
                $ipAddress,
                $userAgent
            ]);

            $accessTokenId = $conn->lastInsertId();
            logTokenAction($accessTokenId, 'access', $userId, 'created', $ipAddress, $userAgent);

            $conn->commit();
            error_log("Issued access token without rotation for user: $username due to lock contention.");

            return [
                'access_token' => $accessToken,
                'refresh_token' => null,
                'expires_in' => ACCESS_TOKEN_EXPIRY,
                'token_type' => 'Bearer'
            ];
        } catch (Throwable $fallbackError) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("Fallback token issuance failed: " . $fallbackError->getMessage());
            return false;
        }
    };

    $maxAttempts = 3;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $conn->beginTransaction();

            // Revoke all existing active tokens for this user (token rotation)
            $revokeStmt = $conn->prepare("
                UPDATE access_tokens
                SET is_active = FALSE
                WHERE user_id = ? AND is_active = TRUE
            ");
            $revokeStmt->execute([$userId]);

            // Mark existing refresh tokens as revoked
            $revokeRefreshStmt = $conn->prepare("
                UPDATE refresh_tokens
                SET is_revoked = TRUE
                WHERE user_id = ? AND is_revoked = FALSE
            ");
            $revokeRefreshStmt->execute([$userId]);

            // Insert new access token
            $accessStmt = $conn->prepare("
                INSERT INTO access_tokens
                (token, user_id, username, expires_at, ip_address, user_agent, is_active)
                VALUES (?, ?, ?, ?, ?, ?, TRUE)
            ");
            $accessStmt->execute([
                $accessTokenHash,
                $userId,
                $username,
                $accessExpiresAt,
                $ipAddress,
                $userAgent
            ]);

            $accessTokenId = $conn->lastInsertId();

            // Insert new refresh token
            $refreshStmt = $conn->prepare("
                INSERT INTO refresh_tokens
                (refresh_token, user_id, username, access_token_id, expires_at, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $refreshStmt->execute([
                $refreshTokenHash,
                $userId,
                $username,
                $accessTokenId,
                $refreshExpiresAt,
                $ipAddress,
                $userAgent
            ]);

            // Log token creation
            logTokenAction($accessTokenId, 'access', $userId, 'created', $ipAddress, $userAgent);

            $conn->commit();

            error_log("Secure token rotation completed for user: $username");

            return [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => ACCESS_TOKEN_EXPIRY,
                'token_type' => 'Bearer'
            ];

        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $message = $e->getMessage();
            $isLockContention = (strpos($message, '1205') !== false) ||
                (stripos($message, 'Lock wait timeout exceeded') !== false) ||
                (strpos($message, '1213') !== false) ||
                (stripos($message, 'Deadlock found') !== false);

            if ($isLockContention && $attempt < $maxAttempts) {
                usleep(150000 * $attempt);
                continue;
            }

            if ($isLockContention) {
                return $issueAccessOnlySession();
            }

            error_log("Error in atomic token rotation: " . $message);
            return false;
        }
    }

    return false;
}

/**
 * Validate access token using hashed comparison
 */
function validateToken() {
    global $conn;

    error_log("=== VALIDATE TOKEN DEBUG ===");
    error_log("Request URI: " . $_SERVER['REQUEST_URI']);
    error_log("Request method: " . $_SERVER['REQUEST_METHOD']);

    if (!$conn) {
        error_log("validateToken: Database connection is not available");
        return false;
    }

    // Get token from multiple sources
    $token = null;

    // First priority: Authorization header
    $headers = getallheaders();
    error_log("Headers: " . json_encode($headers));
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
            $token = $matches[1];
            error_log("validateToken: Found token in Authorization header");
        }
    }

    // Second priority: Cookie
    if (!$token) {
        $token = $_COOKIE['auth_token'] ?? null;
        if ($token) {
            error_log("validateToken: Found token in cookie");
        } else {
            error_log("validateToken: No token in cookie");
        }
    }

    // Third priority: POST data (for AJAX requests)
    if (!$token && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['auth_token'] ?? null;
        if ($token) {
            error_log("validateToken: Found token in POST data");
        }
    }

    // Fourth priority: GET data (for direct links)
    if (!$token && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $token = $_GET['auth_token'] ?? null;
        if ($token) {
            error_log("validateToken: Found token in GET data: " . substr($token, 0, 10) . "...");
        } else {
            error_log("validateToken: No token in GET data");
        }
    }

    if (!$token) {
        error_log("validateToken: No token found in any source");
        return false;
    }

    error_log("validateToken: Using token: " . substr($token, 0, 10) . "...");

    try {
        // Find active access token by hash verification
        $stmt = $conn->prepare("
            SELECT id, user_id, username, token, expires_at, last_accessed
            FROM access_tokens
            WHERE expires_at > NOW() AND is_active = TRUE
        ");
        $stmt->execute();
        $tokens = $stmt->fetchAll();

        foreach ($tokens as $tokenData) {
            if (verifyTokenHash($token, $tokenData['token'])) {
                // Token verified, update last accessed
                $updateStmt = $conn->prepare("
                    UPDATE access_tokens
                    SET last_accessed = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$tokenData['id']]);

                // Log validation
                logTokenAction($tokenData['id'], 'access', $tokenData['user_id'], 'validated');

                $profile = getUserProfile($tokenData['user_id']);
                if (!$profile) {
                    $profile = [];
                }

                return [
                    'id' => $tokenData['user_id'],
                    'username' => $tokenData['username'],
                    'role' => normalizeRoleKey($profile['role_id'] ?? null, $profile['role_name'] ?? ''),
                    'role_id' => isset($profile['role_id']) ? (int)$profile['role_id'] : null,
                    'role_name' => $profile['role_name'] ?? null,
                    'location' => $profile['location'] ?? null,
                    'work_area' => $profile['work_area'] ?? null,
                    'token' => $token,
                    'expires_at' => $tokenData['expires_at']
                ];
            }
        }

        error_log("validateToken: Token verification failed");
        return false;

    } catch (Exception $e) {
        error_log("Error validating token: " . $e->getMessage());
        return false;
    }
}

/**
 * Refresh access token using refresh token (atomic operation)
 */
function refreshAccessToken($refreshToken) {
    global $conn;

    if (!$conn) {
        return false;
    }

    try {
        $conn->beginTransaction();

        // Find valid refresh token
        $stmt = $conn->prepare("
            SELECT rt.id, rt.user_id, rt.username, rt.refresh_token, rt.access_token_id, at.token as access_token_hash
            FROM refresh_tokens rt
            LEFT JOIN access_tokens at ON rt.access_token_id = at.id
            WHERE rt.expires_at > NOW() AND rt.is_used = FALSE AND rt.is_revoked = FALSE
        ");
        $stmt->execute();
        $refreshTokens = $stmt->fetchAll();

        foreach ($refreshTokens as $rt) {
            if (verifyTokenHash($refreshToken, $rt['refresh_token'])) {
                // Mark refresh token as used
                $updateRefreshStmt = $conn->prepare("
                    UPDATE refresh_tokens
                    SET is_used = TRUE
                    WHERE id = ?
                ");
                $updateRefreshStmt->execute([$rt['id']]);

                // Mark old access token as inactive
                $updateAccessStmt = $conn->prepare("
                    UPDATE access_tokens
                    SET is_active = FALSE
                    WHERE id = ?
                ");
                $updateAccessStmt->execute([$rt['access_token_id']]);

                // Generate new token pair
                $newTokens = storeUserSession($rt['user_id'], $rt['username']);

                if ($newTokens) {
                    $conn->commit();
                    logTokenAction($rt['access_token_id'], 'refresh', $rt['user_id'], 'refreshed');
                    return $newTokens;
                } else {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    return false;
                }
            }
        }

        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Invalid or expired refresh token");
        return false;

    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Error refreshing token: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete user session (logout) - revoke all tokens atomically
 */
function deleteUserSession($token) {
    global $conn;

    if (!$conn) {
        return false;
    }

    try {
        $conn->beginTransaction();

        // Find the token to revoke
        $stmt = $conn->prepare("
            SELECT id, user_id, token FROM access_tokens
            WHERE expires_at > NOW() AND is_active = TRUE
        ");
        $stmt->execute();
        $tokens = $stmt->fetchAll();

        foreach ($tokens as $tokenData) {
            if (verifyTokenHash($token, $tokenData['token'])) {
                // Revoke access token
                $revokeAccessStmt = $conn->prepare("
                    UPDATE access_tokens
                    SET is_active = FALSE
                    WHERE id = ?
                ");
                $revokeAccessStmt->execute([$tokenData['id']]);

                // Revoke associated refresh tokens
                $revokeRefreshStmt = $conn->prepare("
                    UPDATE refresh_tokens
                    SET is_revoked = TRUE
                    WHERE access_token_id = ?
                ");
                $revokeRefreshStmt->execute([$tokenData['id']]);

                // Log revocation
                logTokenAction($tokenData['id'], 'access', $tokenData['user_id'], 'revoked');

                $conn->commit();
                error_log("User session revoked for user ID: " . $tokenData['user_id']);
                return true;
            }
        }

        $conn->rollBack();
        error_log("Token not found for revocation");
        return false;

    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("Error deleting user session: " . $e->getMessage());
        return false;
    }
}

/**
 * Log token actions for auditing
 */
function logTokenAction($tokenId, $tokenType, $userId, $action, $ipAddress = null, $userAgent = null) {
    global $conn;

    try {
        if (!$ipAddress) $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!$userAgent) $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare("
            INSERT INTO token_logs
            (token_id, token_type, user_id, action, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tokenId, $tokenType, $userId, $action, $ipAddress, $userAgent]);
    } catch (Exception $e) {
        error_log("Error logging token action: " . $e->getMessage());
    }
}

/**
 * Canonical module registry for page-level access checks.
 */
function getModuleRegistry() {
    return [
        'subsystem_emedtrack' => [
            'label' => 'CHIS EMedTrack',
            'description' => 'Access to the EMedTrack subsystem',
            'page' => 'subsystem_entry.php?subsystem=emedtrack',
            'subsystem' => 'emedtrack'
        ],
        'subsystem_laboratory' => [
            'label' => 'CHIS Laboratory',
            'description' => 'Access to the Laboratory subsystem',
            'page' => 'subsystem_entry.php?subsystem=laboratory',
            'subsystem' => 'laboratory'
        ],
        'dashboard' => [
            'label' => 'Dashboard',
            'description' => 'Overview page and summary widgets',
            'page' => 'inventory_dashboard.php',
            'subsystem' => 'emedtrack'
        ],
        'inventory' => [
            'label' => 'Inventory',
            'description' => 'Inventory list, stock updates, and adjustments',
            'page' => 'inventory.php',
            'subsystem' => 'emedtrack'
        ],
        'checkout' => [
            'label' => 'Checkout',
            'description' => 'Item checkout and RIS generation',
            'page' => 'checkout.php',
            'subsystem' => 'emedtrack'
        ],
        'clinics' => [
            'label' => 'Stocks Distribution',
            'description' => 'Clinic transfer and stocks distribution',
            'page' => 'clinics.php',
            'subsystem' => 'emedtrack'
        ],
        'transaction_history' => [
            'label' => 'Transaction History',
            'description' => 'Audit trail and transaction logs',
            'page' => 'transaction_history.php',
            'subsystem' => 'emedtrack'
        ],
        'reports' => [
            'label' => 'Inventory Reports',
            'description' => 'Inventory and stock movement reports',
            'page' => 'reports.php',
            'subsystem' => 'emedtrack'
        ],
        'user_manual' => [
            'label' => 'User Manual',
            'description' => 'System documentation and usage guides',
            'page' => 'user_manual.php',
            'subsystem' => 'emedtrack'
        ],
        'user_management' => [
            'label' => 'User Management',
            'description' => 'User administration and role module access levels',
            'page' => 'user_management.php',
            'subsystem' => 'chis'
        ]
    ];
}

function normalizeModuleKey($moduleKey) {
    return strtolower(trim((string)$moduleKey));
}

function isSuperAdminRoleKey($roleKey) {
    $roleKey = strtolower(trim((string)$roleKey));
    return $roleKey === 'super_admin' || $roleKey === 'superadmin';
}

function getDefaultModuleAccessByRoleKey($roleKey) {
    $roleKey = strtolower(trim((string)$roleKey));
    $defaults = [
        'subsystem_emedtrack' => true,
        'subsystem_laboratory' => false,
        'dashboard' => true,
        'inventory' => false,
        'checkout' => false,
        'clinics' => false,
        'transaction_history' => false,
        'reports' => false,
        'user_manual' => true,
        'user_management' => false
    ];

    if (isSuperAdminRoleKey($roleKey)) {
        foreach ($defaults as $moduleKey => $value) {
            $defaults[$moduleKey] = true;
        }
        return $defaults;
    }

    if ($roleKey === 'admin') {
        $defaults['subsystem_laboratory'] = true;
        $defaults['inventory'] = true;
        $defaults['checkout'] = true;
        $defaults['clinics'] = true;
        $defaults['transaction_history'] = true;
        $defaults['reports'] = true;
        return $defaults;
    }

    if ($roleKey === 'user') {
        $defaults['checkout'] = true;
        return $defaults;
    }

    return $defaults;
}

function getCanonicalRoleIds() {
    return [1, 2, 7];
}

/**
 * Load role metadata from table_role with safe fallbacks.
 */
function getRoleDefinitions() {
    global $conn;

    $fallback = [
        ['role_id' => 1, 'role_name' => 'Super Admin', 'role_key' => 'super_admin'],
        ['role_id' => 2, 'role_name' => 'Admin', 'role_key' => 'admin'],
        ['role_id' => 7, 'role_name' => 'User', 'role_key' => 'user']
    ];
    $canonicalRoleIds = getCanonicalRoleIds();

    if (!$conn) {
        return $fallback;
    }

    try {
        $stmt = $conn->query("SELECT role_id, role FROM table_role ORDER BY role_id ASC");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        error_log("Error loading role definitions: " . $e->getMessage());
        return $fallback;
    }

    if (empty($rows)) {
        return $fallback;
    }

    $rolesById = [];
    foreach ($rows as $row) {
        $roleId = isset($row['role_id']) ? (int)$row['role_id'] : 0;
        if ($roleId <= 0) {
            continue;
        }

        // Keep only roles that are used by the current system implementation.
        if (!in_array($roleId, $canonicalRoleIds, true)) {
            continue;
        }

        $roleName = trim((string)($row['role'] ?? 'Role ' . $roleId));
        if ($roleName === '') {
            $roleName = 'Role ' . $roleId;
        }

        $rolesById[$roleId] = [
            'role_id' => $roleId,
            'role_name' => $roleName,
            'role_key' => normalizeRoleKey($roleId, $roleName)
        ];
    }

    $roles = [];
    foreach ($canonicalRoleIds as $roleId) {
        if (isset($rolesById[$roleId])) {
            $roles[] = $rolesById[$roleId];
            continue;
        }
        foreach ($fallback as $fallbackRole) {
            if ((int)$fallbackRole['role_id'] === $roleId) {
                $roles[] = $fallbackRole;
                break;
            }
        }
    }

    return !empty($roles) ? $roles : $fallback;
}

function getRoleLookupById() {
    $roles = getRoleDefinitions();
    $lookup = [];
    foreach ($roles as $role) {
        $lookup[(int)$role['role_id']] = $role;
    }
    return $lookup;
}

/**
 * Create and seed role_module_access table if available.
 */
function ensureRoleModuleAccessTable() {
    global $conn;
    static $initialized = false;
    static $isAvailable = false;

    if ($initialized) {
        return $isAvailable;
    }
    $initialized = true;

    if (!$conn) {
        return false;
    }

    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS role_module_access (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                role_id INT NOT NULL,
                module_key VARCHAR(64) NOT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_by INT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_role_module (role_id, module_key),
                INDEX idx_module_key (module_key),
                INDEX idx_role_id (role_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $isAvailable = true;
    } catch (Throwable $e) {
        // If CREATE TABLE fails due privileges, try using existing table.
        try {
            $conn->query("SELECT 1 FROM role_module_access LIMIT 1");
            $isAvailable = true;
        } catch (Throwable $inner) {
            error_log("role_module_access unavailable: " . $e->getMessage());
            $isAvailable = false;
            return false;
        }
    }

    try {
        $roles = getRoleDefinitions();
        $modules = getModuleRegistry();
        $stmt = $conn->prepare("
            INSERT INTO role_module_access (role_id, module_key, is_enabled, updated_by)
            VALUES (?, ?, ?, NULL)
            ON DUPLICATE KEY UPDATE module_key = module_key
        ");

        foreach ($roles as $role) {
            $roleId = (int)$role['role_id'];
            $roleKey = $role['role_key'] ?? '';
            $defaults = getDefaultModuleAccessByRoleKey($roleKey);

            foreach ($modules as $moduleKey => $_moduleMeta) {
                $defaultEnabled = !empty($defaults[$moduleKey]) ? 1 : 0;
                $stmt->execute([$roleId, $moduleKey, $defaultEnabled]);
            }
        }
    } catch (Throwable $e) {
        error_log("Unable to seed role_module_access defaults: " . $e->getMessage());
    }

    return true;
}

function getRoleIdFromUserData($userData) {
    global $conn;

    $roleId = isset($userData['role_id']) ? (int)$userData['role_id'] : 0;
    if ($roleId > 0) {
        return $roleId;
    }

    $userId = isset($userData['id']) ? (int)$userData['id'] : 0;
    if ($userId > 0 && $conn) {
        try {
            $stmt = $conn->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['role_id'])) {
                $resolvedRoleId = (int)$row['role_id'];
                if ($resolvedRoleId > 0) {
                    return $resolvedRoleId;
                }
            }
        } catch (Throwable $e) {
            error_log("Error resolving user role_id: " . $e->getMessage());
        }
    }

    $roleKey = strtolower(trim((string)($userData['role'] ?? '')));
    if (isSuperAdminRoleKey($roleKey)) {
        return 1;
    }
    if ($roleKey === 'admin') {
        return 2;
    }
    if ($roleKey === 'user') {
        return 7;
    }

    return 0;
}

/**
 * Resolve module access for a specific role and module.
 */
function getRoleModuleAccess($roleId, $roleKey, $moduleKey) {
    global $conn;
    static $cache = [];

    $moduleKey = normalizeModuleKey($moduleKey);
    $modules = getModuleRegistry();
    if (!array_key_exists($moduleKey, $modules)) {
        return false;
    }

    if (isSuperAdminRoleKey($roleKey)) {
        return true;
    }

    $defaults = getDefaultModuleAccessByRoleKey($roleKey);
    $fallback = !empty($defaults[$moduleKey]);

    $roleId = (int)$roleId;
    if ($roleId <= 0 || !$conn || !ensureRoleModuleAccessTable()) {
        return $fallback;
    }

    $cacheKey = $roleId . '|' . $moduleKey;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $conn->prepare("
            SELECT is_enabled
            FROM role_module_access
            WHERE role_id = ? AND module_key = ?
            LIMIT 1
        ");
        $stmt->execute([$roleId, $moduleKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && array_key_exists('is_enabled', $row)) {
            $cache[$cacheKey] = ((int)$row['is_enabled']) === 1;
            return $cache[$cacheKey];
        }
    } catch (Throwable $e) {
        error_log("Error reading role module access: " . $e->getMessage());
    }

    $cache[$cacheKey] = $fallback;
    return $cache[$cacheKey];
}

function userHasModuleAccess($userData, $moduleKey) {
    $moduleKey = normalizeModuleKey($moduleKey);
    $userId = isset($userData['id']) ? (int)$userData['id'] : 0;
    $roleKey = strtolower(trim((string)($userData['role'] ?? 'user')));
    $roleId = getRoleIdFromUserData($userData);

    if (isSuperAdminRoleKey($roleKey)) {
        return true;
    }

    if ($userId > 0) {
        $override = getUserModuleOverrideAccess($userId, $moduleKey);
        if ($override !== null) {
            return $override;
        }
    }

    return getRoleModuleAccess($roleId, $roleKey, $moduleKey);
}

function getRoleModuleAccessMatrix() {
    global $conn;

    $roles = getRoleDefinitions();
    $modules = getModuleRegistry();
    $matrix = [];

    foreach ($roles as $role) {
        $roleId = (int)$role['role_id'];
        $roleKey = $role['role_key'] ?? '';
        $defaults = getDefaultModuleAccessByRoleKey($roleKey);

        $matrix[$roleId] = [];
        foreach ($modules as $moduleKey => $_moduleMeta) {
            $matrix[$roleId][$moduleKey] = !empty($defaults[$moduleKey]);
        }
    }

    if ($conn && ensureRoleModuleAccessTable()) {
        try {
            $stmt = $conn->query("
                SELECT role_id, module_key, is_enabled
                FROM role_module_access
            ");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                $roleId = isset($row['role_id']) ? (int)$row['role_id'] : 0;
                $moduleKey = normalizeModuleKey($row['module_key'] ?? '');
                if ($roleId <= 0 || !array_key_exists($moduleKey, $modules)) {
                    continue;
                }
                if (!isset($matrix[$roleId])) {
                    $matrix[$roleId] = [];
                }
                $matrix[$roleId][$moduleKey] = ((int)($row['is_enabled'] ?? 0) === 1);
            }
        } catch (Throwable $e) {
            error_log("Error loading role module access matrix: " . $e->getMessage());
        }
    }

    $moduleList = [];
    foreach ($modules as $moduleKey => $meta) {
        $moduleList[] = [
            'module_key' => $moduleKey,
            'label' => $meta['label'],
            'description' => $meta['description'],
            'page' => $meta['page'],
            'subsystem' => $meta['subsystem'] ?? ''
        ];
    }

    return [
        'roles' => $roles,
        'modules' => $moduleList,
        'access' => $matrix
    ];
}

function setRoleModuleAccess($roleId, $moduleKey, $isEnabled, $updatedBy = null) {
    global $conn;

    if (!$conn) {
        return false;
    }

    $roleId = (int)$roleId;
    if ($roleId <= 0) {
        return false;
    }

    $moduleKey = normalizeModuleKey($moduleKey);
    $modules = getModuleRegistry();
    if (!array_key_exists($moduleKey, $modules)) {
        return false;
    }

    if (!ensureRoleModuleAccessTable()) {
        return false;
    }

    $roleLookup = getRoleLookupById();
    if (!isset($roleLookup[$roleId])) {
        // Only allow updates for canonical system roles (1, 2, 7).
        return false;
    }
    $roleKey = isset($roleLookup[$roleId]['role_key']) ? $roleLookup[$roleId]['role_key'] : '';

    // Keep super admin full access to avoid locking out administrative recovery.
    $isEnabled = isSuperAdminRoleKey($roleKey) ? 1 : ($isEnabled ? 1 : 0);
    $updatedBy = $updatedBy !== null ? (int)$updatedBy : null;

    try {
        $stmt = $conn->prepare("
            INSERT INTO role_module_access (role_id, module_key, is_enabled, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");
        return $stmt->execute([$roleId, $moduleKey, $isEnabled, $updatedBy]);
    } catch (Throwable $e) {
        error_log("Error saving role module access: " . $e->getMessage());
        return false;
    }
}

function ensureUserModuleAccessTable() {
    global $conn;
    static $initialized = false;
    static $isAvailable = false;

    if ($initialized) {
        return $isAvailable;
    }
    $initialized = true;

    if (!$conn) {
        return false;
    }

    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS user_module_access (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                module_key VARCHAR(64) NOT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                updated_by INT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_user_module (user_id, module_key),
                INDEX idx_user_module_key (module_key),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $isAvailable = true;
    } catch (Throwable $e) {
        try {
            $conn->query("SELECT 1 FROM user_module_access LIMIT 1");
            $isAvailable = true;
        } catch (Throwable $inner) {
            error_log("user_module_access unavailable: " . $e->getMessage());
            $isAvailable = false;
            return false;
        }
    }

    return true;
}

function getManagedUsersForAccess() {
    global $conn;

    if (!$conn) {
        return [];
    }

    $canonicalRoleIds = getCanonicalRoleIds();
    if (empty($canonicalRoleIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($canonicalRoleIds), '?'));

    try {
        $stmt = $conn->prepare("
            SELECT
                u.user_id,
                u.user_name,
                u.role_id,
                r.role AS role_name,
                u.Location AS location,
                u.work_area
            FROM users u
            LEFT JOIN table_role r ON r.role_id = u.role_id
            WHERE u.role_id IN ({$placeholders})
            ORDER BY u.user_name ASC
        ");
        $stmt->execute($canonicalRoleIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Error loading users for module access: " . $e->getMessage());
        return [];
    }

    $users = [];
    foreach ($rows as $row) {
        $roleId = isset($row['role_id']) ? (int)$row['role_id'] : 0;
        if ($roleId <= 0) {
            continue;
        }

        $roleName = trim((string)($row['role_name'] ?? ''));
        $users[] = [
            'user_id' => (int)($row['user_id'] ?? 0),
            'user_name' => (string)($row['user_name'] ?? ''),
            'role_id' => $roleId,
            'role_name' => $roleName !== '' ? $roleName : ('Role ' . $roleId),
            'role_key' => normalizeRoleKey($roleId, $roleName),
            'location' => (string)($row['location'] ?? ''),
            'work_area' => (string)($row['work_area'] ?? '')
        ];
    }

    return $users;
}

function &getUserModuleOverrideCache() {
    static $cache = [];
    return $cache;
}

function getUserModuleOverrideMap($userId) {
    global $conn;
    $cache = &getUserModuleOverrideCache();

    $userId = (int)$userId;
    if ($userId <= 0) {
        return [];
    }

    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $cache[$userId] = [];
    if (!$conn || !ensureUserModuleAccessTable()) {
        return $cache[$userId];
    }

    try {
        $stmt = $conn->prepare("
            SELECT module_key, is_enabled
            FROM user_module_access
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $modules = getModuleRegistry();

        foreach ($rows as $row) {
            $moduleKey = normalizeModuleKey($row['module_key'] ?? '');
            if (!array_key_exists($moduleKey, $modules)) {
                continue;
            }
            $cache[$userId][$moduleKey] = ((int)($row['is_enabled'] ?? 0) === 1);
        }
    } catch (Throwable $e) {
        error_log("Error loading user module override map: " . $e->getMessage());
    }

    return $cache[$userId];
}

function getUserModuleOverrideAccess($userId, $moduleKey) {
    $moduleKey = normalizeModuleKey($moduleKey);
    if ($moduleKey === '') {
        return null;
    }

    $map = getUserModuleOverrideMap($userId);
    if (array_key_exists($moduleKey, $map)) {
        return (bool)$map[$moduleKey];
    }
    return null;
}

function setUserModuleAccess($userId, $moduleKey, $isEnabled, $updatedBy = null) {
    global $conn;
    $cache = &getUserModuleOverrideCache();

    if (!$conn) {
        return false;
    }

    $userId = (int)$userId;
    if ($userId <= 0) {
        return false;
    }

    $moduleKey = normalizeModuleKey($moduleKey);
    $modules = getModuleRegistry();
    if (!array_key_exists($moduleKey, $modules)) {
        return false;
    }

    if (!ensureUserModuleAccessTable()) {
        return false;
    }

    try {
        $userStmt = $conn->prepare("SELECT role_id FROM users WHERE user_id = ? LIMIT 1");
        $userStmt->execute([$userId]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Error loading target user for module access update: " . $e->getMessage());
        return false;
    }

    if (!$userRow || !isset($userRow['role_id'])) {
        return false;
    }

    $roleId = (int)$userRow['role_id'];
    if (!in_array($roleId, getCanonicalRoleIds(), true)) {
        return false;
    }

    $roleKey = normalizeRoleKey($roleId, '');
    // Keep super admin full access to avoid lockout.
    $isEnabled = isSuperAdminRoleKey($roleKey) ? 1 : ($isEnabled ? 1 : 0);
    $updatedBy = $updatedBy !== null ? (int)$updatedBy : null;

    try {
        $stmt = $conn->prepare("
            INSERT INTO user_module_access (user_id, module_key, is_enabled, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");
        $saved = $stmt->execute([$userId, $moduleKey, $isEnabled, $updatedBy]);
        if ($saved && array_key_exists($userId, $cache)) {
            unset($cache[$userId]);
        }
        return $saved;
    } catch (Throwable $e) {
        error_log("Error saving user module access: " . $e->getMessage());
        return false;
    }
}

function getUserModuleAccessMatrix($roleMatrix = null) {
    $modules = getModuleRegistry();
    $users = getManagedUsersForAccess();
    $effective = [];
    $overrides = [];

    if (!is_array($roleMatrix)) {
        $roleMatrixData = getRoleModuleAccessMatrix();
        $roleMatrix = $roleMatrixData['access'] ?? [];
    }

    foreach ($users as $user) {
        $userId = (int)($user['user_id'] ?? 0);
        $roleId = (int)($user['role_id'] ?? 0);
        $roleKey = strtolower(trim((string)($user['role_key'] ?? '')));
        $overrideMap = getUserModuleOverrideMap($userId);

        $effective[$userId] = [];
        $overrides[$userId] = [];

        foreach ($modules as $moduleKey => $_moduleMeta) {
            if (array_key_exists($moduleKey, $overrideMap)) {
                $effective[$userId][$moduleKey] = (bool)$overrideMap[$moduleKey];
                $overrides[$userId][$moduleKey] = (bool)$overrideMap[$moduleKey];
                continue;
            }

            if (isset($roleMatrix[$roleId]) && array_key_exists($moduleKey, $roleMatrix[$roleId])) {
                $effective[$userId][$moduleKey] = (bool)$roleMatrix[$roleId][$moduleKey];
            } else {
                $effective[$userId][$moduleKey] = getRoleModuleAccess($roleId, $roleKey, $moduleKey);
            }
        }
    }

    return [
        'users' => $users,
        'user_access' => $effective,
        'user_overrides' => $overrides
    ];
}

/**
 * Require authentication - returns user data or exits with error
 */
function requireAuth() {
    $user = validateToken();

    if (!$user) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Authentication required'
        ]);
        exit;
    }

    return $user;
}

/**
 * Clean up expired tokens (should be called by cron job)
 */
function cleanupExpiredTokens() {
    global $conn;

    try {
        $conn->beginTransaction();

        // Mark expired access tokens as inactive
        $conn->exec("
            UPDATE access_tokens
            SET is_active = FALSE
            WHERE expires_at < NOW() AND is_active = TRUE
        ");

        // Mark expired refresh tokens as revoked
        $conn->exec("
            UPDATE refresh_tokens
            SET is_revoked = TRUE
            WHERE expires_at < NOW() AND is_revoked = FALSE
        ");

        // Move expired tokens to revoked table for audit trail
        $conn->exec("
            INSERT INTO revoked_tokens (token, token_type, user_id, reason)
            SELECT token, 'access', user_id, 'expired'
            FROM access_tokens
            WHERE expires_at < NOW() AND is_active = FALSE
        ");

        $conn->exec("
            INSERT INTO revoked_tokens (token, token_type, user_id, reason)
            SELECT refresh_token, 'refresh', user_id, 'expired'
            FROM refresh_tokens
            WHERE expires_at < NOW() AND is_revoked = FALSE
        ");

        // Delete old revoked tokens (keep last 30 days)
        $conn->exec("
            DELETE FROM revoked_tokens
            WHERE revoked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");

        $conn->commit();
        error_log("Cleaned up expired tokens");
        return true;

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error cleaning up expired tokens: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user tokens for management (admin function)
 */
function getUserTokens($userId) {
    global $conn;

    try {
        $stmt = $conn->prepare("
            SELECT
                'access' as type,
                id,
                username,
                created_at,
                expires_at,
                last_accessed,
                is_active,
                ip_address,
                user_agent
            FROM access_tokens
            WHERE user_id = ?

            UNION ALL

            SELECT
                'refresh' as type,
                id,
                username,
                created_at,
                expires_at,
                NULL as last_accessed,
                CASE WHEN is_revoked = FALSE AND is_used = FALSE THEN 1 ELSE 0 END as is_active,
                ip_address,
                user_agent
            FROM refresh_tokens
            WHERE user_id = ?

            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll();

    } catch (Exception $e) {
        error_log("Error getting user tokens: " . $e->getMessage());
        return [];
    }
}
?>
