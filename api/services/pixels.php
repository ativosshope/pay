<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/storage.php';
require_once __DIR__ . '/random_user.php';

function trackMetaConversion($transacao, $eventName = 'Purchase') {
    $pixels = getMetaPixels();
    $activePixels = array_filter($pixels, fn($p) => ($p['ativo'] ?? false) && !empty($p['pixelId']) && !empty($p['accessToken']));
    
    if (empty($activePixels)) {
        return ['success' => false, 'message' => 'Nenhum pixel Meta ativo'];
    }
    
    $randomUser = getRandomBrazilianUser();
    
    $eventTime = time();
    $eventId = 'evt_' . uniqid();
    
    $userData = [
        'em' => [hash('sha256', strtolower($randomUser['email']))],
        'ph' => [hash('sha256', preg_replace('/\D/', '', $randomUser['phone']))],
        'fn' => [hash('sha256', strtolower($randomUser['firstName']))],
        'ln' => [hash('sha256', strtolower($randomUser['lastName']))],
        'ct' => [hash('sha256', strtolower($randomUser['city']))],
        'st' => [hash('sha256', strtolower($randomUser['state']))],
        'zp' => [hash('sha256', $randomUser['postcode'])],
        'country' => [hash('sha256', 'br')],
        'client_ip_address' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
        'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];
    
    if (!empty($_COOKIE['_fbc'])) $userData['fbc'] = $_COOKIE['_fbc'];
    if (!empty($_COOKIE['_fbp'])) $userData['fbp'] = $_COOKIE['_fbp'];
    
    $customData = [
        'currency' => 'BRL',
        'value' => floatval($transacao['valor']),
        'content_type' => 'product',
        'content_name' => $transacao['product_name'] ?? 'Produto',
        'order_id' => $transacao['order_id'],
        'contents' => [[
            'id' => 'product_' . $transacao['order_id'],
            'quantity' => 1,
            'item_price' => floatval($transacao['valor'])
        ]]
    ];
    
    $eventData = [
        'event_name' => $eventName,
        'event_time' => $eventTime,
        'event_id' => $eventId,
        'event_source_url' => $_SERVER['HTTP_REFERER'] ?? '',
        'action_source' => 'website',
        'user_data' => $userData,
        'custom_data' => $customData
    ];
    
    $results = [];
    
    foreach ($activePixels as $pixel) {
        $url = "https://graph.facebook.com/v18.0/{$pixel['pixelId']}/events?access_token={$pixel['accessToken']}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['data' => [$eventData]]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $results[] = [
            'pixel_id' => $pixel['pixelId'],
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode
        ];
    }
    
    JsonStorage::log('meta_capi', [
        'event' => $eventName,
        'order_id' => $transacao['order_id'],
        'results' => $results
    ]);
    
    return ['success' => true, 'results' => $results];
}

function trackTiktokEvent($transacao, $eventName = 'CompletePayment') {
    $pixels = getTiktokPixels();
    $activePixels = array_filter($pixels, fn($p) => ($p['ativo'] ?? false) && !empty($p['pixelId']) && !empty($p['accessToken']));
    
    if (empty($activePixels)) {
        return ['success' => false, 'message' => 'Nenhum pixel TikTok ativo'];
    }
    
    $randomUser = getRandomBrazilianUser();
    
    $eventData = [
        'event' => $eventName,
        'event_id' => 'tik_' . uniqid(),
        'timestamp' => date('c'),
        'context' => [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''
        ],
        'properties' => [
            'currency' => 'BRL',
            'value' => floatval($transacao['valor']),
            'content_type' => 'product',
            'order_id' => $transacao['order_id'],
            'contents' => [[
                'content_id' => 'product_' . $transacao['order_id'],
                'content_name' => $transacao['product_name'] ?? 'Produto',
                'quantity' => 1,
                'price' => floatval($transacao['valor'])
            ]]
        ],
        'user' => [
            'email' => hash('sha256', strtolower($randomUser['email'])),
            'phone' => hash('sha256', preg_replace('/\D/', '', $randomUser['phone']))
        ]
    ];
    
    if (!empty($_COOKIE['_ttp'])) {
        $eventData['context']['ttp'] = $_COOKIE['_ttp'];
    }
    
    $results = [];
    
    foreach ($activePixels as $pixel) {
        $payload = [
            'pixel_code' => $pixel['pixelId'],
            'event' => $eventName,
            'event_id' => $eventData['event_id'],
            'timestamp' => $eventData['timestamp'],
            'context' => $eventData['context'],
            'properties' => $eventData['properties'],
            'user' => $eventData['user']
        ];
        
        $ch = curl_init('https://business-api.tiktok.com/open_api/v1.3/event/track/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Access-Token: ' . $pixel['accessToken']
            ],
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        $success = $httpCode === 200 && ($responseData['code'] ?? -1) === 0;
        
        $results[] = [
            'pixel_id' => $pixel['pixelId'],
            'success' => $success,
            'http_code' => $httpCode
        ];
    }
    
    JsonStorage::log('tiktok_events', [
        'event' => $eventName,
        'order_id' => $transacao['order_id'],
        'results' => $results
    ]);
    
    return ['success' => true, 'results' => $results];
}

function trackPageView($transacao) {
    trackMetaConversion($transacao, 'PageView');
    trackTiktokEvent($transacao, 'ViewContent');
}

function trackInitiateCheckout($transacao) {
    trackMetaConversion($transacao, 'InitiateCheckout');
    trackTiktokEvent($transacao, 'InitiateCheckout');
}

function trackPurchase($transacao) {
    trackMetaConversion($transacao, 'Purchase');
    trackTiktokEvent($transacao, 'CompletePayment');
    trackTiktokEvent($transacao, 'PlaceAnOrder');
}