<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
// Log out and redirect to login
if (function_exists('destroySession')) destroySession();
redirect('/pages/login.php');
