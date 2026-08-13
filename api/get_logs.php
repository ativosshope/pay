<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $logs = Database::query("SELECT * FROM logs ORDER BY created_at DESC LIMIT 100");

    $logs = array_map(function ($log) {
        return [
            'id' => $log['id'] ?? null,
            'source' => $log['source'] ?? 'unknown',
            'data' => json_decode($log['data'] ?? '[]', true) ?? [],
            'timestamp' => $log['created_at'] ?? null
        ];
    }, $logs);

    echo json_encode([
        'success' => true,
        'total' => count($logs),
        'logs' => $logs
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
