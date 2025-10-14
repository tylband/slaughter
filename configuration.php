<?php
// Simple .env loader without external dependencies
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && !str_starts_with($line, '#')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!empty($key) && !empty($value)) {
                $_ENV[$key] = $value;
            }
        }
    }
}

// Load .env file from the api directory
loadEnv(__DIR__ . '/.env');

// Access .env variables (optional)
$apiBaseUrl = $_ENV['API_BASE_URL'] ?? '';

