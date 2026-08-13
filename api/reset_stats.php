<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';

setApiHeaders();
requireAdminAuth();

try {
    resetStats();
    
    echo json_encode([
        'success' => true,
        'message' => 'Estatísticas resetadas com sucesso'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
