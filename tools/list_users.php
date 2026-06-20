<?php
require __DIR__ . '/../config/database.php';
echo 'DB_AVAILABLE=' . (defined('DB_AVAILABLE') && DB_AVAILABLE ? '1' : '0') . PHP_EOL;
echo 'DB_ERROR_MESSAGE=' . (defined('DB_ERROR_MESSAGE') ? DB_ERROR_MESSAGE : '') . PHP_EOL;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->query('SELECT id, email, password_hash, created_at FROM users ORDER BY id DESC LIMIT 20');
        $rows = $stmt->fetchAll();
        if (!$rows) {
            echo "NO_USERS\n";
            exit(0);
        }
        foreach ($rows as $r) {
            $hash = $r['password_hash'] ?? '';
            echo sprintf("USER: %d | %s | %s | %s\n", $r['id'], $r['email'], substr($hash, 0, 40), $r['created_at']);
        }
    } catch (Throwable $e) {
        echo 'QUERY_ERROR: ' . $e->getMessage() . PHP_EOL;
    }
} else {
    echo "NO_PDO\n";
}
