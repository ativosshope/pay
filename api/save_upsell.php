<?php
header('Content-Type: application/json');
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
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $pdo = Database::getConnection();

    if (isset($input['mode'])) {
        $mode = $input['mode'] === 'link' ? 'link' : 'templates';
        setConfigValue('upsell_mode', $mode);
    }
    if (isset($input['link'])) {
        setConfigValue('upsell_link', trim((string)$input['link']));
    }

    if (isset($input['upsell']) && is_array($input['upsell'])) {
        $u = $input['upsell'];
        $id = $u['id'] ?? null;
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($u['slug'] ?? '')));
        if ($slug === '') $slug = 'upsell-' . time();

        $fields = [
            $slug,
            (($u['template_key'] ?? 'pendencia') === 'havan') ? 'pendencia' : ($u['template_key'] ?? 'pendencia'),
            $u['title'] ?? '',
            $u['logo_url'] ?? '',
            $u['status_ok_label'] ?? 'Pagamento concluído',
            $u['status_warn_label'] ?? 'Pendência no seu pedido',
            $u['description'] ?? '',
            $u['problem_title'] ?? '',
            $u['problem_description'] ?? '',
            $u['button_label'] ?? 'Resolver problema',
            $u['pay_button_label'] ?? 'Pagar agora',
            (int)($u['amount_cents'] ?? 0),
            (int)($u['active'] ?? 1),
            (int)($u['sort_order'] ?? 0),
        ];

        if ($id) {
            $stmt = $pdo->prepare("UPDATE upsell_templates SET slug=?, template_key=?, title=?, logo_url=?, status_ok_label=?, status_warn_label=?, description=?, problem_title=?, problem_description=?, button_label=?, pay_button_label=?, amount_cents=?, active=?, sort_order=? WHERE id=?");
            $fields[] = (int)$id;
            $stmt->execute($fields);
        } else {
            $stmt = $pdo->prepare("INSERT INTO upsell_templates (slug, template_key, title, logo_url, status_ok_label, status_warn_label, description, problem_title, problem_description, button_label, pay_button_label, amount_cents, active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute($fields);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(['success' => true, 'id' => (int)$id]);
        exit;
    }

    if (isset($input['reorder']) && is_array($input['reorder'])) {
        foreach ($input['reorder'] as $item) {
            $stmt = $pdo->prepare("UPDATE upsell_templates SET sort_order = ? WHERE id = ?");
            $stmt->execute([(int)$item['sort_order'], (int)$item['id']]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao salvar upsell']);
}
