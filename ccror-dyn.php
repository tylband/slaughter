<?php
/**
 * ccror-dyn.php — upload to CentOS /var/www/html/api/lcr/rp/
 * Public: https://sakatamalaybalay.com/api/lcr/rp/ccror-dyn.php
 * Connects to 192.168.108.89. Do not upload .env with this file.
 *
 * Backend app on this XAMPP host: https://192.168.108.89:4433/elcr/Dynamic_Rest_API
 */

if (!defined('DYNAMIC_API_BASE')) {
    $fromEnv = getenv('CCRO_DYNAMIC_API_BASE');
    define(
        'DYNAMIC_API_BASE',
        (is_string($fromEnv) && trim($fromEnv) !== '')
            ? rtrim(trim($fromEnv), '/')
            : 'https://192.168.108.89:4433/elcr/Dynamic_Rest_API'
    );
}

if (!defined('DB_LOG_URL')) {
    $logFromEnv = getenv('CCRO_DB_LOG_URL');
    define(
        'DB_LOG_URL',
        (is_string($logFromEnv) && trim($logFromEnv) !== '')
            ? rtrim(trim($logFromEnv), '/')
            : 'https://192.168.108.89:4433/elcr/eMIS_Web_Connect/iConnect_Database_Target/log.php'
    );
}

define('SSL_VERIFY_PEER', false);
define('SSL_VERIFY_HOST', false);
define('REQUEST_TIMEOUT', 300);
define('CONNECT_TIMEOUT', 30);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Timestamp, X-Nonce, X-Signature');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(0);
ini_set('log_errors', '0');

function logToDatabaseHost($message)
{
    static $busy = false;
    if ($busy || $message === '' || DB_LOG_URL === '') {
        return;
    }
    $busy = true;

    $ch = curl_init(DB_LOG_URL);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(array(
            'source'  => 'iConnect_Dynamic',
            'message' => $message,
            'ip'      => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
        )),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => SSL_VERIFY_PEER,
        CURLOPT_SSL_VERIFYHOST => SSL_VERIFY_HOST ? 2 : 0,
    ));
    curl_exec($ch);
    curl_close($ch);

    $busy = false;
}

/**
 * Path after /elcr/eMIS_Web_Connect/iConnect_Dynamic — PATH_INFO, ?path=, or rewrite.
 */
function resolveProxyPath()
{
    $pathInfo = isset($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '';
    if ($pathInfo !== '') {
        return '/' . trim($pathInfo, '/');
    }

    if (isset($_GET['path']) && is_string($_GET['path']) && $_GET['path'] !== '') {
        return '/' . trim($_GET['path'], '/');
    }

    $uri = (string) (parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH) ?: '/');
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';

    // /elcr/eMIS_Web_Connect/iConnect_Dynamic/index.php/oauth/token
    if ($script !== '' && strpos($uri, $script) === 0) {
        $rest = substr($uri, strlen($script));
        if ($rest !== false && $rest !== '' && $rest !== '/') {
            return '/' . trim($rest, '/');
        }
    }

    // /elcr/eMIS_Web_Connect/iConnect_Dynamic/oauth/token when DirectoryIndex = index.php
    $baseDir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($baseDir !== '' && $baseDir !== '/' && strpos($uri, $baseDir) === 0) {
        $rest = substr($uri, strlen($baseDir));
        if ($rest !== false && $rest !== '' && $rest !== '/') {
            // skip if rest is just /index.php
            if ($rest !== '/index.php' && strpos($rest, '/index.php') !== 0) {
                return '/' . trim($rest, '/');
            }
        }
    }

    return '/';
}

function backendUrl($proxyPath)
{
    // Always hit index.php — Synology often ignores .htaccess rewrites for /oauth/token
    $base = rtrim(DYNAMIC_API_BASE, '/') . '/index.php';
    $proxyPath = '/' . trim((string) $proxyPath, '/');
    if ($proxyPath === '/oauth/token') {
        return $base . '?route=oauth/token';
    }
    return $base;
}

/**
 * @return array{httpCode:int, response:string, contentType:?string}
 */
