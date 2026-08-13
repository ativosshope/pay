<?php
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/storage.php';
require_once __DIR__ . '/services/payment_compensation.php';

setApiHeaders();

try {
    $rawPayload = file_get_contents('php://input');
    $payload = json_decode($rawPayload, true);

    if (!is_array($payload)) {
        throw new Exception('Payload inválido');
    }

    JsonStorage::log('mangofy_webhook_raw', [
        'payment_code' => $payload['payment_code'] ?? 'unknown',
        'external_code' => $payload['external_code'] ?? 'unknown',
        'payment_status' => $payload['payment_status'] ?? 'unknown',
        'payment_method' => $payload['payment_method'] ?? 'unknown'
    ]);

    // Aceita apenas PIX
    $method = strtolower($payload['payment_method'] ?? 'pix');
    if ($method !== 'pix') {
        echo json_encode(['success' => true, 'ignored' => 'non-pix']);
        exit;
    }

    $rawStatus = strtolower($payload['payment_status'] ?? '');
    $status = normalizePaymentStatus('mangofy', $rawStatus);

    $internalOrderId = $payload['external_code'] ?? null;
    $paymentCode = $payload['payment_code'] ?? null;

    JsonStorage::log('mangofy_status_processing', [
        'raw_status' => $rawStatus,
        'mapped_status' => $status,
        'internal_order_id' => $internalOrderId,
        'payment_code' => $paymentCode,
        'is_paid' => ($status === 'paid')
    ]);

    $order = null;
    if ($internalOrderId) {
        $order = JsonStorage::findByOrderId('transacoes', $internalOrderId);
    }
    if (!$order && $paymentCode) {
        $order = JsonStorage::findBy('transacoes', 'gateway_id', $paymentCode);
        if ($order) {
            JsonStorage::log('mangofy_found_by_gateway_id', [
                'gateway_id' => $paymentCode,
                'order_id' => $order['order_id']
            ]);
        }
    }

    if (!$order) {
        JsonStorage::log('mangofy_order_not_found', [
            'internalOrderId' => $internalOrderId,
            'paymentCode' => $paymentCode
        ]);
        throw new Exception('Transação não encontrada: ' . ($internalOrderId ?? 'ID desconhecido'));
    }

    $approvedAt = null;
    if (!empty($payload['approved_at'])) {
        $approvedTimestamp = strtotime($payload['approved_at']);
        if ($approvedTimestamp !== false) {
            $approvedAt = date('Y-m-d H:i:s', $approvedTimestamp);
        }
    }
    $result = compensatePayment($order, 'mangofy', $rawStatus, $approvedAt);

    JsonStorage::log('mangofy_status_updated', [
        'order_id' => $order['order_id'],
        'old_status' => $order['status'] ?? 'unknown',
        'new_status' => $result['status'],
        'raw_status' => $rawStatus,
        'effects_claimed' => $result['effects_claimed'] ?? false
    ]);

    echo json_encode(['success' => true, 'status' => $result['status']]);

} catch (Throwable $e) {
    http_response_code(500);
    try {
        JsonStorage::log('mangofy_webhook_error', ['error' => $e->getMessage()]);
    } catch (Throwable $logError) {
        error_log('mangofy webhook log failed: ' . $logError->getMessage());
    }
    echo json_encode(['success' => false, 'error' => 'Não foi possível processar a confirmação.']);
}
