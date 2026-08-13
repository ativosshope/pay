<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';

setApiHeaders();
ensureSecureSession();

try {
    $ip = getClientIp();
    
    // Verifica rate limit ANTES de processar
    if (isRateLimited($ip)) {
        $remaining = getRemainingBlockTime($ip);
        $minutes = ceil($remaining / 60);
        http_response_code(429);
        echo json_encode([
            'success' => false, 
            'error' => "Muitas tentativas. Tente novamente em {$minutes} minuto(s).",
            'retry_after' => $remaining
        ]);
        exit;
    }
    
    // Extrai username e senha do request
    $password = '';
    $username = '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (is_string($contentType) && stripos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (is_array($input)) {
            $password = (string)($input['admin_password'] ?? $input['password'] ?? $input['senha'] ?? '');
            $username = (string)($input['username'] ?? $input['usuario'] ?? '');
        }
    } else {
        $password = (string)(
            $_POST['admin_password'] ??
            $_POST['password'] ??
            $_POST['senha'] ??
            $_REQUEST['admin_password'] ??
            $_REQUEST['password'] ??
            $_REQUEST['senha'] ??
            ''
        );
        $username = (string)($_POST['username'] ?? $_REQUEST['username'] ?? '');
    }

    $password = trim($password);
    $username = trim($username);
    
    if ($password === '' || $username === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Usuário e senha são obrigatórios']);
        exit;
    }

    // Verifica senha com Argon2id
    if (!verifyAdminPasswordSecure($password, $username)) {
        registerLoginAttempt($ip, false);
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Usuário ou senha inválidos']);
        exit;
    }

    // Login bem-sucedido
    registerLoginAttempt($ip, true);
    setAdminAuthenticated(true);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
