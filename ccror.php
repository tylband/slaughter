<?php
/**
 * iConnect_Database — upload this one file to CentOS /var/www/html/api/lcr/ccror.php
 * Public: https://sakatamalaybalay.com/api/lcr/ccror.php
 * Connects to 192.168.108.89. Do not upload .env with this file.
 *
 * Target: https://192.168.108.89:4433/elcr/eMIS_Web_Connect/iConnect_Database_Target/ccroAPI.php
 */

if (!defined('DB_API_BASE')) {
    $fromEnv = getenv('CCRO_DB_API_BASE');
    define(
        'DB_API_BASE',
        (is_string($fromEnv) && trim($fromEnv) !== '')
            ? rtrim(trim($fromEnv), '/')
            : 'https://192.168.108.89:4433/elcr/eMIS_Web_Connect/iConnect_Database_Target/ccroAPI.php'
    );
}

if (!defined('DB_LOG_URL')) {
    $logFromEnv = getenv('CCRO_DB_LOG_URL');
    if (is_string($logFromEnv) && trim($logFromEnv) !== '') {
        define('DB_LOG_URL', rtrim(trim($logFromEnv), '/'));
    } else {
        define('DB_LOG_URL', preg_replace('#/ccroAPI\.php$#i', '/log.php', DB_API_BASE) ?: DB_API_BASE);
    }
}

define('SSL_VERIFY_PEER', false);
define('SSL_VERIFY_HOST', false);
define('REQUEST_TIMEOUT', 300);
define('CONNECT_TIMEOUT', 30);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Client-Token');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '0');

function logToDatabaseHost(string $message): void
{
    static $busy = false;
    if ($busy || $message === '') {
        return;
    }
    $busy = true;

    $ch = curl_init(DB_LOG_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'source'  => 'iConnect_Database',
        'message' => $message,
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_HOST ? 2 : 0);
    curl_exec($ch);
    curl_close($ch);

    $busy = false;
}

set_error_handler(static function ($severity, $message, $file, $line) {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    logToDatabaseHost(sprintf('[ccror] PHP: %s in %s:%s', $message, $file, $line));
    return true;
});

register_shutdown_function(static function () {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'], $fatal, true)) {
        return;
    }
    logToDatabaseHost('[ccror] Fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
});

function databaseApiUrl(?string $route = null): string
{
    $route = $route !== null && $route !== ''
        ? $route
        : (string) ($_GET['route'] ?? 'execsql');
    return DB_API_BASE . '?route=' . rawurlencode($route);
}

/**
 * @return array{httpCode:int, response:string}
 */
function ForwardToCCroAPI($jsonBody, $clientToken, $method = 'POST', ?string $route = null)
{
    $url = databaseApiUrl($route);

    $headers = ['Content-Type: application/json'];
    if ($clientToken) {
        $headers[] = 'Client-Token: ' . $clientToken;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_TIMEOUT, REQUEST_TIMEOUT);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, CONNECT_TIMEOUT);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    if ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
    }

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_HOST ? 2 : 0);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);

    if ($curlError) {
        logToDatabaseHost('[ccror] CURL Error: ' . $curlError . ' url=' . $url);
        throw new Exception('Connection to database API failed: ' . $curlError);
    }

    return [
        'httpCode' => $httpCode,
        'response' => is_string($response) ? $response : '',
    ];
}

try {
    header('Content-Type: application/json');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $clientToken = $_SERVER['HTTP_CLIENT_TOKEN'] ?? null;
    $rawBody = file_get_contents('php://input');
    $jsonBody = json_decode($rawBody !== false ? $rawBody : '', true);

    if (isset($_GET['health'])) {
        echo json_encode([
            'status'    => 'healthy',
            'service'   => 'iConnect_Database reverse proxy',
            'backend'   => 'ccroAPI.php',
            'host'      => '192.168.108.89',
            'db_url'    => DB_API_BASE,
            'log_url'   => DB_LOG_URL,
            'version'   => '5.0',
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (isset($_GET['test']) && $_GET['test'] === 'ping') {
        try {
            $pingRoute = ($clientToken !== null && $clientToken !== '') ? 'ping' : 'health';
            $result = ForwardToCCroAPI(null, $clientToken, 'GET', $pingRoute);

            $statusMap = [
                200 => ['status' => 'success', 'message' => 'Connection to database API successful'],
                403 => ['status' => 'error', 'message' => 'Authentication required'],
                401 => ['status' => 'error', 'message' => 'Invalid credentials'],
                405 => ['status' => 'error', 'message' => 'Method not allowed'],
                500 => ['status' => 'error', 'message' => 'Backend server error'],
                503 => ['status' => 'error', 'message' => 'Service unavailable'],
            ];

            $statusInfo = $statusMap[$result['httpCode']] ?? ['status' => 'error', 'message' => 'Unexpected response'];

            echo json_encode([
                'status'            => $statusInfo['status'],
                'message'           => $statusInfo['message'],
                'backend_http_code' => $result['httpCode'],
                'db_url'            => DB_API_BASE,
                'timestamp'         => date('Y-m-d H:i:s'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (Exception $e) {
            logToDatabaseHost('[ccror] ping failed: ' . $e->getMessage());
            http_response_code(503);
            echo json_encode([
                'status'    => 'error',
                'message'   => 'Backend connection failed: ' . $e->getMessage(),
                'db_url'    => DB_API_BASE,
                'timestamp' => date('Y-m-d H:i:s'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'error'   => true,
            'message' => 'Only POST requests are allowed.',
        ]);
        exit;
    }

    if (!$jsonBody || !isset($jsonBody['Database']) || !isset($jsonBody['Sql'])) {
        http_response_code(400);
        echo json_encode([
            'error'   => true,
            'message' => 'Missing required fields. Required: Database, Sql',
        ]);
        exit;
    }

    $route = (string) ($_GET['route'] ?? 'execsql');
    $result = ForwardToCCroAPI($jsonBody, $clientToken, 'POST', $route);

    if (($result['httpCode'] >= 500) || $result['httpCode'] === 0) {
        logToDatabaseHost(sprintf(
            '[ccror] DB API HTTP %d | %s | Database: %s | QueryType: %s',
            $result['httpCode'],
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $jsonBody['Database'] ?? 'unknown',
            $jsonBody['QueryType'] ?? 'unknown'
        ));
    }

    http_response_code($result['httpCode'] > 0 ? $result['httpCode'] : 502);
    echo $result['response'];
} catch (Exception $ex) {
    logToDatabaseHost('[ccror] Exception: ' . $ex->getMessage());
    http_response_code(500);
    echo json_encode([
        'error'   => true,
        'message' => 'Internal server error. Please contact administrator.',
    ], JSON_PRETTY_PRINT);
}
