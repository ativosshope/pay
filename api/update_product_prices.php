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

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['products']) || !is_array($input['products'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    exit;
}

try {
    $pdo = Database::getConnection();
    
    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_prices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(255) UNIQUE NOT NULL,
        product_name VARCHAR(500) NOT NULL,
        old_price INT NOT NULL DEFAULT 0,
        new_price INT NOT NULL DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    $stmt = $pdo->prepare(
        "INSERT INTO product_prices (slug, product_name, old_price, new_price) 
         VALUES (:slug, :name, :old_price, :new_price)
         ON DUPLICATE KEY UPDATE 
            old_price = VALUES(old_price), 
            new_price = VALUES(new_price),
            product_name = VALUES(product_name)"
    );
    
    $updated = 0;
    foreach ($input['products'] as $product) {
        if (!isset($product['slug'])) continue;
        
        $stmt->execute([
            ':slug' => $product['slug'],
            ':name' => $product['name'] ?? '',
            ':old_price' => (int) ($product['oldPrice'] ?? 0),
            ':new_price' => (int) ($product['newPrice'] ?? 0),
        ]);
        $updated++;
    }
    
    echo json_encode([
        'success' => true,
        'message' => "$updated produtos atualizados",
        'updated' => $updated
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao atualizar preços: ' . $e->getMessage()
    ]);
}