function forwardToDynamicApi($method, $proxyPath, $rawBody)
{
    $url = backendUrl($proxyPath);

    $headers = array();
    $forwardNames = array(
        'HTTP_CONTENT_TYPE'  => 'Content-Type',
        'HTTP_X_API_KEY'     => 'X-API-Key',
        'HTTP_AUTHORIZATION' => 'Authorization',
        'HTTP_X_TIMESTAMP'   => 'X-Timestamp',
        'HTTP_X_NONCE'       => 'X-Nonce',
        'HTTP_X_SIGNATURE'   => 'X-Signature',
    );
    foreach ($forwardNames as $serverKey => $headerName) {
        $val = isset($_SERVER[$serverKey]) ? $_SERVER[$serverKey] : null;
        if (is_string($val) && $val !== '') {
            $headers[] = $headerName . ': ' . $val;
        }
    }

    $xff = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
    $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if (is_string($xff) && $xff !== '') {
        $headers[] = 'X-Forwarded-For: ' . $xff . ($remote !== '' ? ', ' . $remote : '');
    } elseif ($remote !== '') {
        $headers[] = 'X-Forwarded-For: ' . $remote;
    }

    $hasContentType = false;
    foreach ($headers as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $hasContentType = true;
            break;
        }
    }
    if (!$hasContentType) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_TIMEOUT        => REQUEST_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => CONNECT_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => SSL_VERIFY_PEER,
        CURLOPT_SSL_VERIFYHOST => SSL_VERIFY_HOST ? 2 : 0,
    ));

    $methodUp = strtoupper($method);
    if ($rawBody !== '' || $methodUp === 'POST' || $methodUp === 'PUT' || $methodUp === 'PATCH') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    }

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $curlError = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);

    if ($curlError) {
        logToDatabaseHost('[iConnect_Dynamic] CURL Error: ' . $curlError . ' url=' . $url);
        throw new RuntimeException('Connection to Dynamic_Rest_API failed: ' . $curlError);
    }

    return array(
        'httpCode'    => $httpCode,
        'response'    => is_string($response) ? $response : '',
        'contentType' => is_string($contentType) ? $contentType : null,
    );
}

try {
    $method = strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET'));
    $rawBody = file_get_contents('php://input');
    $rawBody = $rawBody !== false ? $rawBody : '';
    $proxyPath = resolveProxyPath();

    if (isset($_GET['health'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'status'    => 'healthy',
            'service'   => 'iConnect_Dynamic reverse proxy',
            'tier'      => 2,
            'deploy'    => '/var/www/html/api/lcr/rp',
            'backend'   => '192.168.108.89',
            'target_url'=> DYNAMIC_API_BASE,
            'version'   => '1.1',
            'timestamp' => date('Y-m-d H:i:s'),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (isset($_GET['test']) && $_GET['test'] === 'ping') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $result = forwardToDynamicApi('GET', '/', '');
            $ok = $result['httpCode'] > 0 && $result['httpCode'] < 500;
            echo json_encode(array(
                'status'            => $ok ? 'success' : 'error',
                'message'           => $ok
                    ? 'Connection to Dynamic_Rest_API reachable'
                    : 'Backend returned an error',
                'backend_http_code' => $result['httpCode'],
                'target_url'        => DYNAMIC_API_BASE,
                'timestamp'         => date('Y-m-d H:i:s'),
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (Exception $e) {
            logToDatabaseHost('[iConnect_Dynamic] ping failed: ' . $e->getMessage());
            http_response_code(503);
            echo json_encode(array(
                'status'    => 'error',
                'message'   => 'Backend connection failed: ' . $e->getMessage(),
                'target_url'=> DYNAMIC_API_BASE,
                'timestamp' => date('Y-m-d H:i:s'),
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    // Friendly GET on the base URL (browsers always use GET)
    if ($method === 'GET' && ($proxyPath === '/' || $proxyPath === '')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'status'  => 'up',
            'service' => 'iConnect_Dynamic reverse proxy',
            'tier'    => 2,
            'message' => 'This URL is for POST API calls (Postman). Browser GET cannot run SQL.',
            'try'     => array(
                'health' => 'GET  ?health=1',
                'ping'   => 'GET  ?test=ping',
                'oauth'  => 'POST ?path=oauth/token  (header X-API-Key + body client_secret)',
                'query'  => 'POST this URL (Bearer token + HMAC + JSON body Database/Sql/QueryType)',
            ),
            'target_url'=> DYNAMIC_API_BASE,
            'timestamp' => date('Y-m-d H:i:s'),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'success' => false,
            'error'   => 'Only POST is allowed for API calls. Open ?health=1 or ?test=ping in the browser, or run: php client/test_connection.php',
        ));
        exit;
    }

    $result = forwardToDynamicApi($method, $proxyPath, $rawBody);

    if ($result['httpCode'] >= 500 || $result['httpCode'] === 0) {
        logToDatabaseHost(sprintf(
            '[iConnect_Dynamic] HTTP %d | %s | path=%s',
            $result['httpCode'],
            isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown',
            $proxyPath
        ));
    }

    http_response_code($result['httpCode'] > 0 ? $result['httpCode'] : 502);
    if ($result['contentType']) {
        header('Content-Type: ' . $result['contentType']);
    } else {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo $result['response'];
} catch (Exception $ex) {
    logToDatabaseHost('[iConnect_Dynamic] Exception: ' . $ex->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'success' => false,
        'error'   => 'Internal server error. Please contact administrator.',
    ), JSON_PRETTY_PRINT);
}
