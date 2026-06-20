<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE && !ob_get_level()) {
    ob_start();
}

if (!function_exists('escape')) {
function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
}

if (!function_exists('csrfField')) {
function csrfField(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . escape($_SESSION['csrf_token']) . '">';
}
}

if (!function_exists('verifyCsrf')) {
function verifyCsrf(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}
}

if (!function_exists('redirect')) {
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= appPath('/assets/styles.css') ?>">
</head>
<body>
