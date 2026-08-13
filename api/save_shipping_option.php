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
    if (!$input) {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
        exit;
    }
    
    $pdo = Database::getConnection();
    
    $id = $input['id'] ?? null;
    $name = $input['name'] ?? '';
    $description = $input['description'] ?? '';
    $price = (int) ($input['price'] ?? 0);
    $isDefault = (int) ($input['is_default'] ?? 0);
    $active = (int) ($input['active'] ?? 1);
    $sortOrder = (int) ($input['sort_order'] ?? 0);
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Nome é obrigatório']);
        exit;
    }
    
    // If setting as default, unset others
    if ($isDefault) {
        $pdo->exec("UPDATE shipping_options SET is_default = 0");
    }
    
    if ($id) {
        $stmt = $pdo->prepare("UPDATE shipping_options SET name = ?, description = ?, price = ?, is_default = ?, active = ?, sort_order = ? WHERE id = ?");
        $stmt->execute([$name, $description, $price, $isDefault, $active, $sortOrder, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO shipping_options (name, description, price, is_default, active, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $description, $price, $isDefault, $active, $sortOrder]);
        $id = $pdo->lastInsertId();
    }
    
    echo json_encode(['success' => true, 'id' => (int) $id]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar frete']);
}
