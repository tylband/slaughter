<?php
declare(strict_types=1);

require_once __DIR__ . '/scoreboard_storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, max-age=0');

try {
    echo json_encode(scoreboardData(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load scoreboard data.']);
}
