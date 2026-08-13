<?php
// Public endpoint — list active upsells (ordered)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/upsell_seed.php';

try {
    $slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
    $preview = isset($_GET['preview']) ? trim($_GET['preview']) : '';
    $idParam = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $pdo = Database::getConnection();
    ensureUpsellSeed($pdo);

    // Extract numeric ID from preview token like "pendencia-12" / "jadlog-3"
    $previewId = 0;
    if ($preview !== '' && preg_match('/-(\d+)(?:-|$)/', $preview, $m)) {
        $previewId = (int)$m[1];
    }
    $targetId = $idParam ?: $previewId;

    if ($slug !== '' || $targetId > 0) {
        if ($targetId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM upsell_templates WHERE id = ? LIMIT 1");
            $stmt->execute([$targetId]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM upsell_templates WHERE slug = ? AND active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1");
            $stmt->execute([$slug]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Upsell não encontrado']);
            exit;
        }
        echo json_encode(['success' => true, 'upsell' => formatUpsell($row)]);
        exit;
    }

    $stmt = $pdo->query("SELECT * FROM upsell_templates WHERE active = 1 ORDER BY sort_order ASC, id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $list = array_map('formatUpsell', $rows);

    $mode = getConfigValue('upsell_mode', 'templates');

    echo json_encode([
        'success' => true,
        'mode' => $mode,
        'upsells' => $list,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erro ao carregar upsells']);
}

function formatUpsell($row) {
    return [
        'id' => (int)$row['id'],
        'slug' => $row['slug'],
        'templateKey' => (($row['template_key'] ?? 'pendencia') === 'havan') ? 'pendencia' : ($row['template_key'] ?? 'pendencia'),
        'title' => $row['title'],
        'logoUrl' => $row['logo_url'],
        'statusOkLabel' => $row['status_ok_label'],
        'statusWarnLabel' => $row['status_warn_label'],
        'description' => $row['description'],
        'problemTitle' => $row['problem_title'],
        'problemDescription' => $row['problem_description'],
        'buttonLabel' => $row['button_label'],
        'payButtonLabel' => $row['pay_button_label'],
        'amountCents' => (int)$row['amount_cents'],
        'sortOrder' => (int)$row['sort_order'],
    ];
}
