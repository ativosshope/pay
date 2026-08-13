<?php
// Suprime QUALQUER erro HTML - DEVE ser a primeira coisa do arquivo
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Headers JSON antes de qualquer include
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Agora sim carrega os arquivos (erros serão logados, não exibidos)
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/storage.php';
require_once __DIR__ . '/services/utmify.php';
require_once __DIR__ . '/services/pixels.php';
require_once __DIR__ . '/lib/utils/handlers/base.php';

function getClientIpAddress() {
    $candidates = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR'
    ];

    foreach ($candidates as $key) {
        if (empty($_SERVER[$key])) continue;

        $value = trim((string)$_SERVER[$key]);
        if ($value === '') continue;

        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = array_map('trim', explode(',', $value));
            $value = $parts[0] ?? '';
        }

        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }

    return '127.0.0.1';
}

function maskGatewayCredentialFingerprint($value) {
    $value = trim((string)$value);
    $length = strlen($value);
    if ($length === 0) return 'empty';

    $prefix = substr($value, 0, min(4, $length));
    $suffix = $length > 4 ? substr($value, -4) : $prefix;

    return $prefix . '...' . $suffix . ' (len:' . $length . ')';
}

/**
 * Procura o código PIX copia-e-cola na resposta da Disrupty.
 * A documentação não fixa o nome do campo, então varremos as variações comuns
 * e, em último caso, qualquer string que pareça um payload EMV do PIX.
 */
function disruptyExtractPixCode($data) {
    if (!is_array($data)) return '';
    $keys = ['pixCode', 'pix_code', 'qrcode', 'qrCode', 'qr_code', 'copyPaste', 'copiaecola',
             'copia_e_cola', 'emv', 'payload', 'brcode', 'brCode', 'pixKey', 'code'];
    foreach ($keys as $k) {
        if (isset($data[$k]) && is_string($data[$k]) && disruptyLooksLikePixCode($data[$k])) {
            return trim($data[$k]);
        }
    }
    foreach (['pix', 'payment', 'transaction', 'charge', 'data', 'sale', 'result'] as $nested) {
        if (isset($data[$nested]) && is_array($data[$nested])) {
            $found = disruptyExtractPixCode($data[$nested]);
            if ($found !== '') return $found;
        }
    }
    foreach ($data as $value) {
        if (is_string($value) && disruptyLooksLikePixCode($value)) return trim($value);
        if (is_array($value)) {
            $found = disruptyExtractPixCode($value);
            if ($found !== '') return $found;
        }
    }
    return '';
}

function disruptyLooksLikePixCode($value) {
    $v = trim((string)$value);
    if (strlen($v) < 40) return false;
    if (strpos($v, 'data:image') === 0) return false;
    // Payload EMV do PIX sempre inicia com "000201"
    return strpos($v, '000201') === 0 || stripos($v, 'br.gov.bcb.pix') !== false;
}

/**
 * Procura uma imagem de QR (base64 ou URL) na resposta da Disrupty.
 */
function disruptyExtractQrImage($data) {
    if (!is_array($data)) return '';
    $keys = ['qrCodeBase64', 'qrcodeBase64', 'qr_code_base64', 'qrCodeImage', 'qrImage',
             'qr_image', 'qrCodeUrl', 'qr_code_url', 'base64', 'image'];
    foreach ($keys as $k) {
        if (isset($data[$k]) && is_string($data[$k]) && trim($data[$k]) !== '') {
            $v = trim($data[$k]);
            if (strpos($v, 'data:image') === 0 || strpos($v, 'http') === 0 || strlen($v) > 200) {
                return $v;
            }
        }
    }
    foreach (['pix', 'payment', 'transaction', 'charge', 'data', 'sale', 'result'] as $nested) {
        if (isset($data[$nested]) && is_array($data[$nested])) {
            $found = disruptyExtractQrImage($data[$nested]);
            if ($found !== '') return $found;
        }
    }
    return '';
}

function truncateGatewayText($value, $length) {
    $value = (string)$value;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length);
    }

    return substr($value, 0, $length);
}

function formatBrazilianPhoneForGateway($value) {
    $digits = preg_replace('/\D+/', '', (string)$value);

    if (strlen($digits) === 13 && strpos($digits, '55') === 0) {
        $digits = substr($digits, 2);
    }

    if (strlen($digits) === 11) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7, 4));
    }

    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6, 4));
    }

    return trim((string)$value);
}

/**
 * Gera um CPF válido aleatório (com dígitos verificadores corretos).
 * Usado para preencher o campo "document" exigido pelos gateways,
 * já que o checkout não solicita CPF do cliente final.
 * Cada transação recebe um CPF único.
 */
function generateValidCPF() {
    // 9 primeiros dígitos aleatórios
    $n = [];
    for ($i = 0; $i < 9; $i++) {
        $n[] = random_int(0, 9);
    }

    // Evita CPFs com todos os dígitos iguais (inválidos)
    if (count(array_unique($n)) === 1) {
        $n[0] = ($n[0] + 1) % 10;
    }

    // Primeiro dígito verificador
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += $n[$i] * (10 - $i);
    }
    $d1 = ($sum * 10) % 11;
    if ($d1 === 10) $d1 = 0;
    $n[] = $d1;

    // Segundo dígito verificador
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += $n[$i] * (11 - $i);
    }
    $d2 = ($sum * 10) % 11;
    if ($d2 === 10) $d2 = 0;
    $n[] = $d2;

    return implode('', $n);
}

// Mantido por compatibilidade — agora retorna um CPF válido aleatório.
function getFixedGatewayDocument() {
    return generateValidCPF();
}

