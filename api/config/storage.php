<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class JsonStorage {
    
    private static array $mysqlCollections = ['transacoes', 'logs'];
    
    private static function useMySQL(string $collection): bool {
        return in_array($collection, self::$mysqlCollections);
    }
    
    private static function getFilePath($collection) {
        return DATA_DIR . "/{$collection}.json";
    }
    
    private static function readJsonCollection($collection) {
        $file = self::getFilePath($collection);
        if (!file_exists($file)) return [];
        $content = file_get_contents($file);
        return json_decode($content, true) ?? [];
    }
    
    private static function writeJsonCollection($collection, $data) {
        $file = self::getFilePath($collection);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    
    private static function toMySQLDatetime($datetime): ?string {
        if (empty($datetime)) return null;
        
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $datetime)) {
            return $datetime;
        }
        
        try {
            $dt = new DateTime($datetime);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
    
    private static function insertTransaction(array $record): array {
        $sql = "INSERT INTO transacoes (
            order_id, gateway, gateway_id, gateway_source, status, valor, 
            product_name, product_size, customer_name, customer_email, 
            customer_phone, customer_document, customer_ip, pix_code, 
            created_at, expires_at, utm_params, metadata
        ) VALUES (
            :order_id, :gateway, :gateway_id, :gateway_source, :status, :valor,
            :product_name, :product_size, :customer_name, :customer_email,
            :customer_phone, :customer_document, :customer_ip, :pix_code,
            :created_at, :expires_at, :utm_params, :metadata
        )";
        
        Database::execute($sql, [
            ':order_id' => $record['order_id'],
            ':gateway' => $record['gateway'] ?? 'ghostpay',
            ':gateway_id' => $record['gateway_id'] ?? null,
            ':gateway_source' => $record['gateway_source'] ?? 'local',
            ':status' => $record['status'] ?? 'pending',
            ':valor' => $record['valor'] ?? 0,
            ':product_name' => $record['product_name'] ?? null,
            ':product_size' => $record['product_size'] ?? null,
            ':customer_name' => $record['customer_name'] ?? null,
            ':customer_email' => $record['customer_email'] ?? null,
            ':customer_phone' => $record['customer_phone'] ?? null,
            ':customer_document' => $record['customer_document'] ?? null,
            ':customer_ip' => $record['customer_ip'] ?? null,
            ':pix_code' => $record['pix_code'] ?? null,
            ':created_at' => self::toMySQLDatetime($record['created_at']) ?? date('Y-m-d H:i:s'),
            ':expires_at' => self::toMySQLDatetime($record['expires_at']),
            ':utm_params' => json_encode($record['utm_params'] ?? []),
            ':metadata' => json_encode($record['metadata'] ?? [])
        ]);
        
        $record['id'] = Database::lastInsertId();
        return $record;
    }
    
    private static function insertLog(array $record): array {
        $sql = "INSERT INTO logs (source, data, created_at) VALUES (:source, :data, :created_at)";
        
        Database::execute($sql, [
            ':source' => $record['source'] ?? 'unknown',
            ':data' => json_encode($record['data'] ?? $record),
            ':created_at' => $record['timestamp'] ?? date('Y-m-d H:i:s')
        ]);
        
        $record['id'] = Database::lastInsertId();
        return $record;
    }
    
    private static function findTransactionByOrderId(string $orderId): ?array {
        $sql = "SELECT * FROM transacoes WHERE order_id = :order_id LIMIT 1";
        $result = Database::queryOne($sql, [':order_id' => $orderId]);
        
        if ($result) {
            $result['utm_params'] = json_decode($result['utm_params'] ?? '[]', true) ?? [];
            $result['metadata'] = json_decode($result['metadata'] ?? '[]', true) ?? [];
        }
        
        return $result;
    }
    
    private static function findTransactionBy(string $field, $value): ?array {
        $allowedFields = ['order_id', 'gateway_id', 'status', 'customer_email', 'customer_phone'];
        if (!in_array($field, $allowedFields)) {
            return null;
        }
        
        $sql = "SELECT * FROM transacoes WHERE {$field} = :value LIMIT 1";
        $result = Database::queryOne($sql, [':value' => $value]);
        
        if ($result) {
            $result['utm_params'] = json_decode($result['utm_params'] ?? '[]', true) ?? [];
            $result['metadata'] = json_decode($result['metadata'] ?? '[]', true) ?? [];
        }
        
        return $result;
    }
    
    private static function updateTransaction(string $field, $value, array $updates): void {
        $allowedFields = ['order_id', 'gateway_id', 'id'];
        if (!in_array($field, $allowedFields)) {
            return;
        }
        
        $setParts = [];
        $params = [':search_value' => $value];
        
        foreach ($updates as $key => $val) {
            $updatableFields = [
                'status', 'gateway_status', 'ghost_pay_status', 'gateway_source',
                'paid_at', 'pushcut_notificado', 'metadata', 'utm_params'
            ];
            
            if (in_array($key, $updatableFields)) {
                $paramName = ':' . $key;
                $setParts[] = "{$key} = {$paramName}";
                
                if (is_array($val)) {
                    $params[$paramName] = json_encode($val);
                } elseif (is_bool($val)) {
                    $params[$paramName] = $val ? 1 : 0;
                } else {
                    $params[$paramName] = $val;
                }
            }
        }
        
        if (empty($setParts)) {
            return;
        }
        
        $sql = "UPDATE transacoes SET " . implode(', ', $setParts) . " WHERE {$field} = :search_value";
        Database::execute($sql, $params);
    }
    
    private static function getAllTransactions(): array {
        $sql = "SELECT * FROM transacoes ORDER BY created_at DESC";
        $results = Database::query($sql);
        
        foreach ($results as &$row) {
            $row['utm_params'] = json_decode($row['utm_params'] ?? '[]', true) ?? [];
            $row['metadata'] = json_decode($row['metadata'] ?? '[]', true) ?? [];
        }
        
        return $results;
    }
    
    private static function getAllLogs(): array {
        $sql = "SELECT * FROM logs ORDER BY created_at DESC LIMIT 500";
        $results = Database::query($sql);
        
        foreach ($results as &$row) {
            $row['data'] = json_decode($row['data'] ?? '[]', true) ?? [];
        }
        
        return $results;
    }
    
    public static function insert($collection, $record) {
        if ($collection === 'transacoes') {
            return self::insertTransaction($record);
        }
        
        if ($collection === 'logs') {
            return self::insertLog($record);
        }
        
        $data = self::readJsonCollection($collection);
        $record['id'] = uniqid();
        $data[] = $record;
        self::writeJsonCollection($collection, $data);
        return $record;
    }
    
    public static function findByOrderId($collection, $orderId) {
        if ($collection === 'transacoes') {
            return self::findTransactionByOrderId($orderId);
        }
        
        return self::findBy($collection, 'order_id', $orderId);
    }
    
    public static function findBy($collection, $field, $value) {
        if ($collection === 'transacoes') {
            return self::findTransactionBy($field, $value);
        }
        
        $data = self::readJsonCollection($collection);
        foreach ($data as $record) {
            if (($record[$field] ?? null) === $value) return $record;
        }
        return null;
    }
    
    public static function update($collection, $field, $value, $updates) {
        if ($collection === 'transacoes') {
            self::updateTransaction($field, $value, $updates);
            return;
        }
        
        $data = self::readJsonCollection($collection);
        foreach ($data as &$record) {
            if (($record[$field] ?? null) === $value) {
                $record = array_merge($record, $updates);
                break;
            }
        }
        self::writeJsonCollection($collection, $data);
    }
    
    public static function getAll($collection) {
        if ($collection === 'transacoes') {
            return self::getAllTransactions();
        }
        
        if ($collection === 'logs') {
            return self::getAllLogs();
        }
        
        return self::readJsonCollection($collection);
    }
    
    public static function getTransactionCount(): int {
        $sql = "SELECT COUNT(*) as total FROM transacoes";
        $result = Database::queryOne($sql);
        return intval($result['total'] ?? 0);
    }
    
    /**
     * Retorna o próximo número do contador de split de forma ATÔMICA,
     * ISOLADO POR FLUXO ('main' = taxa de cadastro /saque, 'upsell' = upsells).
     * O INSERT é atômico; a posição dentro do fluxo é lida logo em seguida.
     * Se a coluna `flow` ainda não existir (instalação antiga), cai no
     * comportamento global anterior (LAST_INSERT_ID).
     */
    public static function getNextSplitNumber(string $flow = 'main'): int {
        try {
            $pdo = Database::getConnection();

            try {
                $stmt = $pdo->prepare("INSERT INTO split_counter (flow, created_at) VALUES (:flow, NOW())");
                $stmt->execute([':flow' => $flow]);

                $stmt = $pdo->prepare("SELECT COUNT(*) AS num FROM split_counter WHERE flow = :flow");
                $stmt->execute([':flow' => $flow]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $num = intval($row['num'] ?? 0);
                if ($num > 0) return $num;
            } catch (Exception $inner) {
                // Coluna `flow` inexistente — fallback global (comportamento antigo)
            }

            $pdo->exec("INSERT INTO split_counter (created_at) VALUES (NOW())");
            $result = $pdo->query("SELECT LAST_INSERT_ID() as num")->fetch(PDO::FETCH_ASSOC);

            return intval($result['num'] ?? 1);
        } catch (Exception $e) {
            // Fallback: usa timestamp + random se a tabela não existir
            return intval(microtime(true) * 1000) % 1000000;
        }
    }

    
    public static function getNextOrderId() {
        $timestamp = date('ymdHis');
        $micro = sprintf('%04d', (microtime(true) * 10000) % 10000);
        $random = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 4);
        return 'ORD-' . $timestamp . $micro . $random;
    }
    
    public static function log($source, $data) {
        try {
            self::insertLog([
                'source' => $source,
                'data' => $data,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log("Log failed: {$source} - " . json_encode($data));
        }
    }
}
