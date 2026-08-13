<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';

if (!isAdminAuthenticated()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

require_once __DIR__ . '/config/database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? null;
    
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID obrigatório']);
        exit;
    }
    
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("DELETE FROM shipping_options WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao deletar frete']);
}
