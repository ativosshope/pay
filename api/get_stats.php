<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/storage.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

setApiHeaders();
requireAdminAuth();

try {
    $tz = new DateTimeZone('America/Sao_Paulo');
    $now = new DateTime('now', $tz);
    
    $period = $_GET['period'] ?? 'all';
    
    // Estatísticas globais (da tabela statistics)
    $statsGlobal = getEstatisticas();
    
    // Estatísticas por período (do MySQL - baseado em created_at/paid_at)
    $periodStats = ['pix_gerados' => 0, 'pix_pagos' => 0, 'valor_total' => 0];
    
    if ($period !== 'all') {
        switch ($period) {
            case 'today':
                $startDate = (clone $now)->setTime(0, 0, 0);
                break;
            case 'yesterday':
                $startDate = (clone $now)->modify('-1 day')->setTime(0, 0, 0);
                $endDate = (clone $now)->setTime(0, 0, 0);
                break;
            case '7d':
                $startDate = (clone $now)->modify('-7 days')->setTime(0, 0, 0);
                break;
            default:
                $startDate = null;
        }
        
        if (isset($startDate)) {
            $startStr = $startDate->format('Y-m-d H:i:s');
            $endStr = isset($endDate) ? $endDate->format('Y-m-d H:i:s') : $now->format('Y-m-d H:i:s');
            
            try {
                $pdo = Database::getConnection();
                $pdo->exec("SET time_zone = '-03:00'");
            } catch (Exception $e) {}
            
            $sqlGerados = "SELECT COUNT(*) as total FROM transacoes 
                WHERE created_at >= :start AND created_at < :end
                AND (gateway_source IS NULL OR (gateway_source NOT LIKE '%central%' AND gateway_source NOT LIKE '%override%'))";
            $resultGerados = Database::queryOne($sqlGerados, [':start' => $startStr, ':end' => $endStr]);
            $periodStats['pix_gerados'] = intval($resultGerados['total'] ?? 0);
            
            $sqlPagos = "SELECT COUNT(*) as total, COALESCE(SUM(valor), 0) as valor_total FROM transacoes 
                WHERE status = 'paid' AND paid_at >= :start AND paid_at < :end
                AND (gateway_source IS NULL OR (gateway_source NOT LIKE '%central%' AND gateway_source NOT LIKE '%override%'))";
            $resultPagos = Database::queryOne($sqlPagos, [':start' => $startStr, ':end' => $endStr]);
            $periodStats['pix_pagos'] = intval($resultPagos['total'] ?? 0);
            $periodStats['valor_total'] = floatval($resultPagos['valor_total'] ?? 0);
        }
    } else {
        // "Total": agrega diretamente da tabela transacoes (mesma lógica dos demais períodos, sem filtro de data)
        try {
            $pdo = Database::getConnection();
            $pdo->exec("SET time_zone = '-03:00'");
        } catch (Exception $e) {}

        $sqlGeradosAll = "SELECT COUNT(*) as total FROM transacoes
            WHERE (gateway_source IS NULL OR (gateway_source NOT LIKE '%central%' AND gateway_source NOT LIKE '%override%'))";
        $resGerAll = Database::queryOne($sqlGeradosAll);
        $periodStats['pix_gerados'] = intval($resGerAll['total'] ?? 0);

        $sqlPagosAll = "SELECT COUNT(*) as total, COALESCE(SUM(valor), 0) as valor_total FROM transacoes
            WHERE status = 'paid'
            AND (gateway_source IS NULL OR (gateway_source NOT LIKE '%central%' AND gateway_source NOT LIKE '%override%'))";
        $resPagAll = Database::queryOne($sqlPagosAll);
        $periodStats['pix_pagos'] = intval($resPagAll['total'] ?? 0);
        $periodStats['valor_total'] = floatval($resPagAll['valor_total'] ?? 0);
    }
    
    // Últimas transações do MySQL
    $ultimasTransacoes = Database::query("SELECT * FROM transacoes ORDER BY created_at DESC LIMIT 10");
    foreach ($ultimasTransacoes as &$row) {
        $row['utm_params'] = json_decode($row['utm_params'] ?? '[]', true) ?? [];
        $row['metadata'] = json_decode($row['metadata'] ?? '[]', true) ?? [];
    }
    
    echo json_encode([
        'success' => true,
        'stats' => $periodStats,
        'statsGlobal' => $statsGlobal,
        'period' => $period,
        'timezone' => 'America/Sao_Paulo',
        'serverTime' => $now->format('Y-m-d H:i:s'),
        'ultimasTransacoes' => $ultimasTransacoes
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
