<?php
require_once __DIR__ . '/vendor/autoload.php'; // Autoload Dotenv and other packages

use Dotenv\Dotenv;

// Load .env file from the eBeterinaryo directory
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Access .env variables (optional)
$apiBaseUrl = $_ENV['API_BASE_URL'] ?? '';
