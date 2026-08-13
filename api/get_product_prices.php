<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/config/database.php';

try {
    $pdo = Database::getConnection();
    
    $stmt = $pdo->query("SELECT slug, old_price, new_price FROM product_prices");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $prices = [];
    foreach ($rows as $row) {
        $prices[$row['slug']] = [
            'oldPrice' => (int) $row['old_price'],
            'newPrice' => (int) $row['new_price'],
        ];
    }
    
    echo json_encode([
        'success' => true,
        'prices' => $prices
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao buscar preços'
    ]);
}
