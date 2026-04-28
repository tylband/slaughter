<?php
/**
 * hrmis_auth.php — Login / logout for HRMIS admin users.
 *
 * POST ?action=login
 *   body: { username, password }
 *   Auth path 1: tblsysuser   → Username + MD5(password)
 *   Auth path 2: tblpersonalinformation → ID_NUM + CONCAT(birthdate, LEFT(Middlename,1))
 *   Returns: { status, token, user: { id, username, userlevel } }
 *
 * POST ?action=logout
 *   Requires Bearer token (Authorization header or auth_token cookie)
 *   Revokes the current session.
 */

// Suppress PHP error display so errors never corrupt JSON output
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/db_auth.php';   // $conn, storeUserSession(), deleteUserSession()

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Helpers ──────────────────────────────────────────────────────────────────

function auth_ok(array $data = []) { echo json_encode(['status' => 'success'] + $data); exit; }
function auth_fail(string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

if (!$conn) auth_fail('Database connection unavailable', 500);

$action = trim($_GET['action'] ?? '');

$raw  = file_get_contents('php://input');
$body = $raw ? (json_decode($raw, true) ?? []) : [];

// ── Actions ───────────────────────────────────────────────────────────────────

switch ($action) {

    // ── VALIDATE / ME ────────────────────────────────────────────────────────
    case 'validate':
    case 'me':
        $user = validateToken();
        if (!$user) auth_fail('Authentication required', 401);
        auth_ok(['user' => $user]);

    // ── LOGIN ─────────────────────────────────────────────────────────────────
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') auth_fail('POST required');

        $username = trim($body['username'] ?? '');
        $password = trim($body['password'] ?? '');

        if (!$username || !$password) auth_fail('Username and password are required');

        try {
            $uid       = null;
            $uname     = null;
            $userlevel = 1;

            // Step 1 — check if username exists in tblsysuser (no password yet)
            $chk = $conn->prepare("SELECT UID FROM tblsysuser WHERE Username = ?");
            $chk->execute([$username]);
            $sysRow = $chk->fetch();

            if ($sysRow) {
                // Username is a system user — must match MD5 password; no fallback
                $stmt = $conn->prepare(
                    "SELECT UID, Userlevel, Username
                     FROM tblsysuser
                     WHERE Username = ? AND Password = MD5(?)"
                );
                $stmt->execute([$username, $password]);
                $row = $stmt->fetch();

                if (!$row) auth_fail('Invalid username or password', 401);

                $uid       = (int)$row['UID'];
                $uname     = $row['Username'];
                $userlevel = (int)($row['Userlevel'] ?? 1);

            } else {
                // Step 2 — try tblpersonalinformation (ID_NUM + birthdate+initial)
                $stmt2 = $conn->prepare(
                    "SELECT PIID, ID_NUM, Surname
                     FROM tblpersonalinformation
                     WHERE ID_NUM = ?
                       AND CONCAT(birthdate, LEFT(Middlename, 1)) = ?"
                );
                $stmt2->execute([$username, $password]);
                $row2 = $stmt2->fetch();

                if (!$row2) auth_fail('Invalid username or password', 401);

                $uid       = (int)$row2['PIID'];
                $uname     = $row2['ID_NUM'];
                $userlevel = 3;
            }

            $session = storeUserSession($uid, $uname);
            if (!$session) auth_fail('Could not create session. Please try again.', 500);

            auth_ok([
                'token'     => $session['access_token'],
                'expiresIn' => $session['expires_in'],
                'user'      => [
                    'id'        => $uid,
                    'username'  => $uname,
                    'userlevel' => $userlevel,
                ],
            ]);

        } catch (Throwable $e) {
            error_log('hrmis_auth login error: ' . $e->getMessage());
            auth_fail('Login failed: ' . $e->getMessage(), 500);
        }

    // ── LOGOUT ────────────────────────────────────────────────────────────────
    case 'logout':
        $token = null;
        $token = getBearerTokenFromRequest();
        if (!$token) $token = $_COOKIE['auth_token'] ?? null;

        if ($token) deleteUserSession($token);

        auth_ok(['message' => 'Logged out']);

    default:
        auth_fail('Unknown action', 404);
}
