<?php
// Redirect root requests to the login page.
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$target = ($base === '' ? '' : $base) . '/pages/login.php';
header('Location: ' . $target);
exit;
