<?php
declare(strict_types=1);
require_once __DIR__ . '/scoreboard_storage.php';

function authTables(): void
{
    $pdo = scoreboardDb();
    $pdo->exec('CREATE TABLE IF NOT EXISTS tbl_dashboard_users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, full_name VARCHAR(120) NOT NULL DEFAULT "", role VARCHAR(30) NOT NULL DEFAULT "manager", is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tbl_dashboard_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, last_used_at DATETIME NULL, INDEX idx_dashboard_token_user(user_id), INDEX idx_dashboard_token_expiry(expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8');
}

function authCreateToken(array $user): string
{
    authTables();
    $token = bin2hex(random_bytes(32));
    $q = scoreboardDb()->prepare('INSERT INTO tbl_dashboard_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 8 HOUR))');
    $q->execute([(int) $user['id'], hash('sha256', $token)]);

    return $token;
}

function authLocalLogin(string $username, string $password): array
{
    authTables();
    $q = scoreboardDb()->prepare('SELECT id,username,full_name,role,password_hash FROM tbl_dashboard_users WHERE username=? AND is_active=1 LIMIT 1');
    $q->execute([$username]);
    $user = $q->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    return [
        'success' => true,
        'user' => ['id' => $user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role']],
        'token' => authCreateToken($user),
    ];
}

function authTokenUser(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    authTables();
    $pdo = scoreboardDb();
    $q = $pdo->prepare('SELECT u.id,u.username,u.full_name,u.role FROM tbl_dashboard_tokens t INNER JOIN tbl_dashboard_users u ON u.id=t.user_id WHERE t.token_hash=? AND t.expires_at>NOW() AND u.is_active=1 LIMIT 1');
    $hash = hash('sha256', $token);
    $q->execute([$hash]);
    $user = $q->fetch();
    if ($user) {
        $pdo->prepare('UPDATE tbl_dashboard_tokens SET last_used_at=NOW() WHERE token_hash=?')->execute([$hash]);
    }

    return $user ?: null;
}

function authRevokeToken(string $token): void
{
    if ($token === '') {
        return;
    }
    authTables();
    scoreboardDb()->prepare('DELETE FROM tbl_dashboard_tokens WHERE token_hash=?')->execute([hash('sha256', $token)]);
}
