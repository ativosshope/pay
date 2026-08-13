<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';

setApiHeaders();

try {
    echo json_encode([
        'success' => true, 
        'authenticated' => isAdminAuthenticated()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
