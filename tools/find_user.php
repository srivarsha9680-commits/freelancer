<?php
require __DIR__ . '/../config/database.php';
$search = 'srivarsha9680@gmail.com';
if (isset($pdo) && $pdo instanceof PDO) {
    $stmt = $pdo->prepare('SELECT id,email,password_hash,created_at FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$search]);
    $r = $stmt->fetch();
    if ($r) {
        echo sprintf("FOUND: %d | %s | %s | %s\n", $r['id'], $r['email'], substr($r['password_hash'],0,60), $r['created_at']);
    } else {
        echo "NOT_FOUND\n";
    }
} else {
    echo "NO_PDO\n";
}
