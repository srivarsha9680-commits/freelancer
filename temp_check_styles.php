<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/pages/login.php';
ob_start();
include 'pages/login.php';
$html = ob_get_clean();
echo strpos($html, 'assets/styles.css') !== false ? 'FOUND' : 'NOTFOUND';
