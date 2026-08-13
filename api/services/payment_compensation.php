<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/storage.php';
require_once __DIR__ . '/pushcut.php';
require_once __DIR__ . '/utmify.php';
require_once __DIR__ . '/pixels.php';

function normalizePaymentStatus(string $gateway, $rawStatus): string {
    $status = strtolower(trim((string)$rawStatus));

    $common = [
        'approved' => 'paid',
        'approve' => 'paid',
        'paid' => 'paid',
        'payment_accepted' => 'paid',
        'completed' => 'paid',
        'complete' => 'paid',
        'success' => 'paid',
        'succeeded' => 'paid',
        'settled' => 'paid',
        'confirmed' => 'paid',
        'pago' => 'paid',
        'aprovado' => 'paid',
        'waiting_payment' => 'pending',
        'awaiting_payment' => 'pending',
        'processing' => 'pending',
        'processando' => 'pending',
        'created' => 'pending',
        'generated' => 'pending',
        'pendente' => 'pending',
        'aguardando_pagamento' => 'pending',
        'pending' => 'pending',
        'in_process' => 'pending',
        'in_analisys' => 'pending',
        'review_question' => 'pending',
        'expired' => 'expired',
        'expirado' => 'expired',
        'refused' => 'failed',
        'recusado' => 'failed',
        'rejected' => 'failed',
        'error' => 'failed',
        'failed' => 'failed',
        'canceled' => 'cancelled',
        'cancelled' => 'cancelled',
        'cancelado' => 'cancelled',
        'canceled_antifraud' => 'cancelled',
        'refunded' => 'refunded',
        'estornado' => 'refunded',
        'reembolsado' => 'refunded',
        'partial_refunded' => 'refunded',
        'refunded_requested' => 'refunded',
        'refunded_error' => 'refunded',
        'chargedback' => 'disputed',
        'chargeback' => 'disputed',
        'charge_back' => 'disputed',
        'med' => 'disputed',
        'in_dispute' => 'disputed',
        'em_disputa' => 'disputed',
        'in_protest' => 'disputed'
    ];

    return $common[$status] ?? $status;
}


function compensatePayment(array $order, string $gateway, string $rawStatus, ?string $paidAt = null): array {
    $status = normalizePaymentStatus($gateway, $rawStatus);
    if ($status === '') {
        throw new Exception('Status vazio recebido do gateway');
    }

    $orderId = (string)($order['order_id'] ?? '');
    if ($orderId === '') {
        throw new Exception('Pedido sem order_id');
    }

    if ($status !== 'paid') {
        JsonStorage::update('transacoes', 'order_id', $orderId, [
            'status' => $status,
            'gateway_status' => $rawStatus
        ]);
        return ['status' => $status, 'newly_paid' => false, 'effects_claimed' => false];
    }

    $approvedAt = $paidAt ?: ($order['paid_at'] ?? null) ?: date('Y-m-d H:i:s');
    Database::execute(
        "UPDATE transacoes SET status = 'paid', gateway_status = :raw_status, paid_at = COALESCE(paid_at, :paid_at) WHERE order_id = :order_id",
        [':raw_status' => $rawStatus, ':paid_at' => $approvedAt, ':order_id' => $orderId]
    );

    // Reserva os efeitos exatamente uma vez, inclusive se webhook e polling chegarem juntos.
    $effectsClaimed = Database::execute(
        "UPDATE transacoes SET pushcut_notificado = 1 WHERE order_id = :order_id AND (pushcut_notificado IS NULL OR pushcut_notificado = 0)",
        [':order_id' => $orderId]
    ) === 1;

    $newlyPaid = empty($order['paid_at']);
    if (!$effectsClaimed) {
        JsonStorage::log('payment_compensation_idempotent', [
            'order_id' => $orderId,
            'gateway' => $gateway,
            'raw_status' => $rawStatus
        ]);
        return ['status' => 'paid', 'newly_paid' => $newlyPaid, 'effects_claimed' => false, 'paid_at' => $approvedAt];
    }

    $valor = (float)($order['valor'] ?? 0);
    $valorCentavos = (int)round($valor * 100);
    $source = (string)($order['gateway_source'] ?? 'local');
    $isCentralOverride = strpos($source, 'central') !== false || strpos($source, 'override') !== false;
    $eventPrefix = preg_replace('/[^a-z0-9_]/', '', strtolower($gateway)) ?: 'gateway';

    if (!$isCentralOverride) {
        try { incrementStats('pago', $valor); }
        catch (Throwable $e) { JsonStorage::log($eventPrefix . '_stats_error', ['order_id' => $orderId, 'error' => $e->getMessage()]); }
    }

    try { enviarPushcutNotificacao('pix', $valor); }
    catch (Throwable $e) { JsonStorage::log($eventPrefix . '_pushcut_error', ['order_id' => $orderId, 'error' => $e->getMessage()]); }

    try {
        utmifyPixPago(
            $orderId,
            $valorCentavos,
            $order['created_at'] ?? date('Y-m-d H:i:s'),
            $order,
            [['name' => $order['product_name'] ?? 'Produto', 'priceInCents' => $valorCentavos]],
            $order['utm_params'] ?? []
        );
    } catch (Throwable $e) {
        JsonStorage::log($eventPrefix . '_utmify_error', ['order_id' => $orderId, 'error' => $e->getMessage()]);
    }

    try { trackMetaConversion($order, 'Purchase'); }
    catch (Throwable $e) { JsonStorage::log($eventPrefix . '_meta_error', ['order_id' => $orderId, 'error' => $e->getMessage()]); }

    try {
        trackTiktokEvent($order, 'CompletePayment');
        trackTiktokEvent($order, 'PlaceAnOrder');
    } catch (Throwable $e) {
        JsonStorage::log($eventPrefix . '_tiktok_error', ['order_id' => $orderId, 'error' => $e->getMessage()]);
    }

    JsonStorage::log('payment_compensated', [
        'order_id' => $orderId,
        'gateway' => $gateway,
        'raw_status' => $rawStatus,
        'status' => 'paid',
        'paid_at' => $approvedAt
    ]);

    return ['status' => 'paid', 'newly_paid' => $newlyPaid, 'effects_claimed' => true, 'paid_at' => $approvedAt];
}