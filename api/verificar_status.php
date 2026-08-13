<?php
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/storage.php';
require_once __DIR__ . '/services/payment_compensation.php';

setApiHeaders();

try {
    $orderId = $_GET['order_id'] ?? $_GET['id'] ?? null;
    
    if (!$orderId) {
        throw new Exception('order_id é obrigatório');
    }
    
    $transacao = JsonStorage::findByOrderId('transacoes', $orderId);
    
    if (!$transacao) {
        throw new Exception('Transação não encontrada');
    }
    
    // Se ainda está pendente, tentar verificar diretamente no gateway
    if ($transacao['status'] === 'pending' && !empty($transacao['gateway_id'])) {
        $gateway = strtolower(trim((string)($transacao['gateway'] ?? '')));
        if ($gateway === '') {
            $cred = getCredenciais();
            $gateway = strtolower(trim((string)($cred['gateway_ativo'] ?? 'vulpex')));
        }


        $gatewayStatus = checkGatewayStatus($transacao['gateway_id'], $gateway, $transacao);
        
        if ($gatewayStatus !== null && $gatewayStatus !== '') {
            $result = compensatePayment($transacao, $gateway, $gatewayStatus);
            
            JsonStorage::log('status_updated_via_polling', [
                'order_id' => $orderId,
                'gateway' => $gateway,
                'gateway_status' => $gatewayStatus,
                'new_status' => $result['status'],
                'effects_claimed' => $result['effects_claimed'] ?? false
            ]);

            $refreshed = JsonStorage::findByOrderId('transacoes', $orderId);
            if ($refreshed) {
                $transacao = $refreshed;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'order_id' => $transacao['order_id'],
        'status' => $transacao['status'],
        'valor' => $transacao['valor'],
        'product_name' => $transacao['product_name'] ?? '',
        'created_at' => $transacao['created_at'],
        'paid_at' => $transacao['paid_at'] ?? null
    ]);
    
} catch (Throwable $e) {
    http_response_code(400);
    try {
        JsonStorage::log('verificar_status_error', [
            'order_id' => $_GET['order_id'] ?? $_GET['id'] ?? null,
            'error' => $e->getMessage()
        ]);
    } catch (Throwable $logError) {
        error_log('verificar_status log failed: ' . $logError->getMessage());
    }
    echo json_encode(['success' => false, 'error' => 'Não foi possível consultar o pagamento agora.']);
}

function checkGatewayStatus($gatewayId, $gateway, array $transacao = []) {
    try {
        if ($gateway === 'vulpex') {
            $config = getVulpexConfig();
            if (empty($config['api_key']) || empty($config['api_secret'])) {
                return null;
            }
            
            $token = getVulpexBearerToken($config['api_key'], $config['api_secret']);
            $url = "https://api.vulpex.com.br/api/v1/transactions/{$gatewayId}";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json'
                ],
                CURLOPT_TIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return strtolower($data['data']['status'] ?? $data['status'] ?? '');
            }
        } elseif ($gateway === 'allowpay') {
            // ===== ALLOWPAY V2 =====
            $config = getAllowPayConfig();
            if (empty($config['api_key'])) {
                return null;
            }

            // Route foi salvo no metadata da transação na criação do PIX.
            $route = '';
            if (is_array($transacao)) {
                $meta = $transacao['metadata'] ?? null;
                if (is_string($meta)) $meta = json_decode($meta, true);
                if (is_array($meta) && !empty($meta['allowpay_route'])) {
                    $route = $meta['allowpay_route'];
                }
            }
            if (empty($route)) {
                $route = $config['route'] ?? '';
            }
            if (empty($route)) {
                JsonStorage::log('allowpay_v2_status_missing_route', [
                    'gateway_id' => $gatewayId
                ]);
                return null;
            }

            $url = 'https://allow-gi0i.onrender.com/api/v2/allowpay-seller/payment-status/'
                . urlencode($gatewayId) . '?route=' . urlencode($route);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['api_key' => $config['api_key']]),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $data = json_decode($response, true);
                if (is_array($data) && !empty($data['status'])) {
                    return strtolower($data['status']);
                }
            }
            JsonStorage::log('allowpay_status_check_failed', [
                'gateway_id' => $gatewayId,
                'http_code' => $httpCode,
                'valid_json' => is_array(json_decode((string)$response, true))
            ]);
        } elseif ($gateway === 'disrupty') {
            // ===== DISRUPTY =====
            $config = getDisruptyConfig();
            if (empty($config['public_key']) || empty($config['private_key'])) {
                return null;
            }

            $url = getDisruptyBaseUrl($config['audience'] ?? 'seller') . '/api/sales/' . urlencode($gatewayId);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'x-api-public-key: ' . $config['public_key'],
                    'x-api-private-key: ' . $config['private_key']
                ],
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                $tx = $data['data'] ?? $data['sale'] ?? $data;
                $raw = $tx['status'] ?? $data['status'] ?? '';
                if ($raw === '') return null;
                return strtolower(trim((string)$raw));
            }
        } elseif ($gateway === 'magicpay') {

            // ===== MAGIC PAY =====
            $config = getMagicPayConfig();
            if (empty($config['public_key']) || empty($config['secret_key'])) {
                return null;
            }
            $url = "https://api.gateway-magicpay.com/v1/transactions/{$gatewayId}";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode($config['public_key'] . ':' . $config['secret_key']),
                    'Accept: application/json'
                ],
                CURLOPT_TIMEOUT => 5
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                $st = strtolower($data['status'] ?? $data['data']['status'] ?? '');
                if (in_array($st, ['approved', 'paid'])) return 'paid';
                return $st;
            }
        } elseif ($gateway === 'mangofy') {
            $config = getMangofyConfig();
            if (empty($config['api_key']) || empty($config['store_code'])) {
                return null;
            }

            $url = "https://checkout.mangofy.com.br/api/v1/payment/{$gatewayId}";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $config['api_key'],
                    'Store-Code: ' . $config['store_code'],
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                CURLOPT_TIMEOUT => 5
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                $raw = strtolower($data['payment_status'] ?? $data['status'] ?? '');
                return $raw;
            }
        } elseif ($gateway === 'ghostpay') {
            // GhostPay

            $config = getGhostPayConfig();
            if (empty($config['secret_key'])) {
                return null;
            }
            
            $url = "https://api.ghostspaysv2.com/functions/v1/transactions/{$gatewayId}";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode($config['secret_key'] . ':'),
                    'Accept: application/json'
                ],
                CURLOPT_TIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return strtolower($data['status'] ?? $data['data']['status'] ?? '');
            }
        }
    } catch (Throwable $e) {
        try {
            JsonStorage::log('gateway_status_check_error', [
                'gateway' => $gateway,
                'gateway_id' => $gatewayId,
                'error' => $e->getMessage()
            ]);
        } catch (Throwable $logError) {
            error_log('gateway status log failed: ' . $logError->getMessage());
        }
    }
    
    return null;
}