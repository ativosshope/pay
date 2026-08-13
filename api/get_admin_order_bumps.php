<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Credentials: true');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';

if (!isAdminAuthenticated()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

require_once __DIR__ . '/config/database.php';

try {
    $pdo = Database::getConnection();
    
    $stmt = $pdo->query("SELECT id, product_slug, product_name, old_price, new_price, active, sort_order FROM order_bumps ORDER BY sort_order ASC, id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'bumps' => $rows]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar order bumps']);
}
