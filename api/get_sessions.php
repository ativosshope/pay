<?php
error_reporting(0);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';

setApiHeaders();
requireAdminAuth();

try {
    $pdo = Database::getConnection();
    $minutes = max(1, min(1440, (int)($_GET['minutes'] ?? 60)));

    // Recent sessions (with geo)
    $stmt = $pdo->prepare("SELECT id, session_id, ip, route, country, country_code, region, city, lat, lng, created_at
        FROM sessions
        WHERE created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ORDER BY id DESC LIMIT 500");
    $stmt->execute([$minutes]);
    $recent = $stmt->fetchAll();

    // Stats: live now (last 1 min), last 5 min, today, total
    $live1 = (int)$pdo->query("SELECT COUNT(DISTINCT session_id) c FROM sessions WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)")->fetch()['c'];
    $live5 = (int)$pdo->query("SELECT COUNT(DISTINCT session_id) c FROM sessions WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetch()['c'];
    $today = (int)$pdo->query("SELECT COUNT(DISTINCT session_id) c FROM sessions WHERE DATE(created_at) = CURDATE()")->fetch()['c'];
    $totalHits = (int)$pdo->query("SELECT COUNT(*) c FROM sessions")->fetch()['c'];
    $totalUnique = (int)$pdo->query("SELECT COUNT(DISTINCT ip) c FROM sessions WHERE ip <> ''")->fetch()['c'];

    // Top routes (last 24h)
    $topRoutes = $pdo->query("SELECT route, COUNT(*) hits, COUNT(DISTINCT session_id) uniques
        FROM sessions WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY route ORDER BY hits DESC LIMIT 12")->fetchAll();

    // Top countries (last 24h)
    $topCountries = $pdo->query("SELECT country, country_code, COUNT(DISTINCT session_id) uniques
        FROM sessions WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) AND country <> ''
        GROUP BY country_code ORDER BY uniques DESC LIMIT 10")->fetchAll();

    // Hits per hour (last 24h)
    $perHour = $pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') hour,
        COUNT(*) hits, COUNT(DISTINCT session_id) uniques
        FROM sessions WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY hour ORDER BY hour ASC")->fetchAll();

    echo json_encode([
        'success' => true,
        'recent' => $recent,
        'stats' => [
            'live_1min' => $live1,
            'live_5min' => $live5,
            'today' => $today,
            'total_hits' => $totalHits,
            'total_unique' => $totalUnique,
            'countries' => count($topCountries),
        ],
        'top_routes' => $topRoutes,
        'top_countries' => $topCountries,
        'per_hour' => $perHour,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao carregar analytics']);
}
