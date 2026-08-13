<?php
/**
 * Autenticação Admin com Argon2id, Rate Limit e Sessão Segura
 */

define('RATE_LIMIT_MAX_ATTEMPTS', 5);
define('RATE_LIMIT_WINDOW_SECONDS', 600); // 10 minutos
define('RATE_LIMIT_FILE', DATA_DIR . '/rate_limit.json');

/**
 * Hash de senha com Argon2id
 */
function hashPasswordArgon2id($password) {
    return password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536, // 64MB
        'time_cost' => 4,
        'threads' => 1
    ]);
}

/**
 * Verifica senha com Argon2id (compatível com plain text para migração)
 */
function verifyPasswordArgon2id($password, $storedHash) {
    $password = is_string($password) ? trim($password) : '';
    $storedHash = is_string($storedHash) ? trim($storedHash) : '';
    
    if ($password === '' || $storedHash === '') {
        return false;
    }
    
    // Se é um hash Argon2id válido
    if (strpos($storedHash, '$argon2id$') === 0) {
        return password_verify($password, $storedHash);
    }
    
    // Compatibilidade com SHA1 antigo
    if (preg_match('/^[a-f0-9]{40}$/i', $storedHash)) {
        return hash_equals(strtolower($storedHash), strtolower(sha1($password)));
    }
    
    // Compatibilidade com texto plano (para migração inicial)
    return hash_equals($storedHash, $password);
}

/**
 * Obtém hash da senha admin da tabela admin_users
 */
function getAdminPasswordHashSecure($username = 'goecom') {
    require_once __DIR__ . '/database.php';
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT password_hash FROM admin_users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row ? trim($row['password_hash']) : '';
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Salva novo hash da senha admin na DB
 */
function setAdminPasswordHashSecure($newPassword, $username = 'goecom') {
    require_once __DIR__ . '/database.php';
    try {
        $pdo = Database::getConnection();
        $hash = hashPasswordArgon2id($newPassword);
        $stmt = $pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE username = ?");
        $stmt->execute([$hash, $username]);
    } catch (Exception $e) {
        error_log('Failed to update admin password: ' . $e->getMessage());
    }
}

/**
 * Verifica senha do admin
 */
function verifyAdminPasswordSecure($password, $username = 'goecom') {
    $storedHash = getAdminPasswordHashSecure($username);
    $isValid = verifyPasswordArgon2id($password, $storedHash);
    
    // Rehash se necessário (migrar para Argon2id)
    if ($isValid && password_needs_rehash($storedHash, PASSWORD_ARGON2ID)) {
        setAdminPasswordHashSecure($password, $username);
    }
    
    return $isValid;
}

/**
 * Obtém IP do cliente
 */
function getClientIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    // Proxy headers (use com cuidado)
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    
    return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
}

/**
 * Lê dados de rate limit
 */
function getRateLimitData() {
    if (!file_exists(RATE_LIMIT_FILE)) {
        return [];
    }
    $content = file_get_contents(RATE_LIMIT_FILE);
    return json_decode($content, true) ?? [];
}

/**
 * Salva dados de rate limit
 */
function saveRateLimitData($data) {
    file_put_contents(RATE_LIMIT_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

/**
 * Limpa entradas expiradas do rate limit
 */
function cleanExpiredRateLimits(&$data) {
    $now = time();
    foreach ($data as $ip => $info) {
        if (($info['first_attempt'] ?? 0) + RATE_LIMIT_WINDOW_SECONDS < $now) {
            unset($data[$ip]);
        }
    }
}

/**
 * Verifica se IP está bloqueado por rate limit
 */
function isRateLimited($ip) {
    $data = getRateLimitData();
    cleanExpiredRateLimits($data);
    saveRateLimitData($data);
    
    if (!isset($data[$ip])) {
        return false;
    }
    
    $info = $data[$ip];
    $now = time();
    
    // Verifica se ainda está na janela de tempo
    if ($info['first_attempt'] + RATE_LIMIT_WINDOW_SECONDS > $now) {
        // Verifica se excedeu tentativas
        if ($info['attempts'] >= RATE_LIMIT_MAX_ATTEMPTS) {
            return true;
        }
    }
    
    return false;
}

/**
 * Registra tentativa de login
 */
function registerLoginAttempt($ip, $success) {
    $data = getRateLimitData();
    cleanExpiredRateLimits($data);
    $now = time();
    
    if ($success) {
        // Login bem-sucedido, limpa tentativas
        unset($data[$ip]);
    } else {
        // Falha no login
        if (!isset($data[$ip])) {
            $data[$ip] = [
                'attempts' => 1,
                'first_attempt' => $now
            ];
        } else {
            // Verifica se precisa resetar a janela
            if ($data[$ip]['first_attempt'] + RATE_LIMIT_WINDOW_SECONDS < $now) {
                $data[$ip] = [
                    'attempts' => 1,
                    'first_attempt' => $now
                ];
            } else {
                $data[$ip]['attempts']++;
            }
        }
    }
    
    saveRateLimitData($data);
}

/**
 * Calcula segundos restantes do bloqueio
 */
function getRemainingBlockTime($ip) {
    $data = getRateLimitData();
    
    if (!isset($data[$ip])) {
        return 0;
    }
    
    $info = $data[$ip];
    $now = time();
    $endTime = $info['first_attempt'] + RATE_LIMIT_WINDOW_SECONDS;
    
    return max(0, $endTime - $now);
}

/**
 * Inicia sessão segura
 */
function ensureSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        // Detecta HTTPS
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                   || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                   || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        
        ini_set('session.gc_maxlifetime', 3600);
        
        session_set_cookie_params([
            'lifetime' => 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        session_start();
    }
}

/**
 * Regenera ID da sessão de forma segura
 */
function regenerateSessionSecure() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * Verifica se admin está autenticado via sessão
 */
function isAdminAuthenticated() {
    ensureSecureSession();
    return !empty($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true;
}

/**
 * Marca admin como autenticado
 */
function setAdminAuthenticated($authenticated = true) {
    ensureSecureSession();
    
    if ($authenticated) {
        regenerateSessionSecure();
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_auth_time'] = time();
    } else {
        unset($_SESSION['admin_authenticated']);
        unset($_SESSION['admin_auth_time']);
    }
}

/**
 * Faz logout do admin
 */
function adminLogout() {
    ensureSecureSession();
    $_SESSION = [];
    
    // Destrói cookie da sessão
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

/**
 * Middleware para proteger rotas admin (retorna 403 se não autenticado)
 */
function requireAdminAuth() {
    if (!isAdminAuthenticated()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Faça login primeiro.']);
        exit;
    }
}
