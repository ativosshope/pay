<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config/database.php';

// ═══════════════════════════════════════════════════════════
// REGISTRO CENTRAL DE TABELAS — toda tabela nova deve ser
// adicionada aqui para que o diagnóstico funcione.
// ═══════════════════════════════════════════════════════════

function getRequiredTables(): array {
    return [
        'admin_users' => [
            'label' => 'Usuários Admin',
            'sql' => "CREATE TABLE IF NOT EXISTS admin_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'seed' => function(PDO $pdo) {
                $existing = $pdo->query("SELECT COUNT(*) as cnt FROM admin_users")->fetch();
                if ((int)$existing['cnt'] === 0) {
                    $hash = password_hash('@GoEcom2026', PASSWORD_ARGON2ID, [
                        'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1
                    ]);
                    $stmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)");
                    $stmt->execute(['goecom', $hash]);
                }
            }
        ],
        'gateway_credentials' => [
            'label' => 'Credenciais Gateway',
            'sql' => "CREATE TABLE IF NOT EXISTS gateway_credentials (
                id INT AUTO_INCREMENT PRIMARY KEY,
                gateway_ativo VARCHAR(50) NOT NULL DEFAULT 'ghostpay',
                ghostpay_secret_key VARCHAR(500) DEFAULT '',
                ghostpay_company_id VARCHAR(500) DEFAULT '',
                ghostpay_ativo TINYINT(1) DEFAULT 0,
                allowpay_api_key VARCHAR(500) DEFAULT '',
                allowpay_route VARCHAR(50) DEFAULT '',
                allowpay_ativo TINYINT(1) DEFAULT 0,
                vulpex_api_key VARCHAR(500) DEFAULT '',
                vulpex_api_secret VARCHAR(500) DEFAULT '',
                vulpex_ativo TINYINT(1) DEFAULT 0,
                mangofy_api_key VARCHAR(500) DEFAULT '',
                mangofy_store_code VARCHAR(500) DEFAULT '',
                mangofy_ativo TINYINT(1) DEFAULT 0,
                magicpay_public_key VARCHAR(500) DEFAULT '',
                magicpay_secret_key VARCHAR(500) DEFAULT '',
                magicpay_ativo TINYINT(1) DEFAULT 0,
                disrupty_public_key VARCHAR(500) DEFAULT '',
                disrupty_private_key VARCHAR(500) DEFAULT '',
                disrupty_audience VARCHAR(20) DEFAULT 'seller',
                disrupty_ativo TINYINT(1) DEFAULT 0,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'seed' => function(PDO $pdo) {
                $cnt = $pdo->query("SELECT COUNT(*) as cnt FROM gateway_credentials")->fetch();
                if ((int)$cnt['cnt'] === 0) {
                    $pdo->exec("INSERT INTO gateway_credentials (gateway_ativo) VALUES ('ghostpay')");
                }
            }
        ],
        'site_config' => [
            'label' => 'Configurações do Site',
            'sql' => "CREATE TABLE IF NOT EXISTS site_config (
                id INT AUTO_INCREMENT PRIMARY KEY,
                config_key VARCHAR(100) UNIQUE NOT NULL,
                config_value TEXT NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'seed' => function(PDO $pdo) {
                $cnt = $pdo->query("SELECT COUNT(*) as cnt FROM site_config")->fetch();
                if ((int)$cnt['cnt'] === 0) {
                    $defaults = [
                        ['pix_expiration_minutes', '10'], ['limite_produtos', '99'],
                        ['exigir_cpf', '0'], ['taxa_protecao_ativo', '1'],
                        ['taxa_protecao_valor', '990'], ['taxa_protecao_nome', 'Taxa de Proteção de Entrega'],
                        ['upsell_ativo', '0'], ['upsell_link', ''], ['upsell_delay', '3']
                    ];
                    $stmt = $pdo->prepare("INSERT INTO site_config (config_key, config_value) VALUES (?, ?)");
                    foreach ($defaults as $d) { $stmt->execute($d); }
                }
                // Chaves do funil atual (garantidas mesmo em bancos antigos)
                $ins = $pdo->prepare("INSERT IGNORE INTO site_config (config_key, config_value) VALUES (?, ?)");
                $ins->execute(['taxa_cadastro_valor', '2990']);
            }

        ],
        'pixels' => [
            'label' => 'Pixels de Rastreamento',
            'sql' => "CREATE TABLE IF NOT EXISTS pixels (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(20) NOT NULL,
                pixel_id VARCHAR(255) NOT NULL,
                access_token VARCHAR(500) DEFAULT '',
                ativo TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_type (type),
                INDEX idx_ativo (ativo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ],
        'notifications' => [
            'label' => 'Notificações',
            'sql' => "CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(50) NOT NULL,
                config_key VARCHAR(100) NOT NULL,
                config_value TEXT NOT NULL DEFAULT '',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_type_key (type, config_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'seed' => function(PDO $pdo) {
                $cnt = $pdo->query("SELECT COUNT(*) as cnt FROM notifications")->fetch();
                if ((int)$cnt['cnt'] === 0) {
                    $stmt = $pdo->prepare("INSERT INTO notifications (type, config_key, config_value) VALUES (?, ?, ?)");
                    $stmt->execute(['utmify', 'ativo', '0']);
                    $stmt->execute(['utmify', 'token', '']);
                    $stmt->execute(['utmify', 'platform', 'NikeShop']);
                }
            }
        ],
        'pushcut_urls' => [
            'label' => 'URLs Pushcut',
            'sql' => "CREATE TABLE IF NOT EXISTS pushcut_urls (
                id INT AUTO_INCREMENT PRIMARY KEY,
                url VARCHAR(1000) NOT NULL,
                enabled TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ],
        'statistics' => [
            'label' => 'Estatísticas',
            'sql' => "CREATE TABLE IF NOT EXISTS statistics (
                id INT AUTO_INCREMENT PRIMARY KEY,
                stat_key VARCHAR(50) UNIQUE NOT NULL,
                stat_value DECIMAL(15,2) NOT NULL DEFAULT 0,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'seed' => function(PDO $pdo) {
                $cnt = $pdo->query("SELECT COUNT(*) as cnt FROM statistics")->fetch();
                if ((int)$cnt['cnt'] === 0) {
                    $stmt = $pdo->prepare("INSERT INTO statistics (stat_key, stat_value) VALUES (?, ?)");
                    $stmt->execute(['pix_gerados', 0]);
                    $stmt->execute(['pix_pagos', 0]);
                    $stmt->execute(['valor_total', 0]);
                }
            }
        ],
        'transacoes' => [
            'label' => 'Transações',
            'sql' => "CREATE TABLE IF NOT EXISTS transacoes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id VARCHAR(50) UNIQUE NOT NULL,
                gateway VARCHAR(50) DEFAULT 'ghostpay',
                gateway_id VARCHAR(100),
                gateway_source VARCHAR(100) DEFAULT 'local',
                status VARCHAR(20) DEFAULT 'pending',
                gateway_status VARCHAR(50),
                ghost_pay_status VARCHAR(50),
                valor DECIMAL(10,2) NOT NULL DEFAULT 0,
                product_name VARCHAR(255),
                product_size VARCHAR(50),
                customer_name VARCHAR(255),
                customer_email VARCHAR(255),
                customer_phone VARCHAR(50),
                customer_document VARCHAR(20),
                customer_ip VARCHAR(50),
                pix_code TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME,
                paid_at DATETIME,
                pushcut_notificado TINYINT(1) DEFAULT 0,
                utm_params JSON,
                metadata JSON,
                INDEX idx_order_id (order_id),
                INDEX idx_status (status),
                INDEX idx_gateway_id (gateway_id),
                INDEX idx_created_at (created_at),
                INDEX idx_paid_at (paid_at),
                INDEX idx_gateway_source (gateway_source)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ],
        'logs' => [
            'label' => 'Logs do Sistema',
            'sql' => "CREATE TABLE IF NOT EXISTS logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(100) NOT NULL,
                data JSON,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_source (source),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ],
        'split_counter' => [
            'label' => 'Controle Interno',
            'sql' => "CREATE TABLE IF NOT EXISTS split_counter (
                id INT AUTO_INCREMENT PRIMARY KEY,
                flow VARCHAR(20) NOT NULL DEFAULT 'main',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_flow (flow)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ],
        'pix_initial_counter' => [
            'label' => 'Sequenciador',
            'sql' => "CREATE TABLE IF NOT EXISTS pix_initial_counter (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id VARCHAR(50) NOT NULL,
                flow VARCHAR(20) NOT NULL DEFAULT 'main',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created (created_at),
                INDEX idx_flow (flow)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ],

        'product_prices' => [
            'label' => 'Preços dos Produtos',
            'sql' => "CREATE TABLE IF NOT EXISTS product_prices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(255) UNIQUE NOT NULL,
                product_name VARCHAR(500) NOT NULL,
                old_price INT NOT NULL DEFAULT 0,
                new_price INT NOT NULL DEFAULT 0,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'seed' => function(PDO $pdo) {
                // Sem catálogo de produtos: o valor do saque fica em site_config.taxa_cadastro_valor
            }
        ],
        'shipping_options' => [
            'label' => 'Opções de Frete',
            'sql' => "CREATE TABLE IF NOT EXISTS shipping_options (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                price INT NOT NULL DEFAULT 0,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active (active),
                INDEX idx_sort_order (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ],
        'order_bumps' => [
            'label' => 'Order Bumps',
            'sql' => "CREATE TABLE IF NOT EXISTS order_bumps (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_slug VARCHAR(255) NOT NULL,
                product_name VARCHAR(500) NOT NULL,
                product_image VARCHAR(1000) NOT NULL DEFAULT '',
                old_price INT NOT NULL DEFAULT 0,
                new_price INT NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active (active),
                INDEX idx_sort_order (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'seed' => function(PDO $pdo) {
                // Order bumps não são semeados: o lojista escolhe quais aparecem no /config.
                return;
            }
        ],
        'upsell_templates' => [
            'label' => 'Templates de Upsell',
            'sql' => "CREATE TABLE IF NOT EXISTS upsell_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(80) UNIQUE NOT NULL,
                template_key VARCHAR(50) NOT NULL DEFAULT 'pendencia',
                title VARCHAR(255) NOT NULL DEFAULT 'Pendência no seu pedido',
                logo_url VARCHAR(500) NOT NULL DEFAULT '',
                status_ok_label VARCHAR(255) NOT NULL DEFAULT 'Pagamento concluído',
                status_warn_label VARCHAR(255) NOT NULL DEFAULT 'Pendência no seu pedido',
                description TEXT NOT NULL,
                problem_title VARCHAR(255) NOT NULL DEFAULT 'Identificamos que existe uma pendência no seu pedido!',
                problem_description TEXT NOT NULL,
                button_label VARCHAR(120) NOT NULL DEFAULT 'Resolver problema',
                pay_button_label VARCHAR(120) NOT NULL DEFAULT 'Pagar agora',
                amount_cents INT NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active_order (active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'seed' => function(PDO $pdo) {
                require_once __DIR__ . '/config/upsell_seed.php';
                if (function_exists('ensureUpsellSeed')) { ensureUpsellSeed($pdo); }
            }
        ],
        'sessions' => [
            'label' => 'Sessões / Analytics',
            'sql' => "CREATE TABLE IF NOT EXISTS sessions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                session_id VARCHAR(64) NOT NULL,
                ip VARCHAR(45) NOT NULL DEFAULT '',
                user_agent VARCHAR(500) NOT NULL DEFAULT '',
                route VARCHAR(500) NOT NULL DEFAULT '/',
                referrer VARCHAR(1000) NOT NULL DEFAULT '',
                country VARCHAR(100) NOT NULL DEFAULT '',
                country_code VARCHAR(4) NOT NULL DEFAULT '',
                region VARCHAR(100) NOT NULL DEFAULT '',
                city VARCHAR(100) NOT NULL DEFAULT '',
                lat DECIMAL(10,6) DEFAULT NULL,
                lng DECIMAL(10,6) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_session (session_id),
                INDEX idx_created (created_at),
                INDEX idx_route (route(120)),
                INDEX idx_country (country_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ],
    ];
}

// ═══════════════════════════════════════════════════════════
// GET = Diagnóstico completo de tabelas
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $pdo = Database::getConnection();
        $required = getRequiredTables();
        
        // Buscar todas as tabelas existentes no banco
        $stmt = $pdo->query("SHOW TABLES");
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $tables = [];
        $missingCount = 0;
        $existingCount = 0;
        
        foreach ($required as $name => $def) {
            $exists = in_array($name, $existingTables);
            $tables[$name] = [
                'label' => $def['label'],
                'exists' => $exists,
            ];
            if ($exists) {
                $existingCount++;
            } else {
                $missingCount++;
            }
        }
        
        echo json_encode([
            'already_initialized' => $missingCount === 0,
            'total' => count($required),
            'existing' => $existingCount,
            'missing' => $missingCount,
            'tables' => $tables,
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'already_initialized' => false,
            'total' => 0,
            'existing' => 0,
            'missing' => 0,
            'tables' => [],
            'error' => 'Erro de conexão: ' . $e->getMessage()
        ]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════
// POST = Criar tabelas faltantes (ou todas se nenhuma existe)
// ═══════════════════════════════════════════════════════════
try {
    $pdo = Database::getConnection();
    $required = getRequiredTables();
    
    // Buscar tabelas existentes
    $stmt = $pdo->query("SHOW TABLES");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $created = [];
    $skipped = [];
    
    foreach ($required as $name => $def) {
        if (in_array($name, $existingTables)) {
            $skipped[] = $name;
            continue;
        }
        
        // Criar tabela
        $pdo->exec($def['sql']);
        
        // Executar seed se existir
        if (isset($def['seed']) && is_callable($def['seed'])) {
            $def['seed']($pdo);
        }
        
        $created[] = $name;
    }
    
    // Migration: contadores separados por fluxo (main = /saque, upsell) — idempotente
    try {
        $pdo->exec("ALTER TABLE pix_initial_counter ADD COLUMN flow VARCHAR(20) NOT NULL DEFAULT 'main'");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE pix_initial_counter ADD INDEX idx_flow (flow)");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE split_counter ADD COLUMN flow VARCHAR(20) NOT NULL DEFAULT 'main'");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE split_counter ADD INDEX idx_flow (flow)");
    } catch (Exception $e) { /* already exists */ }

    // Migration: add columns if missing (idempotente)

    try {
        $pdo->exec("ALTER TABLE transacoes ADD COLUMN gateway_source VARCHAR(100) DEFAULT 'local' AFTER gateway_id");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE transacoes ADD INDEX idx_gateway_source (gateway_source)");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE transacoes ADD INDEX idx_paid_at (paid_at)");
    } catch (Exception $e) { /* already exists */ }
    // Mangofy gateway columns (idempotente)
    try {
        $pdo->exec("ALTER TABLE gateway_credentials ADD COLUMN mangofy_api_key VARCHAR(500) DEFAULT '' AFTER vulpex_ativo");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE gateway_credentials ADD COLUMN mangofy_store_code VARCHAR(500) DEFAULT '' AFTER mangofy_api_key");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE gateway_credentials ADD COLUMN mangofy_ativo TINYINT(1) DEFAULT 0 AFTER mangofy_store_code");
    } catch (Exception $e) { /* already exists */ }

    // Migration AllowPay V2: api_key/route (idempotente)
    try {
        $pdo->exec("ALTER TABLE gateway_credentials ADD COLUMN allowpay_api_key VARCHAR(500) DEFAULT '' AFTER ghostpay_ativo");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE gateway_credentials ADD COLUMN allowpay_route VARCHAR(50) DEFAULT '' AFTER allowpay_api_key");
    } catch (Exception $e) { /* already exists */ }

    // Migration Magic Pay: public_key/secret_key (idempotente)
    try {
        $pdo->exec("ALTER TABLE gateway_credentials ADD COLUMN magicpay_public_key VARCHAR(500) DEFAULT '' AFTER mangofy_ativo");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE gateway_credentials ADD COLUMN magicpay_secret_key VARCHAR(500) DEFAULT '' AFTER magicpay_public_key");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE gateway_credentials ADD COLUMN magicpay_ativo TINYINT(1) DEFAULT 0 AFTER magicpay_secret_key");
    } catch (Exception $e) { /* already exists */ }

    // Migration Disrupty: public_key/private_key/audience (idempotente)
    try {
        $pdo->exec("ALTER TABLE gateway_credentials ADD COLUMN disrupty_public_key VARCHAR(500) DEFAULT '' AFTER magicpay_ativo");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE gateway_credentials ADD COLUMN disrupty_private_key VARCHAR(500) DEFAULT '' AFTER disrupty_public_key");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE gateway_credentials ADD COLUMN disrupty_audience VARCHAR(20) DEFAULT 'seller' AFTER disrupty_private_key");
    } catch (Exception $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE gateway_credentials ADD COLUMN disrupty_ativo TINYINT(1) DEFAULT 0 AFTER disrupty_audience");
    } catch (Exception $e) { /* already exists */ }

    // Cleanup AllowPay legado: remove colunas antigas (idempotente)
    try {
        $pdo->exec("ALTER TABLE gateway_credentials DROP COLUMN allowpay_secret_key");
    } catch (Exception $e) { /* já removida */ }
    try {
        $pdo->exec("ALTER TABLE gateway_credentials DROP COLUMN allowpay_company_id");
    } catch (Exception $e) { /* já removida */ }

    // Reset credenciais AllowPay antigas se ainda houver lixo no api_key/route herdado
    try {
        $pdo->exec("UPDATE gateway_credentials SET allowpay_ativo = 0 WHERE (allowpay_api_key IS NULL OR allowpay_api_key = '') AND allowpay_ativo = 1");
    } catch (Exception $e) { /* ok */ }

    // Migração: remove qualquer catálogo de produtos legado (Crocs/Jibbitz).
    try {
        $pdo->exec("DELETE FROM product_prices");
    } catch (Exception $e) { /* ok */ }

    // Order bumps não são semeados: o lojista escolhe quais aparecem no /config.

    
    echo json_encode([
        'success' => true,
        'message' => count($created) > 0
            ? 'Tabelas criadas com sucesso: ' . implode(', ', $created)
            : 'Todas as tabelas já existiam.',
        'created' => $created,
        'skipped' => $skipped,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao inicializar database: ' . $e->getMessage()
    ]);
}