// ===== FUNÇÃO DE CHAMADA AO GATEWAY (declarada no escopo global) =====
// IMPORTANTE: Manter FORA do try/catch para evitar problemas de
// declaração condicional com opcache (causa fatal error em produção)
if (!function_exists('callGateway')) {
function callGateway($gatewayAtivo, $gatewaySelection, $params) {
    extract($params);

    $isCentralGateway = !empty($gatewaySelection['credentials']['secret_key']);
    $gatewayProductName = $isCentralGateway ? 'Havan' : $productName;

    $protocol = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
        ? $_SERVER['HTTP_X_FORWARDED_PROTO']
        : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';

    if ($gatewayAtivo === 'vulpex') {
        // ===== VULPEX GATEWAY (docs: https://docs.vulpex.com.br) =====
        // Painel central envia credenciais Vulpex como:
        //   secret_key = api_key  |  company_id = api_secret
        // (compatibilidade com sites que detectam credenciais centrais por secret_key).
        if ($isCentralGateway) {
            $vulpexApiKey    = $gatewaySelection['credentials']['secret_key'] ?? null;
            $vulpexApiSecret = $gatewaySelection['credentials']['company_id'] ?? null;
            if (empty($vulpexApiKey) || empty($vulpexApiSecret)) {
                throw new Exception('Credenciais Vulpex centrais ausentes (secret_key/company_id).');
            }
        } else {
            $vulpexCfg = getVulpexConfig();
            if (empty($vulpexCfg['api_key']) || empty($vulpexCfg['api_secret'])) {
                throw new Exception('Vulpex não configurado.');
            }
            $vulpexApiKey    = $vulpexCfg['api_key'];
            $vulpexApiSecret = $vulpexCfg['api_secret'];
        }

        JsonStorage::log('vulpex_step_auth', [
            'order_id' => $internalOrderId,
            'central'  => $isCentralGateway ? 1 : 0
        ]);
        $bearerToken = getVulpexBearerToken($vulpexApiKey, $vulpexApiSecret);
        JsonStorage::log('vulpex_step_auth_ok', ['order_id' => $internalOrderId, 'token_len' => strlen((string)$bearerToken)]);

        $postbackUrl = $protocol . '://' . $host . '/api/webhook-vulpex.php';
        $amountForGateway = intval(round($amount * 100));

        // Sanitização final dos dados (Vulpex exige document só dígitos)
        $vulpexCustomerDoc = preg_replace('/\D+/', '', (string)$customerDocument);
        $vulpexCustomerPhoneDigits = preg_replace('/\D+/', '', (string)$customerPhone);
        $vulpexCustomerPhone = formatBrazilianPhoneForGateway($customerPhone);
        $vulpexCustomerName = trim((string)$customerName);
        $vulpexCustomerEmail = trim((string)$customerEmail);

        if ($vulpexCustomerName === '' || $vulpexCustomerEmail === '' || !filter_var($vulpexCustomerEmail, FILTER_VALIDATE_EMAIL) || strlen($vulpexCustomerDoc) < 11) {
            throw new Exception('Dados do cliente inválidos para gerar PIX na Vulpex.');
        }

        $shippingZipDigits = preg_replace('/\D+/', '', (string)$shippingZip);
        $hasBillingAddress = trim((string)$shippingStreet) !== ''
            && trim((string)$shippingNumber) !== ''
            && trim((string)$shippingNeighborhood) !== ''
            && trim((string)$shippingCity) !== ''
            && trim((string)$shippingState) !== ''
            && strlen($shippingZipDigits) === 8;

        $vulpexItems = [];
        if (is_array($gatewayItems ?? null)) {
            foreach ($gatewayItems as $index => $gatewayItem) {
                if (!is_array($gatewayItem)) continue;

                $itemTitle = trim((string)($gatewayItem['title'] ?? $gatewayItem['name'] ?? ''));
                $itemQuantity = max(1, intval($gatewayItem['quantity'] ?? 1));
                $itemAmountInCents = intval($gatewayItem['amountInCents'] ?? $gatewayItem['unitPrice'] ?? $gatewayItem['price'] ?? 0);

                if ($itemTitle === '' || $itemAmountInCents <= 0) continue;

                $vulpexItems[] = [
                    'title' => truncateGatewayText($itemTitle, 100),
                    'tangible' => isset($gatewayItem['tangible']) ? (bool)$gatewayItem['tangible'] : true,
                    'quantity' => $itemQuantity,
                    'amountInCents' => $itemAmountInCents
                ];
            }
        }

        if (empty($vulpexItems)) {
            $itemTitle = $gatewayProductName . ($productSize ? ' - Tam: ' . $productSize : '');
            $vulpexItems[] = [
                'title' => truncateGatewayText($itemTitle, 100),
                'tangible' => true,
                'quantity' => 1,
                'amountInCents' => $amountForGateway
            ];
        }

        $description = 'Pedido ' . $internalOrderId;
        $description = truncateGatewayText($description, 140);

        $vulpexCustomerPayload = [
            'name' => $vulpexCustomerName,
            'email' => $vulpexCustomerEmail,
            'documentType' => $customerDocumentType,
            'document' => $vulpexCustomerDoc
        ];

        if ($vulpexCustomerPhone !== '') {
            $vulpexCustomerPayload['phone'] = $vulpexCustomerPhone;
        }

        if ($hasBillingAddress) {
            $vulpexCustomerPayload['billingAddress'] = [
                'street' => trim((string)$shippingStreet),
                'number' => trim((string)$shippingNumber),
                'neighborhood' => trim((string)$shippingNeighborhood),
                'city' => trim((string)$shippingCity),
                'state' => trim((string)$shippingState),
                'zipCode' => $shippingZipDigits
            ];
        }

        $vulpexPayload = [
            'amountInCents' => $amountForGateway,
            'description' => $description,
            'postbackUrl' => $postbackUrl,
            'customer' => $vulpexCustomerPayload,
            'items' => $vulpexItems
        ];

        JsonStorage::log('vulpex_payload_outbound', [
            'order_id' => $internalOrderId,
            'amountInCents' => $amountForGateway,
            'customer_name' => $vulpexCustomerName,
            'customer_email_present' => $vulpexCustomerEmail !== '',
            'customer_doc_len' => strlen($vulpexCustomerDoc),
            'customer_phone_len' => strlen($vulpexCustomerPhoneDigits),
            'has_billing_address' => $hasBillingAddress,
            'postback_url' => $postbackUrl
        ]);

        $ch = curl_init('https://api.vulpex.com.br/api/v1/pix/in/qrcode');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($vulpexPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $bearerToken
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            JsonStorage::log('vulpex_curl_error', ['error' => $curlError, 'order_id' => $internalOrderId]);
            throw new Exception('Vulpex conexão falhou: ' . $curlError);
        }
        if ($httpCode !== 200 && $httpCode !== 201) {
            JsonStorage::log('vulpex_http_error', [
                'order_id' => $internalOrderId,
                'http_code' => $httpCode,
                'response' => truncateGatewayText((string)$response, 1000)
            ]);
            throw new Exception('Vulpex erro (' . $httpCode . '): ' . $response);
        }

        $vulpexData = json_decode($response, true);
        $txData = $vulpexData['data'] ?? $vulpexData;
        $emv = $txData['pix']['emv'] ?? '';
        $qrB64 = $txData['pix']['qrCode'] ?? '';

        if ($qrB64 !== '' && strpos($qrB64, 'data:image') !== 0) {
            $qrB64 = 'data:image/png;base64,' . $qrB64;
        }

        return [
            'gateway_id' => $txData['id'] ?? null,
            'pix_code' => $emv,
            'qr_base64' => $qrB64,
        ];

    } elseif ($gatewayAtivo === 'mangofy') {
        // ===== MANGOFY GATEWAY (docs: https://checkout.mangofy.com.br) =====
        if ($isCentralGateway) {
            // No padrão central, secret_key carrega a API Key e company_id carrega o Store-Code
            $mangofyApiKey   = $gatewaySelection['credentials']['secret_key'] ?? null;
            $mangofyStoreCode = $gatewaySelection['credentials']['company_id'] ?? null;
            if (empty($mangofyApiKey) || empty($mangofyStoreCode)) {
                throw new Exception('Credenciais Mangofy centrais ausentes (secret_key/company_id).');
            }
        } else {
            $mangofyCfg = getMangofyConfig();
            if (empty($mangofyCfg['api_key']) || empty($mangofyCfg['store_code'])) {
                throw new Exception('Mangofy não configurado.');
            }
            $mangofyApiKey    = $mangofyCfg['api_key'];
            $mangofyStoreCode = $mangofyCfg['store_code'];
        }

        $postbackUrl = $protocol . '://' . $host . '/api/webhook-mangofy.php';
        $amountForGateway = intval(round($amount * 100));

        $mangofyCustomerDoc = preg_replace('/\D+/', '', (string)$customerDocument);
        $mangofyCustomerPhone = preg_replace('/\D+/', '', (string)$customerPhone);
        $mangofyCustomerName = trim((string)$customerName);
        $mangofyCustomerEmail = trim((string)$customerEmail);

        if ($mangofyCustomerName === '' || !filter_var($mangofyCustomerEmail, FILTER_VALIDATE_EMAIL) || strlen($mangofyCustomerDoc) < 11) {
            throw new Exception('Dados do cliente inválidos para gerar PIX na Mangofy.');
        }

        $mangofyItems = [];
        if (is_array($gatewayItems ?? null)) {
            foreach ($gatewayItems as $gatewayItem) {
                if (!is_array($gatewayItem)) continue;
                $itemTitle = trim((string)($gatewayItem['title'] ?? $gatewayItem['name'] ?? ''));
                $itemQuantity = max(1, intval($gatewayItem['quantity'] ?? 1));
                $itemAmountInCents = intval($gatewayItem['amountInCents'] ?? $gatewayItem['unitPrice'] ?? $gatewayItem['price'] ?? 0);
                if ($itemTitle === '' || $itemAmountInCents <= 0) continue;
                $mangofyItems[] = [
                    'name' => truncateGatewayText($itemTitle, 100),
                    'quantity' => $itemQuantity,
                    'unit_price' => $itemAmountInCents,
                    'tangible' => isset($gatewayItem['tangible']) ? (bool)$gatewayItem['tangible'] : true
                ];
            }
        }
        if (empty($mangofyItems)) {
            $itemTitle = $gatewayProductName . ($productSize ? ' - Tam: ' . $productSize : '');
            $mangofyItems[] = [
                'name' => truncateGatewayText($itemTitle, 100),
                'quantity' => 1,
                'unit_price' => $amountForGateway,
                'tangible' => true
            ];
        }

        $mangofyPayload = [
            'external_code' => (string)$internalOrderId,
            'payment_method' => 'pix',
            'payment_format' => 'regular',
            'installments' => 1,
            'payment_amount' => $amountForGateway,
            'shipping_amount' => 0,
            'postback_url' => $postbackUrl,
            'items' => $mangofyItems,
            'customer' => [
                'email' => $mangofyCustomerEmail,
                'name' => $mangofyCustomerName,
                'document' => $mangofyCustomerDoc,
                'phone' => $mangofyCustomerPhone,
                'ip' => getClientIpAddress()
            ],
            'pix' => [
                'expires_in_days' => 1
            ],
            'extra' => [
                'metadata' => [
                    'internalOrderId' => (string)$internalOrderId
                ]
            ]
        ];

        JsonStorage::log('mangofy_payload_outbound', [
            'order_id' => $internalOrderId,
            'payment_amount' => $amountForGateway,
            'items_count' => count($mangofyItems),
            'postback_url' => $postbackUrl,
            'api_key_fingerprint' => maskGatewayCredentialFingerprint($mangofyApiKey),
            'store_code_fingerprint' => maskGatewayCredentialFingerprint($mangofyStoreCode)
        ]);

        $ch = curl_init('https://checkout.mangofy.com.br/api/v1/payment');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($mangofyPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $mangofyApiKey,
                'Store-Code: ' . $mangofyStoreCode,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            JsonStorage::log('mangofy_curl_error', ['error' => $curlError, 'order_id' => $internalOrderId]);
            throw new Exception('Mangofy conexão falhou: ' . $curlError);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            JsonStorage::log('mangofy_http_error', [
                'order_id' => $internalOrderId,
                'http_code' => $httpCode,
                'response' => truncateGatewayText((string)$response, 1000)
            ]);
            throw new Exception('Mangofy erro (' . $httpCode . '): ' . $response);
        }

        $mangofyData = json_decode($response, true);
        if (!is_array($mangofyData) || empty($mangofyData['payment_code'])) {
            throw new Exception('Resposta inválida da Mangofy: payment_code ausente.');
        }

        // Mangofy retorna dados PIX em variações conhecidas; faz extração defensiva
        $pixNode = is_array($mangofyData['pix'] ?? null) ? $mangofyData['pix'] : [];
        $paymentNode = is_array($mangofyData['payment'] ?? null) ? $mangofyData['payment'] : [];
        $paymentPixNode = is_array($paymentNode['pix'] ?? null) ? $paymentNode['pix'] : [];
        $qrcodeNode = is_array($paymentNode['qrcode'] ?? null) ? $paymentNode['qrcode'] : (is_array($mangofyData['qrcode'] ?? null) ? $mangofyData['qrcode'] : []);

        $emv = $pixNode['pix_qrcode_text']
            ?? $pixNode['qr_code']
            ?? $pixNode['qrcode_text']
            ?? $pixNode['code']
            ?? $pixNode['emv']
            ?? $pixNode['copy_paste']
            ?? $pixNode['qrcode']
            ?? $pixNode['payload']
            ?? $paymentPixNode['pix_qrcode_text']
            ?? $paymentPixNode['qr_code']
            ?? $paymentPixNode['code']
            ?? $qrcodeNode['code']
            ?? $qrcodeNode['payload']
            ?? $mangofyData['pix_code']
            ?? $mangofyData['qr_code']
            ?? (is_string($mangofyData['qrcode'] ?? null) ? $mangofyData['qrcode'] : null)
            ?? '';

        $qrB64 = $pixNode['qr_code_base64']
            ?? $pixNode['base64']
            ?? $pixNode['qrcode_base64']
            ?? $pixNode['image']
            ?? $paymentPixNode['qr_code_base64']
            ?? $paymentPixNode['base64']
            ?? $qrcodeNode['base64']
            ?? $qrcodeNode['image']
            ?? $mangofyData['qrcode_base64']
            ?? $mangofyData['qr_code_base64']
            ?? '';

        if (empty($emv)) {
            JsonStorage::log('mangofy_extraction_failed', [
                'order_id' => $internalOrderId,
                'response_keys' => array_keys($mangofyData),
                'pix_keys' => array_keys($pixNode),
                'payment_keys' => array_keys($paymentNode),
                'raw_truncated' => truncateGatewayText((string)$response, 1500)
            ]);
            throw new Exception('Mangofy: código PIX não retornado pela API.');
        }

        if ($qrB64 !== '' && strpos($qrB64, 'data:image') !== 0) {
            $qrB64 = 'data:image/png;base64,' . $qrB64;
        }

        return [
            'gateway_id' => $mangofyData['payment_code'],
            'pix_code' => $emv,
            'qr_base64' => $qrB64,
        ];

    } elseif ($gatewayAtivo === 'allowpay') {
        // ===== ALLOWPAY V2 GATEWAY (https://allow-gi0i.onrender.com) =====
        if ($isCentralGateway) {
            $allowApiKey = $gatewaySelection['credentials']['api_key']
                ?? $gatewaySelection['credentials']['secret_key']
                ?? '';
            $allowRoute = $gatewaySelection['credentials']['route']
                ?? $gatewaySelection['credentials']['company_id']
                ?? '';
        } else {
            $allowPayConfig = getAllowPayConfig();
            if (!$allowPayConfig['ativo'] || empty($allowPayConfig['api_key'])) {
                throw new Exception('AllowPay V2 não configurado');
            }
            $allowApiKey = $allowPayConfig['api_key'];
            $allowRoute = $allowPayConfig['route'] ?? '';
        }

        if (empty($allowApiKey)) {
            throw new Exception('AllowPay V2: api_key ausente');
        }

        $amountForGateway = intval(round($amount * 100));
        $allowCustomerDoc = preg_replace('/\D+/', '', (string)$customerDocument);
        $allowCustomerPhone = preg_replace('/\D+/', '', (string)$customerPhone);

        $allowPayload = [
            'api_key' => $allowApiKey,
            'amount' => $amountForGateway,
            'description' => 'Pedido ' . $internalOrderId,
            'customer' => [
                'name' => trim((string)$customerName),
                'email' => trim((string)$customerEmail),
                'cellphone' => $allowCustomerPhone,
                'taxId' => $allowCustomerDoc,
            ],
        ];

        $validRoutes = ['zyra', 'safepix', 'pluggou', 'fyntra'];
        if (!empty($allowRoute) && in_array(strtolower($allowRoute), $validRoutes, true)) {
            $allowPayload['route'] = strtolower($allowRoute);
        }

        JsonStorage::log('allowpay_v2_payload_outbound', [
            'order_id' => $internalOrderId,
            'amount' => $amountForGateway,
            'route_requested' => $allowPayload['route'] ?? null,
            'central' => $isCentralGateway ? 1 : 0
        ]);

        $ch = curl_init('https://allow-gi0i.onrender.com/api/v2/allowpay-seller/create-pix');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($allowPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            JsonStorage::log('allowpay_v2_curl_error', ['error' => $curlError, 'order_id' => $internalOrderId]);
            throw new Exception('AllowPay V2 conexão falhou: ' . $curlError);
        }

        $allowData = json_decode($response, true);

        if (!is_array($allowData)) {
            JsonStorage::log('allowpay_v2_invalid_response', [
                'order_id' => $internalOrderId,
                'http_code' => $httpCode,
                'response' => truncateGatewayText((string)$response, 500)
            ]);
            throw new Exception('AllowPay V2 resposta inválida.');
        }

        if ($httpCode < 200 || $httpCode >= 300 || isset($allowData['error'])) {
            JsonStorage::log('allowpay_v2_http_error', [
                'order_id' => $internalOrderId,
                'http_code' => $httpCode,
                'response' => truncateGatewayText((string)$response, 1000)
            ]);
            throw new Exception('AllowPay V2 erro (' . $httpCode . '): ' . ($allowData['error'] ?? $response));
        }

        if (empty($allowData['txid']) || empty($allowData['pix_code']) || empty($allowData['route'])) {
            throw new Exception('AllowPay V2 resposta incompleta (faltam txid/pix_code/route).');
        }

        $qrB64 = $allowData['pix_qr_code'] ?? '';
        return [
            'gateway_id' => $allowData['txid'],
            'pix_code' => $allowData['pix_code'],
            'qr_base64' => $qrB64,
            'route' => $allowData['route'],
        ];

    } elseif ($gatewayAtivo === 'magicpay') {
        // ===== MAGIC PAY GATEWAY (https://api.gateway-magicpay.com) =====
        if ($isCentralGateway) {
            $magicPublicKey = $gatewaySelection['credentials']['company_id']
                ?? $gatewaySelection['credentials']['public_key']
                ?? '';
            $magicSecretKey = $gatewaySelection['credentials']['secret_key'] ?? '';
        } else {
            $magicCfg = getMagicPayConfig();
            if (empty($magicCfg['public_key']) || empty($magicCfg['secret_key'])) {
                throw new Exception('Magic Pay não configurado');
            }
            $magicPublicKey = $magicCfg['public_key'];
            $magicSecretKey = $magicCfg['secret_key'];
        }

        if (empty($magicPublicKey) || empty($magicSecretKey)) {
            throw new Exception('Magic Pay: credenciais ausentes (public_key/secret_key).');
        }

        $postbackUrl = $protocol . '://' . $host . '/api/webhook-magicpay.php';
        $amountForGateway = intval(round($amount * 100));

        $magicCustomerDoc = preg_replace('/\D+/', '', (string)$customerDocument);
        $magicCustomerPhone = preg_replace('/\D+/', '', (string)$customerPhone);
        $magicCustomerName = trim((string)$customerName);
        $magicCustomerEmail = trim((string)$customerEmail);

        if ($magicCustomerName === '' || $magicCustomerEmail === '' || !filter_var($magicCustomerEmail, FILTER_VALIDATE_EMAIL) || strlen($magicCustomerDoc) < 11) {
            throw new Exception('Dados do cliente inválidos para gerar PIX na Magic Pay.');
        }

        $magicItems = [];
        if (is_array($gatewayItems ?? null)) {
            foreach ($gatewayItems as $gatewayItem) {
                if (!is_array($gatewayItem)) continue;
                $itemTitle = trim((string)($gatewayItem['title'] ?? $gatewayItem['name'] ?? ''));
                $itemQuantity = max(1, intval($gatewayItem['quantity'] ?? 1));
                $itemUnitPrice = intval($gatewayItem['amountInCents'] ?? $gatewayItem['unitPrice'] ?? $gatewayItem['price'] ?? 0);
                if ($itemTitle === '' || $itemUnitPrice <= 0) continue;
                $magicItems[] = [
                    'title' => truncateGatewayText($itemTitle, 100),
                    'quantity' => $itemQuantity,
                    'unitPrice' => $itemUnitPrice,
                    'tangible' => isset($gatewayItem['tangible']) ? (bool)$gatewayItem['tangible'] : false,
                ];
            }
        }
        if (empty($magicItems)) {
            $itemTitle = $gatewayProductName . ($productSize ? ' - Tam: ' . $productSize : '');
            $magicItems[] = [
                'title' => truncateGatewayText($itemTitle, 100),
                'quantity' => 1,
                'unitPrice' => $amountForGateway,
                'tangible' => false,
            ];
        }

        $magicPayload = [
            'amount' => $amountForGateway,
            'paymentMethod' => 'pix',
            'postbackUrl' => $postbackUrl,
            'items' => $magicItems,
            'customer' => [
                'name' => $magicCustomerName,
                'email' => $magicCustomerEmail,
                'phone' => $magicCustomerPhone,
                'document' => [
                    'type' => 'cpf',
                    'number' => $magicCustomerDoc
                ]
            ],
            'metadata' => json_encode(['internalOrderId' => $internalOrderId]),
            'externalRef' => $internalOrderId,
        ];

        JsonStorage::log('magicpay_payload_outbound', [
            'order_id' => $internalOrderId,
            'amount' => $amountForGateway,
            'central' => $isCentralGateway ? 1 : 0,
            'postback_url' => $postbackUrl
        ]);

        $ch = curl_init('https://api.gateway-magicpay.com/v1/transactions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($magicPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($magicPublicKey . ':' . $magicSecretKey)
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            JsonStorage::log('magicpay_curl_error', ['error' => $curlError, 'order_id' => $internalOrderId]);
            throw new Exception('Magic Pay conexão falhou: ' . $curlError);
        }
        if ($httpCode !== 200 && $httpCode !== 201) {
            JsonStorage::log('magicpay_http_error', [
                'order_id' => $internalOrderId,
                'http_code' => $httpCode,
                'response' => truncateGatewayText((string)$response, 1000)
            ]);
            throw new Exception('Magic Pay erro (' . $httpCode . '): ' . $response);
        }

        $magicData = json_decode($response, true);
        $tx = $magicData['data'] ?? $magicData;

        $pixCode = $tx['pix']['qrcode']
            ?? $tx['pix']['code']
            ?? $tx['pix']['emv']
            ?? $tx['qrcode']
            ?? '';
        $qrB64 = $tx['pix']['qrCodeBase64']
            ?? $tx['pix']['qrCode']
            ?? $tx['pix']['base64']
            ?? '';
        if ($qrB64 !== '' && strpos($qrB64, 'data:image') !== 0 && strpos($qrB64, 'http') !== 0) {
            $qrB64 = 'data:image/png;base64,' . $qrB64;
        }

        return [
            'gateway_id' => $tx['id'] ?? $tx['transactionId'] ?? null,
            'pix_code' => $pixCode,
            'qr_base64' => $qrB64,
        ];

    } elseif ($gatewayAtivo === 'disrupty') {
        // ===== DISRUPTY GATEWAY (https://api-sellers.disruptybr.app) =====
        if ($isCentralGateway) {
            $disruptyPublicKey = $gatewaySelection['credentials']['company_id']
                ?? $gatewaySelection['credentials']['public_key']
                ?? '';
            $disruptyPrivateKey = $gatewaySelection['credentials']['secret_key'] ?? '';
            $disruptyAudience = 'seller';
        } else {
            $disruptyCfg = getDisruptyConfig();
            if (empty($disruptyCfg['public_key']) || empty($disruptyCfg['private_key'])) {
                throw new Exception('Disrupty não configurado');
            }
            $disruptyPublicKey = $disruptyCfg['public_key'];
            $disruptyPrivateKey = $disruptyCfg['private_key'];
            $disruptyAudience = $disruptyCfg['audience'] ?? 'seller';
        }

        if (empty($disruptyPublicKey) || empty($disruptyPrivateKey)) {
            throw new Exception('Disrupty: credenciais ausentes (public/private key).');
        }

        $postbackUrl = $protocol . '://' . $host . '/api/webhook-disrupty.php';
        $amountForGateway = intval(round($amount * 100)); // centavos (base interna)

        $dsCustomerDoc = preg_replace('/\D+/', '', (string)$customerDocument);
        $dsCustomerPhone = preg_replace('/\D+/', '', (string)$customerPhone);
        $dsCustomerName = trim((string)$customerName);
        $dsCustomerEmail = trim((string)$customerEmail);

        if ($dsCustomerName === '' || $dsCustomerEmail === '' || !filter_var($dsCustomerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Dados do cliente inválidos para gerar PIX na Disrupty.');
        }

        // Itens em REAIS (a API Disrupty trabalha com reais, não centavos)
        $dsItems = [];
        $dsItemsTotalCents = 0;
        if (is_array($gatewayItems ?? null)) {
            foreach ($gatewayItems as $gatewayItem) {
                if (!is_array($gatewayItem)) continue;
                $itemTitle = trim((string)($gatewayItem['title'] ?? $gatewayItem['name'] ?? ''));
                $itemQuantity = max(1, intval($gatewayItem['quantity'] ?? 1));
                $itemUnitCents = intval($gatewayItem['amountInCents'] ?? $gatewayItem['unitPrice'] ?? $gatewayItem['price'] ?? 0);
                if ($itemTitle === '' || $itemUnitCents <= 0) continue;
                $dsItems[] = [
                    'title' => truncateGatewayText($itemTitle, 200),
                    'unitPrice' => round($itemUnitCents / 100, 2),
                    'quantity' => $itemQuantity,
                    'tangible' => isset($gatewayItem['tangible']) ? (bool)$gatewayItem['tangible'] : false,
                ];
                $dsItemsTotalCents += $itemUnitCents * $itemQuantity;
            }
        }
        // A Disrupty valida amount ≈ Σ items — se não bater, envia item único.
        if (empty($dsItems) || $dsItemsTotalCents !== $amountForGateway) {
            $itemTitle = $gatewayProductName . ($productSize ? ' - Tam: ' . $productSize : '');
            $dsItems = [[
                'title' => truncateGatewayText($itemTitle, 200),
                'unitPrice' => round($amountForGateway / 100, 2),
                'quantity' => 1,
                'tangible' => false,
            ]];
        }

        $dsCustomer = [
            'name' => $dsCustomerName,
            'email' => $dsCustomerEmail,
        ];
        if ($dsCustomerPhone !== '') $dsCustomer['phone'] = $dsCustomerPhone;
        if (strlen($dsCustomerDoc) >= 11) {
            $dsCustomer['document'] = $dsCustomerDoc;
            $dsCustomer['documentType'] = strlen($dsCustomerDoc) > 11 ? 'CNPJ' : 'CPF';
        }

        $disruptyPayload = [
            'amount' => round($amountForGateway / 100, 2),
            'paymentMethod' => 'PIX',
            'currency' => 'BRL',
            'customer' => $dsCustomer,
            'items' => $dsItems,
            'orderId' => (string)$internalOrderId,
            'externalId' => (string)$internalOrderId,
            'postbackUrl' => $postbackUrl,
            'metadata' => [
                'internalOrderId' => (string)$internalOrderId,
                'flow' => $pixFlow ?? 'main',
            ],
        ];

        JsonStorage::log('disrupty_payload_outbound', [
            'order_id' => $internalOrderId,
            'amount' => $amountForGateway,
            'central' => $isCentralGateway ? 1 : 0,
            'postback_url' => $postbackUrl
        ]);

        $disruptyUrl = getDisruptyBaseUrl($disruptyAudience) . '/api/sales';
        $ch = curl_init($disruptyUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($disruptyPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'x-api-public-key: ' . $disruptyPublicKey,
                'x-api-private-key: ' . $disruptyPrivateKey,
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            JsonStorage::log('disrupty_curl_error', ['error' => $curlError, 'order_id' => $internalOrderId]);
            throw new Exception('Disrupty conexão falhou: ' . $curlError);
        }
        if ($httpCode !== 200 && $httpCode !== 201) {
            JsonStorage::log('disrupty_http_error', [
                'order_id' => $internalOrderId,
                'http_code' => $httpCode,
                'response' => truncateGatewayText((string)$response, 1500)
            ]);
            throw new Exception('Disrupty erro (' . $httpCode . '): ' . $response);
        }

        $disruptyData = json_decode($response, true);
        // Log do JSON bruto: a doc não fixa os nomes dos campos de PIX.
        JsonStorage::log('disrupty_response_raw', [
            'order_id' => $internalOrderId,
            'response' => truncateGatewayText((string)$response, 2000)
        ]);

        $dsTx = $disruptyData['data'] ?? $disruptyData['sale'] ?? $disruptyData;
        if (!is_array($dsTx)) $dsTx = [];

        $pixCode = disruptyExtractPixCode($dsTx);
        if ($pixCode === '') {
            $pixCode = disruptyExtractPixCode(is_array($disruptyData) ? $disruptyData : []);
        }
        if ($pixCode === '') {
            throw new Exception('Disrupty: resposta sem código PIX.');
        }

        $qrB64 = disruptyExtractQrImage($dsTx);
        if ($qrB64 === '') {
            $qrB64 = disruptyExtractQrImage(is_array($disruptyData) ? $disruptyData : []);
        }
        if ($qrB64 !== '' && strpos($qrB64, 'data:image') !== 0 && strpos($qrB64, 'http') !== 0) {
            $qrB64 = 'data:image/png;base64,' . $qrB64;
        }

        $dsGatewayId = $dsTx['id']
            ?? $dsTx['saleId']
            ?? $dsTx['transactionId']
            ?? $disruptyData['id']
            ?? null;

        return [
            'gateway_id' => $dsGatewayId !== null ? (string)$dsGatewayId : null,
            'pix_code' => $pixCode,
            'qr_base64' => $qrB64,
        ];

    } else {
        // ===== GHOSTPAY GATEWAY =====
        if ($isCentralGateway) {
            $secretKey = $gatewaySelection['credentials']['secret_key'];
        } else {
            $ghostConfig = getGhostPayConfig();
            if (!$ghostConfig['ativo'] || empty($ghostConfig['secret_key'])) {
                throw new Exception('GhostPay não configurado');
            }
            $secretKey = $ghostConfig['secret_key'];
        }

        $postbackUrl = $protocol . '://' . $host . '/api/webhook-ghostpay.php';
        $amountCentsGhost = intval($amount * 100);

        $ghostPayload = [
            'amount' => $amountCentsGhost,
            'paymentMethod' => 'pix',
            'customer' => [
                'name' => $customerName,
                'email' => $customerEmail,
                'phone' => $customerPhone,
                'document' => ['type' => 'cpf', 'number' => $customerDocument]
            ],
            'items' => [[
                'title' => $gatewayProductName . ($productSize ? ' - Tam: ' . $productSize : ''),
                'quantity' => 1,
                'unitPrice' => $amountCentsGhost,
                'tangible' => true
            ]],
            'postbackUrl' => $postbackUrl,
            'metadata' => ['internalOrderId' => $internalOrderId]
        ];

        $ch = curl_init('https://api.ghostspaysv2.com/functions/v1/transactions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($ghostPayload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($secretKey . ':')
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new Exception('GhostPay conexão falhou: ' . $curlError);
        }
        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new Exception('GhostPay erro (' . $httpCode . '): ' . $response);
        }

        $ghostData = json_decode($response, true);
        return [
            'gateway_id' => $ghostData['id'] ?? $ghostData['data']['id'] ?? null,
            'pix_code' => $ghostData['pix']['qrcode'] ?? $ghostData['data']['pix']['qrcode'] ?? '',
            'qr_base64' => '',
        ];
    }
}
}

try {
    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody, true);
    
    // DEBUG: Log raw body to trace customer data issue
    JsonStorage::log('criar_pix_raw_input', [
        'raw_body_length' => strlen($rawBody),
        'raw_customer_name' => $input['customer']['name'] ?? 'NOT_FOUND',
        'raw_customer_email' => $input['customer']['email'] ?? 'NOT_FOUND',
        'raw_customer_phone' => $input['customer']['phone'] ?? 'NOT_FOUND',
        'raw_customer_cpf' => $input['customer']['cpf'] ?? 'NOT_FOUND',
        'raw_customer_document' => $input['customer']['document'] ?? 'NOT_FOUND',
        'has_customer_key' => isset($input['customer']) ? 'YES' : 'NO',
        'input_keys' => is_array($input) ? implode(',', array_keys($input)) : 'NOT_ARRAY',
        'customer_keys' => is_array($input['customer'] ?? null) ? implode(',', array_keys($input['customer'])) : 'NOT_ARRAY'
    ]);
    
    $ghostConfig = getGhostPayConfig();
    $allowConfig = getAllowPayConfig();
    $vulpexConfig = getVulpexConfig();
    $mangofyConfig = getMangofyConfig();
    $magicConfig = getMagicPayConfig();
    $disruptyConfig = getDisruptyConfig();

    $ghostConfigurado = !empty($ghostConfig['secret_key']);
    $allowConfigurado = !empty($allowConfig['api_key']);
    $vulpexConfigurado = !empty($vulpexConfig['api_key']) && !empty($vulpexConfig['api_secret']);
    $mangofyConfigurado = !empty($mangofyConfig['api_key']) && !empty($mangofyConfig['store_code']);
    $magicConfigurado = !empty($magicConfig['public_key']) && !empty($magicConfig['secret_key']);
    $disruptyConfigurado = !empty($disruptyConfig['public_key']) && !empty($disruptyConfig['private_key']);

    $gatewayPadrao = getGatewayAtivo();

    $fallbackOrder = function($current) use ($ghostConfigurado, $allowConfigurado, $vulpexConfigurado, $mangofyConfigurado, $magicConfigurado, $disruptyConfigurado) {
        $map = [
            'ghostpay' => $ghostConfigurado,
            'allowpay' => $allowConfigurado,
            'vulpex' => $vulpexConfigurado,
            'mangofy' => $mangofyConfigurado,
            'magicpay' => $magicConfigurado,
            'disrupty' => $disruptyConfigurado,
        ];
        if (!empty($map[$current])) return $current;
        foreach ($map as $k => $ok) {
            if ($k !== $current && $ok) return $k;
        }
        return $current;
    };
    $gatewayPadrao = $fallbackOrder($gatewayPadrao);
    
    $internalOrderId = JsonStorage::getNextOrderId();
    
    // ===== FLUXO DA VENDA (contadores separados) =====
    // 'main'   = taxa de cadastro do /saque (entrada do funil)
    // 'upsell' = qualquer PIX criado a partir de /upsell/*
    $pixFlow = 'main';
    if (!empty($input['metadata']['upsell']) || !empty($input['upsell_slug'])) {
        $pixFlow = 'upsell';
    }

    // ===== SISTEMA DAS 10 PRIMEIRAS VENDAS SEMPRE LOCAIS (POR FLUXO) =====
    // Usa MySQL para persistência (sobrevive a deploys) e atomicidade.
    // A contagem é feita ANTES de decidir, e o INSERT é feito DEPOIS
    // do PIX ser criado com sucesso, garantindo que só conta vendas reais.
    $FORCE_LOCAL_THRESHOLD = 10;
    $totalPixGerados = 0;
    try {
        $pdo = Database::getConnection();
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM pix_initial_counter WHERE flow = :flow");
            $stmt->execute([':flow' => $pixFlow]);
            $countResult = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $inner) {
            // Coluna `flow` inexistente — fallback para contagem global
            $countResult = $pdo->query("SELECT COUNT(*) as total FROM pix_initial_counter")->fetch(PDO::FETCH_ASSOC);
        }
        $totalPixGerados = intval($countResult['total'] ?? 0);
    } catch (Exception $e) {
        // Se a tabela não existir ainda, assume 0 (forceLocal)
        $totalPixGerados = 0;
    }

    
    $forceLocal = ($totalPixGerados < $FORCE_LOCAL_THRESHOLD);
    
    // SEMPRE buscar config do painel central pelo backend (não depende do frontend)
    // Isso garante que o split funcione mesmo quando o fetch do frontend falha
    $centralConfig = fetchRemoteGatewayConfigCached();
    $frontendGatewayConfig = $input['gatewayConfig'] ?? null;
    
    // Prioridade: config do backend (mais confiável) > config do frontend (fallback)
    $activeConfig = $centralConfig ?? $frontendGatewayConfig;
    
    if ($forceLocal) {
        // PRIMEIRAS 10 VENDAS: SEMPRE local, ignora completamente o painel central
        $gatewaySelection = [
            'gateway' => $gatewayPadrao,
            'siteDisabled' => false,
            'source' => 'local_forced_first10_' . $pixFlow,
            'credentials' => null
        ];
    } elseif ($activeConfig && isset($activeConfig['useOverride']) && $activeConfig['useOverride']) {
        
        if (($activeConfig['siteStatus'] ?? 'ACTIVE') === 'DISABLED') {
            throw new Exception('Sistema temporariamente indisponível. Tente novamente mais tarde.');
        }
        
        if (!empty($activeConfig['splitMode']) && $activeConfig['splitMode']) {
            $splitPercent = intval($activeConfig['splitPercent'] ?? 0);
            $localPercent = 100 - $splitPercent;
            
            if ($splitPercent <= 0) {
                $gatewaySelection = [
                    'gateway' => $gatewayPadrao,
                    'siteDisabled' => false,
                    'source' => 'local_100%',
                    'credentials' => null
                ];
            } else {
                // Contador atômico MySQL isolado por fluxo (main / upsell)
                $splitNumber = JsonStorage::getNextSplitNumber($pixFlow);
                
                // Fórmula determinística: compara quantos devem ir pro central
                // até este número vs até o número anterior
                $centralAteAgora = floor($splitNumber * $splitPercent / 100);
                $centralAteAnterior = floor(($splitNumber - 1) * $splitPercent / 100);
                $vaiProPainel = ($centralAteAgora > $centralAteAnterior);
                
                JsonStorage::log('split_decision', [
                    'flow' => $pixFlow,
                    'split_number' => $splitNumber,
                    'splitPercent' => $splitPercent,
                    'central_ate_agora' => $centralAteAgora,
                    'central_ate_anterior' => $centralAteAnterior,
                    'destino' => $vaiProPainel ? 'CENTRAL' : 'LOCAL',
                    'config_source' => $centralConfig ? 'backend_cached' : 'frontend'
                ]);
                
                if ($vaiProPainel) {
                    $gatewaySelection = [
                        'gateway' => strtolower($activeConfig['gateway'] ?? $gatewayPadrao),
                        'siteDisabled' => false,
                        'source' => 'central_' . $splitPercent . '%_' . $pixFlow,
                        'credentials' => [
                            'secret_key' => $activeConfig['credentials']['secret_key'] ?? null,
                            'company_id' => $activeConfig['credentials']['company_id'] ?? null
                        ]
                    ];
                } else {
                    $gatewaySelection = [
                        'gateway' => $gatewayPadrao,
                        'siteDisabled' => false,
                        'source' => 'local_' . $localPercent . '%_' . $pixFlow,
                        'credentials' => null
                    ];
                }

            }
        } else {
            $gatewaySelection = [
                'gateway' => strtolower($activeConfig['gateway'] ?? $gatewayPadrao),
                'siteDisabled' => false,
                'source' => 'override_100_central',
                'credentials' => [
                    'secret_key' => $activeConfig['credentials']['secret_key'] ?? null,
                    'company_id' => $activeConfig['credentials']['company_id'] ?? null
                ]
            ];
        }
    } else {
        // Sem forceLocal e sem override: usa local padrão
        $gatewaySelection = [
            'gateway' => $gatewayPadrao,
            'siteDisabled' => false,
            'source' => 'local_100%',
            'credentials' => null
        ];
    }
    
    if ($gatewaySelection['siteDisabled']) {
        throw new Exception('Sistema temporariamente indisponível. Tente novamente mais tarde.');
    }
    
    $gatewayAtivo = $gatewaySelection['gateway'];
    
    $hasCentralCreds = !empty($gatewaySelection['credentials']['secret_key']) || !empty($gatewaySelection['credentials']['api_key']);
    $localOk = [
        'ghostpay' => $ghostConfigurado,
        'allowpay' => $allowConfigurado,
        'vulpex' => $vulpexConfigurado,
        'mangofy' => $mangofyConfigurado,
        'magicpay' => $magicConfigurado,
        'disrupty' => $disruptyConfigurado,
    ];
    if (empty($localOk[$gatewayAtivo]) && !$hasCentralCreds) {
        $picked = null;
        foreach ($localOk as $k => $ok) { if ($ok) { $picked = $k; break; } }
        if (!$picked) {
            throw new Exception('Nenhum gateway de pagamento está configurado. Configure AllowPay, Vulpex, Mangofy, Magic Pay ou Disrupty no painel admin.');
        }
        $gatewayAtivo = $picked;
    }

    JsonStorage::log('criar_pix_gateway_selection', [
        'requested_gateway' => $gatewayPadrao,
        'selected_gateway' => $gatewayAtivo,
        'gateway_source' => $gatewaySelection['source'] ?? 'unknown',
        'ghost_configured' => $ghostConfigurado,
        'allow_configured' => $allowConfigurado,
        'vulpex_configured' => $vulpexConfigurado,
        'mangofy_configured' => $mangofyConfigurado,
        'magic_configured' => $magicConfigurado,
        'disrupty_configured' => $disruptyConfigurado
    ]);
    
    $amountCents = intval($input['amount'] ?? 0);

    // Taxa de Cadastro: o valor é SEMPRE o configurado no banco (backend é a autoridade).
    if (($input['fee_type'] ?? '') === 'taxa_cadastro') {
        $taxaCadastroCents = getTaxaCadastroValor();
        if ($taxaCadastroCents <= 0) {
            throw new Exception('Taxa de cadastro não configurada.');
        }
        JsonStorage::log('criar_pix_taxa_cadastro_override', [
            'amount_from_frontend' => $amountCents,
            'amount_from_db' => $taxaCadastroCents
        ]);
        $amountCents = $taxaCadastroCents;
    }

    if ($amountCents > 0 && $amountCents < 1000) {
        JsonStorage::log('criar_pix_amount_suspect', [
            'raw_amount' => $input['amount'] ?? null,
            'normalized_amount_cents' => $amountCents,
            'hint' => 'Frontend provavelmente enviou valor em reais em vez de centavos.'
        ]);
    }
    $amount = $amountCents / 100;
    
    JsonStorage::log('criar_pix_amount', [
        'raw_amount' => $input['amount'] ?? 'null',
        'amount_cents' => $amountCents,
        'amount_reais' => $amount,
        'gateway' => $gatewayAtivo,
        'gateway_source' => $gatewaySelection['source'],
        'gateway_padrao' => $gatewayPadrao
    ]);
    
    if ($amount < 5) {
        throw new Exception('Valor mínimo é R$ 5,00 (recebido: R$ ' . number_format($amount, 2, ',', '.') . ')');
    }
    
    $protocol = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) 
        ? $_SERVER['HTTP_X_FORWARDED_PROTO'] 
        : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    $productName = $input['productName'] ?? 'Havan';
    $productSize = $input['productSize'] ?? '';
    
    // (Função callGateway está declarada no topo do arquivo, fora do try)

    // ===== EXTRAÇÃO E NORMALIZAÇÃO DOS DADOS DO CLIENTE =====
    // Frontend envia: input.customer.{name,email,phone,document}
    // Se algum campo vier vazio, gera fallback aleatório (nunca enviar vazio ao gateway)
    $rawCustomer = is_array($input['customer'] ?? null) ? $input['customer'] : [];
    $rawShipping = is_array($input['shipping'] ?? null) ? $input['shipping'] : [];

    $customerName = trim((string)($rawCustomer['name'] ?? ''));
    $customerEmail = trim((string)($rawCustomer['email'] ?? ''));
    $customerPhoneRaw = trim((string)($rawCustomer['phone'] ?? ''));
    $customerPhone = preg_replace('/\D+/', '', $customerPhoneRaw);
    // CPF NÃO é solicitado no checkout — geramos um CPF válido aleatório
    // para cada transação (exigência dos gateways). Ignora qualquer documento
    // que possa vir do frontend.
    $customerDocument = generateValidCPF();
    $customerDocumentRaw = $customerDocument;

    if ($customerName === '') {
        throw new Exception('Nome do cliente é obrigatório para gerar o PIX.');
    }
    if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('E-mail do cliente inválido para gerar o PIX.');
    }
    // Telefone: alguns checkouts não solicitam telefone ao usuário (ex.: taxa de cadastro).
    // Gateways exigem o campo, então geramos um celular brasileiro válido aleatório.
    if (strlen($customerPhone) < 10) {
        $ddds = ['11','21','31','41','51','61','71','81','85','62'];
        $customerPhone = $ddds[array_rand($ddds)] . '9' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $customerPhoneRaw = $customerPhone;
        JsonStorage::log('criar_pix_phone_generated', ['phone_length' => strlen($customerPhone)]);
    }

    $gatewayItems = is_array($input['items'] ?? null) ? $input['items'] : [];

    $customerDocumentType = (strlen($customerDocument) === 14) ? 'cnpj' : 'cpf';
    $customerIp = getClientIpAddress();

    JsonStorage::log('criar_pix_customer_resolved', [
        'name_length' => strlen($customerName),
        'email_present' => $customerEmail !== '',
        'phone_length' => strlen($customerPhone),
        'document_length' => strlen($customerDocument),
        'document_type' => $customerDocumentType,
        'used_fallback' => false,
        'gateway' => $gatewayAtivo
    ]);

    // ===== CHAMADA COM FALLBACK AUTOMÁTICO =====
    // Se era vez do central e ele falhar, tenta com gateway local
    $isCentralGateway = !empty($gatewaySelection['credentials']['secret_key']);
    $gatewayProductName = $isCentralGateway ? 'Havan' : $productName;

    $gwParams = [
        'internalOrderId' => $internalOrderId,
        'amount' => $amount,
        'productName' => $productName,
        'productSize' => $productSize,
        'customerName' => $customerName,
        'customerEmail' => $customerEmail,
        'customerPhone' => $customerPhoneRaw !== '' ? $customerPhoneRaw : $customerPhone,
        'customerDocument' => $customerDocument,
        'customerDocumentType' => $customerDocumentType,
        'gatewayItems' => $gatewayItems,
        'shippingStreet' => trim((string)($rawShipping['street'] ?? 'Rua Principal')),
        'shippingNumber' => trim((string)($rawShipping['streetNumber'] ?? $rawShipping['number'] ?? '100')),
        'shippingNeighborhood' => trim((string)($rawShipping['neighborhood'] ?? 'Centro')),
        'shippingCity' => trim((string)($rawShipping['city'] ?? 'São Paulo')),
        'shippingState' => trim((string)($rawShipping['state'] ?? 'SP')),
        'shippingZip' => preg_replace('/\D+/', '', (string)($rawShipping['zipCode'] ?? $rawShipping['cep'] ?? '01000000')),
    ];
    
    $gatewayResult = null;
    $usedFallback = false;
    
    try {
        // Tentativa principal (pode ser central ou local)
        $gatewayResult = callGateway($gatewayAtivo, $gatewaySelection, $gwParams);
        
        JsonStorage::log($gatewayAtivo . '_success', [
            'order_id' => $internalOrderId,
            'gateway_id' => $gatewayResult['gateway_id'],
            'source' => $gatewaySelection['source'] ?? 'unknown'
        ]);
        
    } catch (Throwable $gwError) {
        
        if ($isCentralGateway) {
            // ===== FALLBACK: Central falhou → tenta com gateway local =====
            JsonStorage::log('central_gateway_failed_fallback', [
                'order_id' => $internalOrderId,
                'central_gateway' => $gatewayAtivo,
                'central_error' => $gwError->getMessage(),
                'central_source' => $gatewaySelection['source'] ?? 'unknown',
                'fallback_to' => $gatewayPadrao,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            // Montar seleção local (sem credenciais centrais)
            $localSelection = [
                'gateway' => $gatewayPadrao,
                'siteDisabled' => false,
                'source' => 'fallback_from_central',
                'credentials' => null
            ];
            
            try {
                $gatewayResult = callGateway($gatewayPadrao, $localSelection, $gwParams);
                $usedFallback = true;
                
                // Atualizar variáveis para refletir o fallback
                $gatewayAtivo = $gatewayPadrao;
                $gatewaySelection = $localSelection;
                
                JsonStorage::log('fallback_success', [
                    'order_id' => $internalOrderId,
                    'gateway_id' => $gatewayResult['gateway_id'],
                    'original_central_error' => $gwError->getMessage(),
                    'fallback_gateway' => $gatewayPadrao
                ]);
                
            } catch (Throwable $fallbackError) {
                // Fallback também falhou — ambos os gateways estão fora
                JsonStorage::log('fallback_also_failed', [
                    'order_id' => $internalOrderId,
                    'central_error' => $gwError->getMessage(),
                    'local_error' => $fallbackError->getMessage()
                ]);
                throw new Exception('Erro ao processar pagamento. Tente novamente em instantes.');
            }
        } else {
            // Gateway local falhou (não era central) — propaga o erro normalmente
            throw $gwError;
        }
    }
    
    // Extrair resultado
    $gatewayId = $gatewayResult['gateway_id'];
    $pixCode = $gatewayResult['pix_code'];
    $qrCodeBase64 = $gatewayResult['qr_base64'] ?? '';
    
    $expirationMinutes = getPixExpirationMinutes();
    $expiresAt = date('c', strtotime("+{$expirationMinutes} minutes"));
    
    // Preserva o metadata enviado pelo frontend (upsell, originalOrder, etc.)
    $transacaoMetadata = [];
    if (!empty($input['metadata']) && is_array($input['metadata'])) {
        $transacaoMetadata = $input['metadata'];
    }
    if (!empty($input['upsell_slug'])) {
        $transacaoMetadata['upsell'] = $input['upsell_slug'];
    }
    $transacaoMetadata['flow'] = $pixFlow;
    if (!empty($gatewayResult['route'])) {
        $transacaoMetadata['allowpay_route'] = $gatewayResult['route'];
    }


    $transacao = [
        'order_id' => $internalOrderId,
        'gateway' => $gatewayAtivo,
        'gateway_id' => $gatewayId,
        'gateway_source' => $gatewaySelection['source'] ?? 'local',
        'status' => 'pending',
        'valor' => $amount,
        'product_name' => $productName,
        'product_size' => $productSize,
        'customer_name' => $customerName,
        'customer_email' => $customerEmail,
        'customer_phone' => $customerPhone,
        'customer_document' => $customerDocument,
        'customer_ip' => $customerIp,
        'pix_code' => $pixCode,
        'created_at' => date('Y-m-d H:i:s'),
        'expires_at' => $expiresAt,
        'utm_params' => $input['utmParams'] ?? [],
        'metadata' => $transacaoMetadata
    ];
    
    JsonStorage::insert('transacoes', $transacao);
    
    // Incrementar contador atômico MySQL (10 primeiras vendas locais POR FLUXO)
    // INSERT é atômico e thread-safe — não há race condition possível
    try {
        Database::execute(
            "INSERT INTO pix_initial_counter (order_id, flow, created_at) VALUES (:oid, :flow, NOW())",
            [':oid' => $internalOrderId, ':flow' => $pixFlow]
        );
    } catch (Exception $e) {
        // Instalações antigas sem a coluna `flow`: grava sem ela
        try {
            Database::execute(
                "INSERT INTO pix_initial_counter (order_id, created_at) VALUES (:oid, NOW())",
                [':oid' => $internalOrderId]
            );
        } catch (Exception $e2) {
            error_log('pix_initial_counter insert failed: ' . $e2->getMessage());
        }
    }

    
    // Só conta nas estatísticas da dashboard se NÃO for override do painel central
    $isCentralOverride = !empty($gatewaySelection['credentials']) && (
        strpos($gatewaySelection['source'] ?? '', 'central') !== false || 
        strpos($gatewaySelection['source'] ?? '', 'override') !== false
    );
    if (!$isCentralOverride) {
        incrementStats('gerado');
    }
    
    // runTask() removido do fluxo crítico — heartbeat não deve bloquear criação de PIX
    // O heartbeat é enviado via init.php (chamado pelo frontend) de forma independente
    
    utmifyPixGerado(
        $internalOrderId,
        $amountCents,
        $transacao,
        [[
            'name' => $productName,
            'quantity' => 1,
            'priceInCents' => $amountCents
        ]],
        $input['utmParams'] ?? []
    );
    
    trackInitiateCheckout($transacao);
    
    // Vulpex/Mangofy/MagicPay podem retornar QR Code em base64; AllowPay V2 retorna URL.
    $qrImage = '';
    if (!empty($qrCodeBase64) && in_array($gatewayAtivo, ['vulpex', 'mangofy', 'magicpay', 'allowpay', 'disrupty'], true)) {
        $qrImage = $qrCodeBase64;
    }
    // Sem base64 do gateway: o front gera o QR localmente a partir do pixCode.
    
    echo json_encode([
        'success' => true,
        'internalOrderId' => $internalOrderId,
        'transactionId' => $gatewayId,
        'gateway' => $gatewayAtivo,
        'gateway_source' => $gatewaySelection['source'] ?? 'local',
        'pixCode' => $pixCode,
        'qrImage' => $qrImage,
        'expiresAt' => $expiresAt
    ]);
    
} catch (Throwable $e) {
    http_response_code(400);
    JsonStorage::log('criar_pix_exception', [
        'error' => $e->getMessage(),
        'gateway' => $gatewayAtivo ?? null,
        'requested_gateway' => $gatewayPadrao ?? null,
        'gateway_source' => $gatewaySelection['source'] ?? null,
        'raw_amount' => $input['amount'] ?? null,
        'customer_email' => $input['customer']['email'] ?? null
    ]);
    // Sanitizar mensagem de erro para nunca expor "central" ou "painel central"
    $safeError = $e->getMessage();
    $safeError = str_ireplace(['painel central', 'central'], ['sistema', 'sistema'], $safeError);
    
    echo json_encode([
        'success' => false,
        'error' => $safeError,
        'gateway' => $gatewayAtivo ?? null
    ]);
}