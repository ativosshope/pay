<?php
date_default_timezone_set('America/Sao_Paulo');

define('DATA_DIR', __DIR__ . '/../data');
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);

require_once __DIR__ . '/database.php';

// ═══════════════════════════════════════════
// Helper: read/write site_config table
// ═══════════════════════════════════════════

function getConfigValue($key, $default = '') {
    try {
        $row = Database::queryOne("SELECT config_value FROM site_config WHERE config_key = :k", [':k' => $key]);
        return $row ? $row['config_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function setConfigValue($key, $value) {
    try {
        $existing = Database::queryOne("SELECT id FROM site_config WHERE config_key = :k", [':k' => $key]);
        if ($existing) {
            Database::execute("UPDATE site_config SET config_value = :v WHERE config_key = :k", [':v' => (string)$value, ':k' => $key]);
        } else {
            Database::execute("INSERT INTO site_config (config_key, config_value) VALUES (:k, :v)", [':k' => $key, ':v' => (string)$value]);
        }
    } catch (Exception $e) {
        error_log('setConfigValue failed: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════
// Credenciais de Gateway (DB)
// ═══════════════════════════════════════════

function getCredenciais() {
    try {
        $row = Database::queryOne("SELECT * FROM gateway_credentials ORDER BY id LIMIT 1");
        if (!$row) {
            return [
                'gateway_ativo' => 'ghostpay',
                'ghostpay' => ['secret_key' => '', 'company_id' => '', 'ativo' => false],
                'allowpay' => ['api_key' => '', 'route' => '', 'ativo' => false],
                'vulpex' => ['api_key' => '', 'api_secret' => '', 'ativo' => false],
                'mangofy' => ['api_key' => '', 'store_code' => '', 'ativo' => false],
                'magicpay' => ['public_key' => '', 'secret_key' => '', 'ativo' => false],
                'disrupty' => ['public_key' => '', 'private_key' => '', 'audience' => 'seller', 'ativo' => false]
            ];
        }
        return [
            'gateway_ativo' => $row['gateway_ativo'] ?? 'ghostpay',
            'ghostpay' => [
                'secret_key' => $row['ghostpay_secret_key'] ?? '',
                'company_id' => $row['ghostpay_company_id'] ?? '',
                'ativo' => (bool)($row['ghostpay_ativo'] ?? false)
            ],
            'allowpay' => [
                'api_key' => $row['allowpay_api_key'] ?? '',
                'route' => $row['allowpay_route'] ?? '',
                'ativo' => (bool)($row['allowpay_ativo'] ?? false)
            ],
            'vulpex' => [
                'api_key' => $row['vulpex_api_key'] ?? '',
                'api_secret' => $row['vulpex_api_secret'] ?? '',
                'ativo' => (bool)($row['vulpex_ativo'] ?? false)
            ],
            'mangofy' => [
                'api_key' => $row['mangofy_api_key'] ?? '',
                'store_code' => $row['mangofy_store_code'] ?? '',
                'ativo' => (bool)($row['mangofy_ativo'] ?? false)
            ],
            'magicpay' => [
                'public_key' => $row['magicpay_public_key'] ?? '',
                'secret_key' => $row['magicpay_secret_key'] ?? '',
                'ativo' => (bool)($row['magicpay_ativo'] ?? false)
            ],
            'disrupty' => [
                'public_key' => $row['disrupty_public_key'] ?? '',
                'private_key' => $row['disrupty_private_key'] ?? '',
                'audience' => $row['disrupty_audience'] ?? 'seller',
                'ativo' => (bool)($row['disrupty_ativo'] ?? false)
            ]
        ];
    } catch (Exception $e) {
        return [
            'gateway_ativo' => 'ghostpay',
            'ghostpay' => ['secret_key' => '', 'company_id' => '', 'ativo' => false],
            'allowpay' => ['api_key' => '', 'route' => '', 'ativo' => false],
            'vulpex' => ['api_key' => '', 'api_secret' => '', 'ativo' => false],
            'mangofy' => ['api_key' => '', 'store_code' => '', 'ativo' => false],
            'magicpay' => ['public_key' => '', 'secret_key' => '', 'ativo' => false],
                'disrupty' => ['public_key' => '', 'private_key' => '', 'audience' => 'seller', 'ativo' => false]
        ];
    }
}

function saveCredenciais($data) {
    // Garante existência de uma linha e resolve o ID (evita UPDATE ... ORDER BY LIMIT,
    // que pode falhar com prepared statements em algumas versões do MariaDB).
    $row = Database::queryOne("SELECT id FROM gateway_credentials ORDER BY id ASC LIMIT 1");
    if (!$row) {
        Database::execute("INSERT INTO gateway_credentials (gateway_ativo) VALUES ('ghostpay')");
        $row = Database::queryOne("SELECT id FROM gateway_credentials ORDER BY id ASC LIMIT 1");
    }
    if (!$row) {
        throw new Exception('Não foi possível inicializar gateway_credentials');
    }
    $id = (int)$row['id'];

    $updates = [];
    $params = [':id' => $id];

    if (isset($data['gateway_ativo'])) {
        $updates[] = "gateway_ativo = :gateway_ativo";
        $params[':gateway_ativo'] = (string)$data['gateway_ativo'];
    }
    if (isset($data['ghostpay']) && is_array($data['ghostpay'])) {
        $gp = $data['ghostpay'];
        if (array_key_exists('secret_key', $gp)) { $updates[] = "ghostpay_secret_key = :gs"; $params[':gs'] = trim((string)$gp['secret_key']); }
        if (array_key_exists('company_id', $gp)) { $updates[] = "ghostpay_company_id = :gc"; $params[':gc'] = trim((string)$gp['company_id']); }
        if (array_key_exists('ativo', $gp))      { $updates[] = "ghostpay_ativo = :ga";       $params[':ga'] = !empty($gp['ativo']) ? 1 : 0; }
    }
    if (isset($data['allowpay']) && is_array($data['allowpay'])) {
        $ap = $data['allowpay'];
        if (array_key_exists('api_key', $ap)) { $updates[] = "allowpay_api_key = :ak"; $params[':ak'] = trim((string)$ap['api_key']); }
        if (array_key_exists('route', $ap))   { $updates[] = "allowpay_route = :ar";   $params[':ar'] = trim((string)$ap['route']); }
        if (array_key_exists('ativo', $ap))   { $updates[] = "allowpay_ativo = :aa";   $params[':aa'] = !empty($ap['ativo']) ? 1 : 0; }
    }
    if (isset($data['vulpex']) && is_array($data['vulpex'])) {
        $vx = $data['vulpex'];
        $normalized = normalizeVulpexCredentials($vx['api_key'] ?? '', $vx['api_secret'] ?? '');
        if (array_key_exists('api_key', $vx))    { $updates[] = "vulpex_api_key = :vk";    $params[':vk'] = $normalized['api_key']; }
        if (array_key_exists('api_secret', $vx)) { $updates[] = "vulpex_api_secret = :vs"; $params[':vs'] = $normalized['api_secret']; }
        if (array_key_exists('ativo', $vx))      { $updates[] = "vulpex_ativo = :va";      $params[':va'] = !empty($vx['ativo']) ? 1 : 0; }
    }
    if (isset($data['mangofy']) && is_array($data['mangofy'])) {
        $mf = $data['mangofy'];
        if (array_key_exists('api_key', $mf))    { $updates[] = "mangofy_api_key = :mk";    $params[':mk'] = trim((string)$mf['api_key']); }
        if (array_key_exists('store_code', $mf)) { $updates[] = "mangofy_store_code = :ms"; $params[':ms'] = trim((string)$mf['store_code']); }
        if (array_key_exists('ativo', $mf))      { $updates[] = "mangofy_ativo = :ma";      $params[':ma'] = !empty($mf['ativo']) ? 1 : 0; }
    }
    if (isset($data['magicpay']) && is_array($data['magicpay'])) {
        $mp = $data['magicpay'];
        if (array_key_exists('public_key', $mp)) { $updates[] = "magicpay_public_key = :mpk"; $params[':mpk'] = trim((string)$mp['public_key']); }
        if (array_key_exists('secret_key', $mp)) { $updates[] = "magicpay_secret_key = :msk"; $params[':msk'] = trim((string)$mp['secret_key']); }
        if (array_key_exists('ativo', $mp))      { $updates[] = "magicpay_ativo = :mpa";     $params[':mpa'] = !empty($mp['ativo']) ? 1 : 0; }
    }
    if (isset($data['disrupty']) && is_array($data['disrupty'])) {
        $dp = $data['disrupty'];
        if (array_key_exists('public_key', $dp))  { $updates[] = "disrupty_public_key = :dpk";  $params[':dpk'] = trim((string)$dp['public_key']); }
        if (array_key_exists('private_key', $dp)) { $updates[] = "disrupty_private_key = :dsk"; $params[':dsk'] = trim((string)$dp['private_key']); }
        if (array_key_exists('audience', $dp))    { $updates[] = "disrupty_audience = :dau";    $params[':dau'] = (strtolower(trim((string)$dp['audience'])) === 'facilitador') ? 'facilitador' : 'seller'; }
        if (array_key_exists('ativo', $dp))       { $updates[] = "disrupty_ativo = :dat";       $params[':dat'] = !empty($dp['ativo']) ? 1 : 0; }
    }

    if (empty($updates)) {
        return;
    }

    $sql = "UPDATE gateway_credentials SET " . implode(', ', $updates) . " WHERE id = :id";
    try {
        Database::execute($sql, $params);
    } catch (Exception $e) {
        error_log('saveCredenciais failed: ' . $e->getMessage());
        throw new Exception('Falha ao salvar credenciais do gateway: ' . $e->getMessage());
    }
}

function normalizeVulpexCredentials($apiKey, $apiSecret) {
    $apiKey = trim((string)$apiKey);
    $apiSecret = trim((string)$apiSecret);

    $apiKeyLooksLikeSecret = preg_match('/^sk_(live|test)_/i', $apiKey) === 1;
    $apiSecretLooksLikeKey = preg_match('/^pk_(live|test)_/i', $apiSecret) === 1;

    if ($apiKeyLooksLikeSecret && $apiSecretLooksLikeKey) {
        [$apiKey, $apiSecret] = [$apiSecret, $apiKey];
    }

    return [
        'api_key' => $apiKey,
        'api_secret' => $apiSecret
    ];
}

function getVulpexCredentialCandidates($apiKey, $apiSecret) {
    $original = [
        'api_key' => trim((string)$apiKey),
        'api_secret' => trim((string)$apiSecret)
    ];
    $normalized = normalizeVulpexCredentials($apiKey, $apiSecret);
    $swapped = [
        'api_key' => $original['api_secret'],
        'api_secret' => $original['api_key']
    ];

    $candidates = [];
    $seen = [];

    foreach ([$normalized, $original, $swapped] as $candidate) {
        if ($candidate['api_key'] === '' || $candidate['api_secret'] === '') continue;
        $signature = $candidate['api_key'] . '::' . $candidate['api_secret'];
        if (isset($seen[$signature])) continue;
        $seen[$signature] = true;
        $candidates[] = $candidate;
    }

    return $candidates;
}

function maskVulpexCredential($value) {
    $value = trim((string)$value);
    $length = strlen($value);
    if ($length === 0) return 'empty';
    $prefix = substr($value, 0, min(4, $length));
    $suffix = $length > 4 ? substr($value, -4) : $prefix;
    return $prefix . '...' . $suffix . ' (len:' . $length . ')';
}

function getVulpexConfig() {
    $cred = getCredenciais();
    return array_merge($cred['vulpex'], normalizeVulpexCredentials(
        $cred['vulpex']['api_key'] ?? '',
        $cred['vulpex']['api_secret'] ?? ''
    ));
}

function getVulpexBearerToken($apiKey, $apiSecret) {
    $apiKey = trim((string)$apiKey);
    $apiSecret = trim((string)$apiSecret);

    if ($apiKey === '' || $apiSecret === '') {
        throw new Exception('Credenciais da Vulpex ausentes ou inválidas.');
    }

    $cacheSuffix = substr(hash('sha256', $apiKey . ':' . $apiSecret), 0, 16);
    $cacheFile = DATA_DIR . '/.vulpex_token_cache_' . $cacheSuffix . '.json';

    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if ($cache && !empty($cache['token']) && !empty($cache['expires_at'])) {
            if (time() < $cache['expires_at']) {
                return $cache['token'];
            }
        }
    }

    $credentials = base64_encode($apiKey . ':' . $apiSecret);

    $ch = curl_init('https://api.vulpex.com.br/api/auth');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $credentials,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        throw new Exception(
            'Erro de conexão ao autenticar na Vulpex: ' . ($curlError ?: 'Falha na requisição') .
            ' (código curl: ' . $curlErrno . ')'
        );
    }

    if ($httpCode !== 200) {
        throw new Exception(
            'Erro ao autenticar na Vulpex: HTTP ' . $httpCode . ' - ' . $response .
            ' | api_key: ' . maskVulpexCredential($apiKey) .
            ', api_secret: ' . maskVulpexCredential($apiSecret)
        );
    }

    $data = json_decode($response, true);
    if (empty($data['token'])) {
        throw new Exception('Vulpex auth retornou sucesso mas sem token. Resposta: ' . $response);
    }

    $expiresInMs = intval($data['expiresIn'] ?? $data['expires_in'] ?? 1800000);
    $cacheData = [
        'token' => $data['token'],
        'expires_at' => time() + max(60, intval(($expiresInMs / 1000) - 300))
    ];
    file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);

    return $data['token'];
}

function getGatewayAtivo() {
    $cred = getCredenciais();
    return $cred['gateway_ativo'];
}

function getGhostPayConfig() {
    $cred = getCredenciais();
    return $cred['ghostpay'];
}

function getAllowPayConfig() {
    $cred = getCredenciais();
    return $cred['allowpay'];
}

function getMangofyConfig() {
    $cred = getCredenciais();
    return $cred['mangofy'];
}

function getMagicPayConfig() {
    $cred = getCredenciais();
    return $cred['magicpay'];
}

function getDisruptyConfig() {
    $cred = getCredenciais();
    return $cred['disrupty'];
}

/**
 * Host base da API Disrupty conforme a audiência configurada.
 */
function getDisruptyBaseUrl($audience = 'seller') {
    return (strtolower((string)$audience) === 'facilitador')
        ? 'https://api.disruptybr.app'
        : 'https://api-sellers.disruptybr.app';
}

/**
 * Mapeia o status da Disrupty para o status interno do sistema.
 */
function mapDisruptyStatus($status) {
    $s = strtoupper(trim((string)$status));
    switch ($s) {
        case 'PAGO':
        case 'PAID':
        case 'APPROVED':
            return 'paid';
        case 'PENDENTE':
        case 'PENDING':
        case 'EM_PROCESSAMENTO':
        case 'PROCESSING':
            return 'pending';
        case 'ESTORNADO':
        case 'REFUNDED':
        case 'REEMBOLSO_PENDENTE':
            return 'refunded';
        case 'RECUSADO':
        case 'CANCELADO':
        case 'FALHA':
        case 'FAILED':
            return 'failed';
        default:
            return strtolower($s);
    }
}

// ═══════════════════════════════════════════
// Pixels (DB)
// ═══════════════════════════════════════════

function getPixels() {
    try {
        $rows = Database::query("SELECT * FROM pixels ORDER BY id ASC");
        $meta = [];
        $tiktok = [];
        foreach ($rows as $row) {
            $pixel = [
                'id' => (int)$row['id'],
                'pixelId' => $row['pixel_id'],
                'accessToken' => $row['access_token'] ?? '',
                'ativo' => (bool)$row['ativo']
            ];
            if ($row['type'] === 'meta') {
                $meta[] = $pixel;
            } else {
                $tiktok[] = $pixel;
            }
        }
        return ['meta_pixels' => $meta, 'tiktok_pixels' => $tiktok];
    } catch (Exception $e) {
        return ['meta_pixels' => [], 'tiktok_pixels' => []];
    }
}

function savePixels($metaPixels, $tiktokPixels) {
    try {
        Database::execute("DELETE FROM pixels");
        $stmt = Database::getConnection()->prepare(
            "INSERT INTO pixels (type, pixel_id, access_token, ativo) VALUES (?, ?, ?, ?)"
        );
        foreach ($metaPixels as $p) {
            $stmt->execute(['meta', $p['pixelId'] ?? '', $p['accessToken'] ?? '', ($p['ativo'] ?? false) ? 1 : 0]);
        }
        foreach ($tiktokPixels as $p) {
            $stmt->execute(['tiktok', $p['pixelId'] ?? '', $p['accessToken'] ?? '', ($p['ativo'] ?? false) ? 1 : 0]);
        }
    } catch (Exception $e) {
        error_log('savePixels failed: ' . $e->getMessage());
    }
}

function getMetaPixels() {
    return getPixels()['meta_pixels'];
}

function getTiktokPixels() {
    return getPixels()['tiktok_pixels'];
}

// ═══════════════════════════════════════════
// Business Config (DB - site_config table)
// ═══════════════════════════════════════════

function getBusinessConfig() {
    return [
        'pix_expiration_minutes' => (int)getConfigValue('pix_expiration_minutes', '10'),
        'limite_produtos' => (int)getConfigValue('limite_produtos', '99'),
        'exigir_cpf' => (bool)(int)getConfigValue('exigir_cpf', '0'),
        'order_bumps_ativo' => (bool)(int)getConfigValue('order_bumps_ativo', '1'),
        'taxa_protecao' => [
            'ativo' => (bool)(int)getConfigValue('taxa_protecao_ativo', '1'),
            'valor' => (int)getConfigValue('taxa_protecao_valor', '990'),
            'nome' => getConfigValue('taxa_protecao_nome', 'Taxa de Proteção de Entrega')
        ],
        'taxa_cadastro' => [
            'valor' => (int)getConfigValue('taxa_cadastro_valor', '0')
        ],
        'upsell' => [
            'ativo' => (bool)(int)getConfigValue('upsell_ativo', '0'),
            'link' => getConfigValue('upsell_link', ''),
            'delay' => (int)getConfigValue('upsell_delay', '3')
        ]
    ];
}

function saveBusinessConfig($data) {
    if (isset($data['pix_expiration_minutes'])) setConfigValue('pix_expiration_minutes', intval($data['pix_expiration_minutes']));
    if (isset($data['limite_produtos'])) setConfigValue('limite_produtos', intval($data['limite_produtos']));
    if (isset($data['exigir_cpf'])) setConfigValue('exigir_cpf', $data['exigir_cpf'] ? '1' : '0');
    if (isset($data['order_bumps_ativo'])) setConfigValue('order_bumps_ativo', $data['order_bumps_ativo'] ? '1' : '0');
    
    
    if (isset($data['taxa_protecao'])) {
        $tp = $data['taxa_protecao'];
        if (isset($tp['ativo'])) setConfigValue('taxa_protecao_ativo', $tp['ativo'] ? '1' : '0');
        if (isset($tp['valor'])) setConfigValue('taxa_protecao_valor', intval($tp['valor']));
        if (isset($tp['nome'])) setConfigValue('taxa_protecao_nome', (string)$tp['nome']);
    }
    
    if (isset($data['taxa_cadastro'])) {
        $tc = $data['taxa_cadastro'];
        // Ignora valores vazios/nulos para nunca zerar a taxa por engano
        if (isset($tc['valor']) && $tc['valor'] !== '' && $tc['valor'] !== null && is_numeric($tc['valor'])) {
            setConfigValue('taxa_cadastro_valor', intval($tc['valor']));
        }
    }


    if (isset($data['upsell'])) {
        $up = $data['upsell'];
        if (isset($up['ativo'])) setConfigValue('upsell_ativo', $up['ativo'] ? '1' : '0');
        if (isset($up['link'])) setConfigValue('upsell_link', (string)$up['link']);
        if (isset($up['delay'])) setConfigValue('upsell_delay', intval($up['delay']));
    }
}

function getPixExpirationMinutes() {
    return (int)getConfigValue('pix_expiration_minutes', '10');
}

function getExigirCpf() {
    return (bool)(int)getConfigValue('exigir_cpf', '0');
}

function getTaxaProtecao() {
    return [
        'ativo' => (bool)(int)getConfigValue('taxa_protecao_ativo', '1'),
        'valor' => (int)getConfigValue('taxa_protecao_valor', '990'),
        'nome' => getConfigValue('taxa_protecao_nome', 'Taxa de Proteção de Entrega')
    ];
}

// ═══════════════════════════════════════════
// Notificações (DB)
// ═══════════════════════════════════════════

function getNotificationValue($type, $key, $default = '') {
    try {
        $row = Database::queryOne(
            "SELECT config_value FROM notifications WHERE type = :t AND config_key = :k",
            [':t' => $type, ':k' => $key]
        );
        return $row ? $row['config_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function setNotificationValue($type, $key, $value) {
    try {
        $existing = Database::queryOne(
            "SELECT id FROM notifications WHERE type = :t AND config_key = :k",
            [':t' => $type, ':k' => $key]
        );
        if ($existing) {
            Database::execute(
                "UPDATE notifications SET config_value = :v WHERE type = :t AND config_key = :k",
                [':v' => (string)$value, ':t' => $type, ':k' => $key]
            );
        } else {
            Database::execute(
                "INSERT INTO notifications (type, config_key, config_value) VALUES (:t, :k, :v)",
                [':t' => $type, ':k' => $key, ':v' => (string)$value]
            );
        }
    } catch (Exception $e) {
        error_log('setNotificationValue failed: ' . $e->getMessage());
    }
}

function getNotificacoes() {
    $pushcutUrls = getPushcutUrls();
    return [
        'pushcut' => ['urls' => $pushcutUrls],
        'utmify' => getUtmifyConfig()
    ];
}

function saveNotificacoes($pushcut, $utmify) {
    // Save pushcut URLs
    if (isset($pushcut['urls'])) {
        savePushcutUrls($pushcut['urls']);
    }
    // Save utmify
    if (isset($utmify['ativo'])) setNotificationValue('utmify', 'ativo', $utmify['ativo'] ? '1' : '0');
    if (isset($utmify['token'])) setNotificationValue('utmify', 'token', $utmify['token']);
    if (isset($utmify['platform'])) setNotificationValue('utmify', 'platform', $utmify['platform']);
}

function getPushcutUrls() {
    try {
        $rows = Database::query("SELECT * FROM pushcut_urls ORDER BY id ASC");
        return array_map(function($row) {
            return [
                'id' => (int)$row['id'],
                'url' => $row['url'],
                'enabled' => (bool)$row['enabled']
            ];
        }, $rows);
    } catch (Exception $e) {
        return [];
    }
}

function savePushcutUrls($urls) {
    try {
        Database::execute("DELETE FROM pushcut_urls");
        $stmt = Database::getConnection()->prepare(
            "INSERT INTO pushcut_urls (url, enabled) VALUES (?, ?)"
        );
        foreach ($urls as $u) {
            if (!empty($u['url'])) {
                $stmt->execute([$u['url'], ($u['enabled'] ?? true) ? 1 : 0]);
            }
        }
    } catch (Exception $e) {
        error_log('savePushcutUrls failed: ' . $e->getMessage());
    }
}

function getUtmifyConfig() {
    return [
        'ativo' => (bool)(int)getNotificationValue('utmify', 'ativo', '0'),
        'token' => getNotificationValue('utmify', 'token', ''),
        'platform' => getNotificationValue('utmify', 'platform', 'NikeShop')
    ];
}

// ═══════════════════════════════════════════
// Estatísticas (DB)
// ═══════════════════════════════════════════

function getEstatisticas() {
    try {
        $rows = Database::query("SELECT stat_key, stat_value FROM statistics");
        $stats = ['pix_gerados' => 0, 'pix_pagos' => 0, 'valor_total' => 0];
        foreach ($rows as $row) {
            if (array_key_exists($row['stat_key'], $stats)) {
                $stats[$row['stat_key']] = $row['stat_key'] === 'valor_total' 
                    ? floatval($row['stat_value']) 
                    : intval($row['stat_value']);
            }
        }
        return $stats;
    } catch (Exception $e) {
        return ['pix_gerados' => 0, 'pix_pagos' => 0, 'valor_total' => 0];
    }
}

function incrementStats($tipo, $valor = 0) {
    try {
        if ($tipo === 'gerado') {
            Database::execute("UPDATE statistics SET stat_value = stat_value + 1 WHERE stat_key = 'pix_gerados'");
        }
        if ($tipo === 'pago') {
            Database::execute("UPDATE statistics SET stat_value = stat_value + 1 WHERE stat_key = 'pix_pagos'");
            Database::execute("UPDATE statistics SET stat_value = stat_value + :v WHERE stat_key = 'valor_total'", [':v' => $valor]);
        }
    } catch (Exception $e) {
        error_log('incrementStats failed: ' . $e->getMessage());
    }
}

function resetStats() {
    try {
        Database::execute("UPDATE statistics SET stat_value = 0");
    } catch (Exception $e) {
        error_log('resetStats failed: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════
// Fretes (DB only - no JSON fallback)
// ═══════════════════════════════════════════

function getFretes() {
    try {
        $rows = Database::query("SELECT * FROM shipping_options WHERE active = 1 ORDER BY sort_order ASC, id ASC");
        return array_map(function($row) {
            return [
                'id' => 'shipping-' . $row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'price' => (int)$row['price'],
                'isDefault' => (bool)$row['is_default'],
                'ativo' => true
            ];
        }, $rows);
    } catch (Exception $e) {
        return [];
    }
}

// ═══════════════════════════════════════════
// Aggregated Config (get/save all)
// ═══════════════════════════════════════════

function getConfig() {
    $cred = getCredenciais();
    $pixels = getPixels();
    $business = getBusinessConfig();
    $notif = getNotificacoes();
    $stats = getEstatisticas();
    $fretes = getFretes();
    
    return [
        'gateway_ativo' => $cred['gateway_ativo'],
        'ghostpay' => $cred['ghostpay'],
        'allowpay' => $cred['allowpay'],
        'vulpex' => $cred['vulpex'],
        'mangofy' => $cred['mangofy'],
        'magicpay' => $cred['magicpay'],
        'disrupty' => $cred['disrupty'],
        'meta_pixels' => $pixels['meta_pixels'],
        'tiktok_pixels' => $pixels['tiktok_pixels'],
        'pix_expiration_minutes' => $business['pix_expiration_minutes'],
        'limite_produtos' => $business['limite_produtos'],
        'exigir_cpf' => $business['exigir_cpf'],
        'order_bumps_ativo' => $business['order_bumps_ativo'],
        'taxa_protecao' => $business['taxa_protecao'],
        'taxa_cadastro' => $business['taxa_cadastro'],
        'upsell' => $business['upsell'],


        'pushcut' => $notif['pushcut'],
        'utmify' => $notif['utmify'],
        'fretes' => $fretes,
        'estatisticas' => $stats
    ];
}

function saveConfig($config) {
    if (isset($config['gateway_ativo']) || isset($config['ghostpay']) || isset($config['allowpay']) || isset($config['vulpex']) || isset($config['mangofy']) || isset($config['magicpay']) || isset($config['disrupty'])) {
        saveCredenciais($config);
    }
    
    if (isset($config['meta_pixels']) || isset($config['tiktok_pixels'])) {
        savePixels($config['meta_pixels'] ?? getMetaPixels(), $config['tiktok_pixels'] ?? getTiktokPixels());
    }
    
    if (isset($config['pix_expiration_minutes']) || isset($config['limite_produtos']) || isset($config['exigir_cpf']) || isset($config['order_bumps_ativo']) || isset($config['taxa_protecao']) || isset($config['taxa_cadastro']) || isset($config['upsell'])) {
        saveBusinessConfig($config);
    }
    
    if (isset($config['pushcut']) || isset($config['utmify'])) {
        $notif = getNotificacoes();
        saveNotificacoes(
            $config['pushcut'] ?? $notif['pushcut'],
            $config['utmify'] ?? $notif['utmify']
        );
    }
}

// ═══════════════════════════════════════════
// API Headers & Auth (legacy compat)
// ═══════════════════════════════════════════

function setApiHeaders() {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Admin-Password, Authorization');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

function ensureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.gc_maxlifetime', 3600);
        session_set_cookie_params([
            'lifetime' => 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
}

function getRequestHeaderValue($headerName) {
    $target = strtolower($headerName);

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strtolower((string)$k) === $target) {
                    return is_string($v) ? $v : '';
                }
            }
        }
    }

    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
    if (!empty($_SERVER[$serverKey])) return $_SERVER[$serverKey];

    $redirectKey = 'REDIRECT_' . $serverKey;
    if (!empty($_SERVER[$redirectKey])) return $_SERVER[$redirectKey];

    return '';
}

function checkAdminAuth() {
    ensureSession();

    if (!empty($_SESSION['admin_auth']) && $_SESSION['admin_auth'] === true) {
        return;
    }

    $password = getRequestHeaderValue('X-Admin-Password');

    if (empty($password)) {
        $auth = getRequestHeaderValue('Authorization');
        if (is_string($auth) && stripos($auth, 'bearer ') === 0) {
            $password = trim(substr($auth, 7));
        }
    }

    $password = is_string($password) ? trim($password) : '';

    // Use DB-based auth
    require_once __DIR__ . '/auth.php';
    if (!verifyAdminPasswordSecure($password)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Senha inválida']);
        exit;
    }
}

function getTaxaCadastroValor() {
    return (int)getConfigValue('taxa_cadastro_valor', '0');
}
