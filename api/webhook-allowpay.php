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
    
    // Log detalhado do webhook recebido
    JsonStorage::log('allowpay_webhook_raw', [
        'raw_status' => $payload['data']['status'] ?? $payload['status'] ?? 'unknown',
        'transaction_id' => $payload['data']['id'] ?? $payload['id'] ?? 'unknown',
        'type' => $payload['type'] ?? 'unknown'
    ]);
    
    $transaction = $payload['data'] ?? $payload;
    if (!is_array($transaction)) {
        throw new Exception('Transação inválida no payload');
    }
    
    $metadata = $transaction['metadata'] ?? null;
    if (is_string($metadata)) {
        $metadata = json_decode($metadata, true);
    }
    
    $internalOrderId = (is_array($metadata) ? ($metadata['internalOrderId'] ?? $metadata['order_id'] ?? null) : null)
        ?? $transaction['internalOrderId']
        ?? $transaction['order_id']
        ?? $transaction['externalRef']
        ?? $transaction['items'][0]['externalRef']
        ?? null;
    
    $rawStatus = strtolower($transaction['status'] ?? '');
    $status = normalizePaymentStatus('allowpay', $rawStatus);
    
    // Log do status processado
    JsonStorage::log('allowpay_status_processing', [
        'raw_status' => $rawStatus,
        'mapped_status' => $status,
        'internal_order_id' => $internalOrderId,
        'is_paid' => ($status === 'paid')
    ]);
    
    $order = $internalOrderId ? JsonStorage::findByOrderId('transacoes', $internalOrderId) : null;
    
    if (!$order) {
        $allowPayId = $transaction['id'] ?? $transaction['txid'] ?? $transaction['transaction_id'] ?? null;
        $order = $allowPayId ? JsonStorage::findBy('transacoes', 'gateway_id', $allowPayId) : null;
        
        if ($order) {
            JsonStorage::log('allowpay_found_by_gateway_id', [
                'gateway_id' => $allowPayId,
                'order_id' => $order['order_id']
            ]);
        }
    }
    
    if (!$order) {
        JsonStorage::log('allowpay_order_not_found', [
            'internal_order_id' => $internalOrderId,
            'gateway_id' => $transaction['id'] ?? $transaction['txid'] ?? null
        ]);
        throw new Exception('Transação não encontrada');
    }

    $result = compensatePayment($order, 'allowpay', $rawStatus);
    
    // Log da atualização
    JsonStorage::log('allowpay_status_updated', [
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
        JsonStorage::log('allowpay_webhook_error', ['error' => $e->getMessage()]);
    } catch (Throwable $logError) {
        error_log('allowpay webhook log failed: ' . $logError->getMessage());
    }
    echo json_encode(['success' => false, 'error' => 'Não foi possível processar a confirmação.']);
}
