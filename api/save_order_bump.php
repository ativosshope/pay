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
    $productSlug = $input['product_slug'] ?? '';
    $productName = $input['product_name'] ?? '';
    $oldPrice = (int) ($input['old_price'] ?? 0);
    $newPrice = (int) ($input['new_price'] ?? 0);
    $active = (int) ($input['active'] ?? 1);
    $sortOrder = (int) ($input['sort_order'] ?? 0);
    
    if (empty($productSlug) || empty($productName)) {
        echo json_encode(['success' => false, 'error' => 'Slug e nome são obrigatórios']);
        exit;
    }
    
    if ($id) {
        $stmt = $pdo->prepare("UPDATE order_bumps SET product_slug = ?, product_name = ?, old_price = ?, new_price = ?, active = ?, sort_order = ? WHERE id = ?");
        $stmt->execute([$productSlug, $productName, $oldPrice, $newPrice, $active, $sortOrder, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO order_bumps (product_slug, product_name, old_price, new_price, active, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$productSlug, $productName, $oldPrice, $newPrice, $active, $sortOrder]);
        $id = $pdo->lastInsertId();
    }
    
    echo json_encode(['success' => true, 'id' => (int) $id]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar order bump']);
}
