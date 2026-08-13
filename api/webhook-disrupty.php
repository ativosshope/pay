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

    $eventType = strtoupper((string)($payload['type'] ?? 'TRANSACTION'));

    JsonStorage::log('disrupty_webhook_raw', [
        'type' => $eventType,
        'raw_status' => $payload['status'] ?? 'unknown',
        'transaction_id' => $payload['transactionId'] ?? $payload['id'] ?? 'unknown'
    ]);

    // Eventos de saque não afetam vendas.
    if ($eventType === 'WITHDRAWAL') {
        echo json_encode(['success' => true, 'ignored' => 'withdrawal']);
        exit;
    }

    $transaction = $payload['data'] ?? $payload['sale'] ?? $payload;
    if (!is_array($transaction)) $transaction = $payload;

    $metadata = $transaction['metadata'] ?? $payload['metadata'] ?? null;
    if (is_string($metadata)) $metadata = json_decode($metadata, true);

    $internalOrderId = (is_array($metadata) ? ($metadata['internalOrderId'] ?? $metadata['order_id'] ?? null) : null)
        ?? $transaction['orderId']
        ?? $transaction['externalId']
        ?? $transaction['externalRef']
        ?? $payload['orderId']
        ?? $payload['externalId']
        ?? null;

    $rawStatus = (string)($transaction['status'] ?? $payload['status'] ?? '');
    $status = normalizePaymentStatus('disrupty', $rawStatus);

    JsonStorage::log('disrupty_status_processing', [
        'raw_status' => $rawStatus,
        'mapped_status' => $status,
        'internal_order_id' => $internalOrderId,
        'is_paid' => ($status === 'paid')
    ]);

    $order = $internalOrderId ? JsonStorage::findByOrderId('transacoes', $internalOrderId) : null;

    if (!$order) {
        $dsId = $transaction['id'] ?? $payload['transactionId'] ?? $payload['id'] ?? null;
        $order = ($dsId !== null) ? JsonStorage::findBy('transacoes', 'gateway_id', (string)$dsId) : null;
        if ($order) {
            JsonStorage::log('disrupty_found_by_gateway_id', [
                'gateway_id' => (string)$dsId,
                'order_id' => $order['order_id']
            ]);
        }
    }

    if (!$order) {
        JsonStorage::log('disrupty_order_not_found', [
            'internal_order_id' => $internalOrderId,
            'gateway_id' => $transaction['id'] ?? $payload['id'] ?? null
        ]);
        echo json_encode(['success' => true, 'ignored' => 'order_not_found']);
        exit;
    }

    $paidAtSource = $transaction['paidAt'] ?? $transaction['date'] ?? $payload['date'] ?? null;
    $paidAt = $paidAtSource ? date('Y-m-d H:i:s', strtotime($paidAtSource)) : null;

    $result = compensatePayment($order, 'disrupty', $rawStatus, $paidAt);

    JsonStorage::log('disrupty_status_updated', [
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
        JsonStorage::log('disrupty_webhook_error', ['error' => $e->getMessage()]);
    } catch (Throwable $logError) {
        error_log('disrupty webhook log failed: ' . $logError->getMessage());
    }
    echo json_encode(['success' => false, 'error' => 'Não foi possível processar a confirmação.']);
}
