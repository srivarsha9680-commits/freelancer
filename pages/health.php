<?php
// Temporary health-check page. Remove after debugging.
if (!isset($_GET['key']) || $_GET['key'] !== 'debugkey123') {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}
// Load config to surface DB availability and fallback info.
require_once __DIR__ . '/../config/database.php';
header('Content-Type: text/plain; charset=utf-8');
echo "Scope Creep Defender - health check\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Server software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'n/a') . "\n";
echo "App URL: " . (defined('APP_URL') ? APP_URL : 'n/a') . "\n";
echo "DB_AVAILABLE: " . (defined('DB_AVAILABLE') && DB_AVAILABLE ? '1' : '0') . "\n";
echo "DB_ERROR_MESSAGE: " . (defined('DB_ERROR_MESSAGE') ? DB_ERROR_MESSAGE : '') . "\n";
$drivers = defined('PDO') ? implode(',', PDO::getAvailableDrivers()) : 'none';
echo "PDO drivers: " . $drivers . "\n";
// If PDO is available, try a lightweight query for SQLite fallback
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->query("SELECT 1");
        $stmt->execute();
        echo "PDO query OK\n";
    } catch (Throwable $t) {
        echo "PDO query failed: " . $t->getMessage() . "\n";
    }
} else {
    echo "PDO not configured\n";
}

echo "Document root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'n/a') . "\n";

echo "End of health check\n";
