<?php
require_once __DIR__ . '/config/config.php';

setApiHeaders();

try {
    $pixels = getPixels();
    $metaPixels = array_map(function($p) {
        return [
            'pixelId' => $p['pixelId'] ?? '',
            'ativo' => $p['ativo'] ?? false
        ];
    }, $pixels['meta_pixels'] ?? []);
    
    $tiktokPixels = array_map(function($p) {
        return [
            'pixelId' => $p['pixelId'] ?? '',
            'ativo' => $p['ativo'] ?? false
        ];
    }, $pixels['tiktok_pixels'] ?? []);
    
    // Fetch fretes from DB
    $fretes = getFretes();
    
    $business = getBusinessConfig();
    
    echo json_encode([
        'success' => true,
        'config' => [
            'limite_produtos' => $business['limite_produtos'] ?? 2
        ],
        'metaPixels' => $metaPixels,
        'tiktokPixels' => $tiktokPixels,
        'fretes' => $fretes,
        'limiteProdutos' => $business['limite_produtos'] ?? 2,
        'exigirCpf' => $business['exigir_cpf'] ?? false,
        'orderBumpsAtivo' => $business['order_bumps_ativo'] ?? true,
        'taxaProtecao' => [
            'ativo' => $business['taxa_protecao']['ativo'] ?? true,
            'valor' => $business['taxa_protecao']['valor'] ?? 990,
            'nome' => $business['taxa_protecao']['nome'] ?? 'Taxa de Proteção de Entrega'
        ],
        'taxaCadastro' => [
            'valor' => $business['taxa_cadastro']['valor'] ?? 0
        ],
        'upsell' => [
            'ativo' => $business['upsell']['ativo'] ?? false,
            'link' => $business['upsell']['link'] ?? '',
            'delay' => $business['upsell']['delay'] ?? 3
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
