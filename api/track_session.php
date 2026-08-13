<?php
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config/database.php';

try {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    $sessionId = substr(preg_replace('/[^a-zA-Z0-9-]/', '', $body['session_id'] ?? ''), 0, 64);
    $route = substr($body['route'] ?? '/', 0, 500);
    $referrer = substr($body['referrer'] ?? '', 0, 1000);
    if (!$sessionId) { echo json_encode(['success' => false]); exit; }

    // Get real IP behind proxy
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
    $ip = substr($ip, 0, 45);
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    $pdo = Database::getConnection();

    // Geolocate (cache same IP within 24h to avoid hammering ip-api)
    $geo = ['country' => '', 'country_code' => '', 'region' => '', 'city' => '', 'lat' => null, 'lng' => null];
    if ($ip && !in_array($ip, ['127.0.0.1', '::1'], true)) {
        $cached = $pdo->prepare("SELECT country, country_code, region, city, lat, lng FROM sessions
            WHERE ip = ? AND lat IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
            ORDER BY id DESC LIMIT 1");
        $cached->execute([$ip]);
        $row = $cached->fetch();
        if ($row) {
            $geo = $row;
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
            $resp = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,regionName,city,lat,lon", false, $ctx);
            if ($resp) {
                $d = json_decode($resp, true);
                if (($d['status'] ?? '') === 'success') {
                    $geo = [
                        'country' => substr($d['country'] ?? '', 0, 100),
                        'country_code' => substr($d['countryCode'] ?? '', 0, 4),
                        'region' => substr($d['regionName'] ?? '', 0, 100),
                        'city' => substr($d['city'] ?? '', 0, 100),
                        'lat' => $d['lat'] ?? null,
                        'lng' => $d['lon'] ?? null,
                    ];
                }
            }
        }
    }

    $stmt = $pdo->prepare("INSERT INTO sessions
        (session_id, ip, user_agent, route, referrer, country, country_code, region, city, lat, lng)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $sessionId, $ip, $ua, $route, $referrer,
        $geo['country'], $geo['country_code'], $geo['region'], $geo['city'], $geo['lat'], $geo['lng']
    ]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    echo json_encode(['success' => false]);
}
