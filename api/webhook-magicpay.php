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
        echo json_encode(['success' => true, 'ignored' => 'invalid_payload']);
        exit;
    }

    JsonStorage::log('magicpay_webhook_raw', [
        'raw_status' => $payload['data']['status'] ?? $payload['transaction']['status'] ?? $payload['status'] ?? 'unknown',
        'transaction_id' => $payload['data']['id'] ?? $payload['transaction']['id'] ?? $payload['id'] ?? 'unknown',
        'type' => $payload['type'] ?? $payload['event'] ?? 'unknown'
    ]);

    $transaction = $payload['data'] ?? $payload['transaction'] ?? $payload;
    if (!is_array($transaction)) $transaction = $payload;

    $metadata = $transaction['metadata'] ?? $payload['metadata'] ?? null;
    if (is_string($metadata)) $metadata = json_decode($metadata, true);

    $internalOrderId = (is_array($metadata) ? ($metadata['internalOrderId'] ?? $metadata['order_id'] ?? null) : null)
        ?? $transaction['internalOrderId']
        ?? $transaction['order_id']
        ?? $transaction['orderId']
        ?? $transaction['externalRef']
        ?? $transaction['externalId']
        ?? $transaction['items'][0]['externalRef']
        ?? null;

    $rawStatus = strtolower((string)($transaction['status'] ?? $payload['status'] ?? ''));
    $status = normalizePaymentStatus('magicpay', $rawStatus);

    JsonStorage::log('magicpay_status_processing', [
        'raw_status' => $rawStatus,
        'mapped_status' => $status,
        'internal_order_id' => $internalOrderId,
        'is_paid' => ($status === 'paid')
    ]);

    $order = $internalOrderId ? JsonStorage::findByOrderId('transacoes', $internalOrderId) : null;

    if (!$order) {
        $magicId = $transaction['id'] ?? $transaction['transaction_id'] ?? $payload['id'] ?? null;
        $order = $magicId ? JsonStorage::findBy('transacoes', 'gateway_id', (string)$magicId) : null;
        if ($order) {
            JsonStorage::log('magicpay_found_by_gateway_id', [
                'gateway_id' => (string)$magicId,
                'order_id' => $order['order_id']
            ]);
        }
    }

    if (!$order) {
        JsonStorage::log('magicpay_order_not_found', [
            'internal_order_id' => $internalOrderId,
            'gateway_id' => $transaction['id'] ?? null
        ]);
        echo json_encode(['success' => true, 'ignored' => 'order_not_found']);
        exit;
    }

    $paidAtSource = $transaction['paidAt'] ?? $transaction['paid_at'] ?? null;
    $paidAt = $paidAtSource ? date('Y-m-d H:i:s', strtotime($paidAtSource)) : null;

    $result = compensatePayment($order, 'magicpay', $rawStatus, $paidAt);

    JsonStorage::log('magicpay_status_updated', [
        'order_id' => $order['order_id'],
        'old_status' => $order['status'] ?? 'unknown',
        'new_status' => $result['status'],
        'raw_status' => $rawStatus,
        'effects_claimed' => $result['effects_claimed'] ?? false
    ]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    http_response_code(500);
    try {
        JsonStorage::log('magicpay_webhook_error', ['error' => $e->getMessage()]);
    } catch (Throwable $logError) {
        error_log('magicpay webhook log failed: ' . $logError->getMessage());
    }
    echo json_encode(['success' => false, 'error' => 'Não foi possível processar a confirmação.']);
}
