<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';

setApiHeaders();
requireAdminAuth();

try {
    $config = getConfig();
    
    // Remove senha do response
    unset($config['admin_password']);
    
    echo json_encode([
        'success' => true,
        'config' => $config
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
