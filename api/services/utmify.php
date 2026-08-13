<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/storage.php';

/**
 * Envia pedido para a API da Utmify
 * Endpoint: https://api.utmify.com.br/api-credentials/orders
 */
function enviarUtmify($orderId, $paymentMethod, $status, $createdAt, $approvedDate, $refundedAt, $customer, $products, $trackingParams, $commission) {
    $utmifyConfig = getUtmifyConfig();
    
    if (!$utmifyConfig['ativo'] || empty($utmifyConfig['token'])) {
        JsonStorage::log('utmify_skip', ['reason' => 'not_configured', 'order_id' => $orderId]);
        return ['success' => false, 'message' => 'Utmify não configurado'];
    }
    
    // Formatar datas para UTC no formato YYYY-MM-DD HH:MM:SS
    $createdAtFormatted = formatUtmifyDate($createdAt);
    $approvedDateFormatted = $approvedDate ? formatUtmifyDate($approvedDate) : null;
    $refundedAtFormatted = $refundedAt ? formatUtmifyDate($refundedAt) : null;
    
    // Calcular valor total em centavos
    $totalPriceInCents = 0;
    foreach ($products as $p) {
        $totalPriceInCents += intval($p['priceInCents'] ?? ($p['price'] ?? 0) * 100);
    }
    
    // Valores de comissão
    $gatewayFeeInCents = intval($commission['gatewayFeeInCents'] ?? 0);
    $userCommissionInCents = intval($commission['userCommissionInCents'] ?? $totalPriceInCents);
    
    $payload = [
        'orderId' => $orderId,
        'platform' => $utmifyConfig['platform'] ?? 'BeautyHub',
        'paymentMethod' => $paymentMethod,
        'status' => $status,
        'createdAt' => $createdAtFormatted,
        'approvedDate' => $approvedDateFormatted,
        'refundedAt' => $refundedAtFormatted,
        'customer' => [
            'name' => $customer['customer_name'] ?? $customer['name'] ?? 'Cliente',
            'email' => $customer['customer_email'] ?? $customer['email'] ?? '',
            'phone' => preg_replace('/\D/', '', $customer['phone'] ?? ($customer['customer_phone'] ?? '')),
            'document' => preg_replace('/\D/', '', $customer['document'] ?? ($customer['customer_document'] ?? '')),
            'country' => 'BR',
            // Utmify valida schema e não aceita IP nulo
            'ip' => $customer['ip']
                ?? ($customer['customer_ip'] ?? null)
                ?? ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? null)
                ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : null)
                ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')
        ],
        'products' => array_map(function($p) {
            return [
                'id' => $p['id'] ?? uniqid(),
                'name' => $p['name'] ?? 'Produto',
                'planId' => $p['planId'] ?? null,
                'planName' => $p['planName'] ?? null,
                'quantity' => intval($p['quantity'] ?? 1),
                'priceInCents' => intval($p['priceInCents'] ?? ($p['price'] ?? 0) * 100)
            ];
        }, $products),
        'trackingParameters' => [
            'src' => $trackingParams['src'] ?? null,
            'sck' => $trackingParams['sck'] ?? null,
            'utm_source' => $trackingParams['utm_source'] ?? null,
            'utm_campaign' => $trackingParams['utm_campaign'] ?? null,
            'utm_medium' => $trackingParams['utm_medium'] ?? null,
            'utm_content' => $trackingParams['utm_content'] ?? null,
            'utm_term' => $trackingParams['utm_term'] ?? null
        ],
        'commission' => [
            'totalPriceInCents' => $totalPriceInCents,
            'gatewayFeeInCents' => $gatewayFeeInCents,
            'userCommissionInCents' => $userCommissionInCents
        ],
        'isTest' => false
    ];
    
    // Endpoint correto da API Utmify
    $ch = curl_init('https://api.utmify.com.br/api-credentials/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-token: ' . $utmifyConfig['token']
        ],
        CURLOPT_TIMEOUT => 15
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $success = $httpCode >= 200 && $httpCode < 300;
    
    JsonStorage::log('utmify_request', [
        'order_id' => $orderId,
        'status' => $status,
        'http_code' => $httpCode,
        'success' => $success,
        'payload' => $payload,
        'response' => json_decode($response, true) ?? $response,
        'curl_error' => $curlError ?: null
    ]);
    
    return ['success' => $success, 'response' => $response, 'httpCode' => $httpCode];
}

/**
 * Formata data para o formato Utmify: YYYY-MM-DD HH:MM:SS (UTC)
 */
function formatUtmifyDate($date) {
    if (empty($date)) return null;
    
    try {
        // Se já for um timestamp ou string de data
        if (is_numeric($date)) {
            $timestamp = $date;
        } else {
            $timestamp = strtotime($date);
        }
        
        if ($timestamp === false) {
            return date('Y-m-d H:i:s');
        }
        
        // Converter para UTC
        return gmdate('Y-m-d H:i:s', $timestamp);
    } catch (Exception $e) {
        return date('Y-m-d H:i:s');
    }
}

/**
 * PIX Gerado - status: waiting_payment
 */
function utmifyPixGerado($orderId, $valorCentavos, $customer, $items, $utmParams = []) {
    $products = [];
    foreach ($items as $item) {
        $products[] = [
            'id' => $item['id'] ?? $item['slug'] ?? uniqid(),
            'name' => $item['name'] ?? $item['title'] ?? 'Produto',
            'planId' => null,
            'planName' => null,
            'quantity' => intval($item['quantity'] ?? 1),
            'priceInCents' => intval($item['priceInCents'] ?? $item['unitPrice'] ?? $item['price'] ?? 0)
        ];
    }
    
    return enviarUtmify(
        $orderId,
        'pix',
        'waiting_payment',
        date('Y-m-d H:i:s'),
        null,
        null,
        $customer,
        $products,
        $utmParams,
        [
            'totalPriceInCents' => $valorCentavos,
            'gatewayFeeInCents' => 0,
            'userCommissionInCents' => $valorCentavos
        ]
    );
}

/**
 * PIX Pago - status: paid
 */
function utmifyPixPago($orderId, $valorCentavos, $createdAt, $customer, $items, $utmParams = []) {
    $products = [];
    foreach ($items as $item) {
        $products[] = [
            'id' => $item['id'] ?? $item['slug'] ?? uniqid(),
            'name' => $item['name'] ?? $item['title'] ?? 'Produto',
            'planId' => null,
            'planName' => null,
            'quantity' => intval($item['quantity'] ?? 1),
            'priceInCents' => intval($item['priceInCents'] ?? $item['unitPrice'] ?? $item['price'] ?? 0)
        ];
    }
    
    return enviarUtmify(
        $orderId,
        'pix',
        'paid',
        $createdAt,
        date('Y-m-d H:i:s'), // approvedDate = agora
        null,
        $customer,
        $products,
        $utmParams,
        [
            'totalPriceInCents' => $valorCentavos,
            'gatewayFeeInCents' => 0,
            'userCommissionInCents' => $valorCentavos
        ]
    );
}
