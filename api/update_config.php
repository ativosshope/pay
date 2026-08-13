<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';

setApiHeaders();
requireAdminAuth();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Dados inválidos');
    }
    
    // Se houver nova senha, usa hash Argon2id
    if (!empty($input['new_password'])) {
        setAdminPasswordHashSecure(trim((string)$input['new_password']));
        unset($input['new_password']);
    }
    
    saveConfig($input);
    
    echo json_encode(['success' => true, 'message' => 'Configurações salvas']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
