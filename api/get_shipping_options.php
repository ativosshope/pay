<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/config/database.php';

try {
    $pdo = Database::getConnection();
    
    $stmt = $pdo->query("SELECT id, name, description, price, is_default FROM shipping_options WHERE active = 1 ORDER BY sort_order ASC, id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $options = array_map(function($row) {
        return [
            'id' => 'shipping-' . $row['id'],
            'dbId' => (int) $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'price' => (int) $row['price'],
            'isDefault' => (bool) $row['is_default'],
        ];
    }, $rows);
    
    echo json_encode(['success' => true, 'options' => $options]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar fretes']);
}
