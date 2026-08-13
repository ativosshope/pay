<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/storage.php';

function enviarPushcutNotificacao($tipo, $valor) {
    $pushcutUrls = getPushcutUrls();
    
    if (empty($pushcutUrls)) {
        return ['success' => false, 'message' => 'Nenhuma URL configurada'];
    }
    
    $urlsAtivas = array_filter($pushcutUrls, fn($item) => ($item['enabled'] ?? false) && !empty($item['url']));
    
    if (empty($urlsAtivas)) {
        return ['success' => false, 'message' => 'Nenhuma URL ativa'];
    }
    
    $valorFormatado = 'R$ ' . number_format($valor, 2, ',', '.');
    $titulo = $tipo === 'pix' ? 'Pagamento aprovado no Pix 💰' : 'Pagamento aprovado 💰';
    
    $payload = json_encode([
        'title' => $titulo, 
        'text' => $valorFormatado
    ]);
    
    $results = [];
    
    foreach ($urlsAtivas as $pushcut) {
        $ch = curl_init($pushcut['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10
        ]);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $results[] = ['success' => $httpCode >= 200 && $httpCode < 300];
    }
    
    JsonStorage::log('pushcut', ['tipo' => $tipo, 'valor' => $valor, 'results' => $results]);
    
    return ['success' => true, 'results' => $results];
}