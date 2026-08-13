<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/config/database.php';

try {
    $pdo = Database::getConnection();
    
    $stmt = $pdo->query("SELECT id, product_slug, product_name, old_price, new_price FROM order_bumps WHERE active = 1 ORDER BY sort_order ASC, id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $bumps = array_map(function($row) {
        return [
            'id' => (int) $row['id'],
            'productSlug' => $row['product_slug'],
            'productName' => $row['product_name'],
            'oldPrice' => (int) $row['old_price'],
            'newPrice' => (int) $row['new_price'],
        ];
    }, $rows);
    
    echo json_encode(['success' => true, 'bumps' => $bumps]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar order bumps']);
}
