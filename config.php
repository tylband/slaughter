<?php
require_once __DIR__ . '/vendor/autoload.php'; // Autoload dependencies
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
?>
