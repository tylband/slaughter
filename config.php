<?php
require_once __DIR__ . '/../vendor/autoload.php'; // Autoload dependencies
use Dotenv\Dotenv;

// Load root .env file first for database configuration
$rootDotenv = Dotenv::createImmutable(__DIR__ . '/..');
$rootDotenv->load();

// Determine which .env file to load based on the calling script's location
$callingScript = $_SERVER['SCRIPT_FILENAME'] ?? '';
$scriptDir = dirname($callingScript);

// Check if the calling script is in the system folder
if (strpos($scriptDir, __DIR__ . '/../system') === 0 || strpos($callingScript, 'system/') !== false) {
    // Load .env from system folder for system PHP files (to override root settings if needed)
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../system');
    $dotenv->load();
} elseif (file_exists(__DIR__ . '/.env')) {
    // Load .env from api folder for API files (to override root settings if needed)
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

$host     = $_ENV['DB_HOST'];
$dbname   = $_ENV['DB_NAME'];
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASS'];

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    // Only output error if this file is called directly, not when included
    if (basename($_SERVER['SCRIPT_FILENAME']) === 'config.php') {
        echo json_encode(['error' => $e->getMessage()]);
    } else {
        // For included files, throw exception instead of outputting
        throw $e;
    }
    exit;
}

// Redis Configuration (mandatory for unified config)
$redis_host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
$redis_port = $_ENV['REDIS_PORT'] ?? 6379;
$redis_password = $_ENV['REDIS_PASSWORD'] ?? null;
$redis_database = $_ENV['REDIS_DATABASE'] ?? 0;

/**
 * Get Redis connection instance
 */
function getRedisConnection() {
    global $redis_host, $redis_port, $redis_password, $redis_database;

    try {
        $redis = new Predis\Client([
            'scheme' => 'tcp',
            'host'   => $redis_host,
            'port'   => $redis_port,
            'password' => $redis_password,
            'database' => $redis_database,
        ]);

        // Test connection
        $redis->ping();
        return $redis;
    } catch (Exception $e) {
        // Redis is mandatory for unified config - throw exception instead of returning null
        throw new RedisException('Redis server is not available. Connection failed: ' . $e->getMessage());
    }
}

// Initialize Redis connection (mandatory)
$redis = getRedisConnection();
?>

