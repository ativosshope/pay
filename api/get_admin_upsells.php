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
require_once __DIR__ . '/config/upsell_seed.php';

try {
    $pdo = Database::getConnection();
    ensureUpsellSeed($pdo);
    $stmt = $pdo->query("SELECT * FROM upsell_templates ORDER BY sort_order ASC, id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mode = getConfigValue('upsell_mode', 'templates');
    $link = getConfigValue('upsell_link', '');

    echo json_encode([
        'success' => true,
        'mode' => $mode,
        'link' => $link,
        'upsells' => $rows,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar upsells']);
}
