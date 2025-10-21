<?php
// Try to load composer autoloader, but don't fail if it's not available
$composer_autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

// Check if Dotenv class exists, if not define a simple alternative
if (!class_exists('Dotenv')) {
    class Dotenv {
        private $env_file;

        public function __construct($env_file) {
            $this->env_file = $env_file;
        }

        public static function createImmutable($env_file) {
            return new self($env_file);
        }

        public function load() {
            if (file_exists($this->env_file . '/.env')) {
                $lines = file($this->env_file . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos($line, '=') !== false && !strpos($line, '#')) {
                        list($key, $value) = explode('=', $line, 2);
                        $key = trim($key);
                        $value = trim($value);
                        if (!empty($key)) {
                            $_ENV[$key] = $value;
                            putenv("$key=$value");
                        }
                    }
                }
            }
        }
    }
}

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

