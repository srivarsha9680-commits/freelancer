<?php
$html = @file_get_contents('http://localhost:8000/pages/login.php');
if ($html === false) {
    echo "FAIL_HTTP\n";
    exit(1);
}
echo strpos($html, 'assets/styles.css') !== false ? "FOUND_LINK\n" : "NO_LINK\n";
$css = @file_get_contents('http://localhost:8000/assets/styles.css');
echo $css === false ? "CSS_FAIL\n" : "CSS_LEN=" . strlen($css) . "\n";
