<?php
require_once __DIR__ . '/../vendor/autoload.php'; // Autoload dependencies
use Dotenv\Dotenv;

// Load .env variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

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

// Redis Configuration
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
        // Log error but don't fail - Redis is optional
        error_log('Redis connection failed: ' . $e->getMessage());
        return null;
    }
}

// Initialize Redis connection
$redis = getRedisConnection();
?>

