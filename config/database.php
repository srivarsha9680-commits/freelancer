<?php
// FIX: Enforce HTTPS at PHP level, not just .htaccess
// Handle reverse proxies (Cloudflare, load balancers)
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
$requestedHost = strtolower($_SERVER['HTTP_HOST'] ?? 'localhost');
$isLocalhost = in_array($requestedHost, ['localhost', '127.0.0.1', '[::1]'], true);
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
$currentScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

// Allow overriding DB credentials via environment variables for easier deployment.
$envDbHost = getenv('DB_HOST') ?: 'mysql.us.stackcp.com';
$envDbUser = getenv('DB_USER') ?: 'freelancer_user';
$envDbPass = getenv('DB_PASS') ?: 'Karachi2';
$envDbName = getenv('DB_NAME') ?: 'freelancer_db-313931450d';
$envDbPort = getenv('DB_PORT') ?: '44140';

define('DB_HOST', $envDbHost);
define('DB_USER', $envDbUser);
define('DB_PASS', $envDbPass);
define('DB_NAME', $envDbName);
define('DB_PORT', $envDbPort);

$customAppUrl = getenv('APP_URL') ?: 'https://mintbrand.buzz/freelancer';
define('APP_URL', rtrim($customAppUrl, '/'));
define('APP_BASE', rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '', '/'));
define('APP_NAME', 'Scope Creep Defender');
define('APP_HOST', parse_url(APP_URL, PHP_URL_HOST));
define('APP_DOMAIN', APP_HOST ?: $requestedHost);
define('APP_SCHEME', parse_url(APP_URL, PHP_URL_SCHEME) ?: $currentScheme);

$dbErrorMessage = '';
$dbAvailable = false;

if (php_sapi_name() !== 'cli' && !$isLocalhost && APP_SCHEME === 'https' && $currentScheme !== 'https') {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}

if (!class_exists('PDO')) {
    error_log('PDO extension is not installed.');
    $dbErrorMessage = 'Server error: PDO support is not available. Please enable the PHP PDO extension (and PDO MySQL if using MySQL).';
    $pdo = null;
    $dbAvailable = false;
} else {
    define('SMTP_ENABLED', false); // Set true if using PHPMailer (see includes/emails.php)

    try {
        $dsn = "mysql:host=" . DB_HOST . (defined('DB_PORT') ? ";port=" . DB_PORT : "") . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO(
            $dsn,
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $dbAvailable = true;
    } catch (PDOException $e) {
        error_log("DB connection failed: " . $e->getMessage());
        $pdo = null;
        $dbErrorMessage = 'MySQL connection failed: ' . $e->getMessage();

        // Only attempt SQLite fallback if the driver is present.
        if (extension_loaded('pdo_sqlite')) {
            $dataDir = __DIR__ . '/../data';
            if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);
            $sqlitePath = $dataDir . '/demo.sqlite';
            try {
                $pdo = new PDO('sqlite:' . $sqlitePath);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                // Create minimal schema used by the app.
                $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT UNIQUE NOT NULL,
                    full_name TEXT,
                    password_hash TEXT NOT NULL,
                    avatar_url TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS tenants (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT,
                    slug TEXT UNIQUE,
                    is_active INTEGER DEFAULT 1,
                    deleted_at DATETIME DEFAULT NULL,
                    company_name TEXT,
                    branding_color TEXT DEFAULT '#10B981',
                    currency TEXT DEFAULT 'USD',
                    email_from_name TEXT,
                    email_reply_to TEXT,
                    max_members INTEGER DEFAULT 25,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS tenant_members (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER,
                    user_id INTEGER,
                    role TEXT DEFAULT 'member',
                    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS projects (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER,
                    name TEXT,
                    client_name TEXT,
                    client_email TEXT,
                    price REAL DEFAULT 0,
                    status TEXT DEFAULT 'draft',
                    pricing_type TEXT DEFAULT 'fixed',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS scope_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER,
                    project_id INTEGER,
                    description TEXT,
                    sort_order INTEGER DEFAULT 0
                );
                CREATE TABLE IF NOT EXISTS change_orders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER,
                    project_id INTEGER,
                    client_token TEXT UNIQUE,
                    status TEXT DEFAULT 'draft',
                    request_description TEXT,
                    estimated_value REAL DEFAULT 0,
                    estimated_hours REAL DEFAULT 0,
                    timeline_impact TEXT,
                    email_content TEXT,
                    email_tone TEXT DEFAULT 'friendly',
                    created_by INTEGER,
                    sent_at DATETIME,
                    responded_at DATETIME,
                    decline_reason TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS activity_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER,
                    user_id INTEGER,
                    action TEXT,
                    description TEXT,
                    project_id INTEGER,
                    change_order_id INTEGER,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS tenant_invitations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER,
                    email TEXT,
                    role TEXT,
                    invited_by INTEGER,
                    token TEXT UNIQUE,
                    expires_at DATETIME,
                    accepted_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS password_resets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    token TEXT UNIQUE,
                    expires_at DATETIME,
                    used_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    bucket_key TEXT,
                    hit_count INTEGER,
                    window_start DATETIME,
                    window_end DATETIME
                );
                CREATE TABLE IF NOT EXISTS security_audit_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER,
                    user_id INTEGER,
                    event_type TEXT,
                    ip_address TEXT,
                    user_agent TEXT,
                    metadata TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS email_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER,
                    to_address TEXT,
                    subject TEXT,
                    status TEXT,
                    error_text TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                SQL
                );

                $dbAvailable = true;
            } catch (Throwable $t) {
                error_log('SQLite fallback failed: ' . $t->getMessage());
                $pdo = null;
                $dbErrorMessage = 'SQLite fallback failed: ' . $t->getMessage();
            }
        } else {
            $dbErrorMessage .= ' SQLite fallback unavailable because the PDO SQLite extension is not enabled.';
        }
        // If SQLite isn't available, DB_AVAILABLE remains false and pages should render a friendly message.
    }
}
define('SMTP_HOST', 'smtp.serverbyt.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@serverbyt.com');
define('SMTP_PASS', 'your_smtp_password');
define('SMTP_FROM_EMAIL', 'noreply@serverbyt.com');
define('SMTP_FROM_NAME', 'Scope Creep Defender');

define('RATE_LIMIT_LOGIN_MAX', 10);
define('RATE_LIMIT_LOGIN_WINDOW', 900);
define('RATE_LIMIT_CLIENT_MAX', 20);
define('RATE_LIMIT_CLIENT_WINDOW', 3600);
define('RATE_LIMIT_INVITE_MAX', 5);
define('RATE_LIMIT_INVITE_WINDOW', 3600);
define('RATE_LIMIT_RESET_MAX', 3);
define('RATE_LIMIT_RESET_WINDOW', 900);

define('SUBDOMAIN_MODE', false);

if (!function_exists('createMySqlSchema')) {
    function createMySqlSchema(PDO $pdo): void {
        $statements = [
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) UNIQUE NOT NULL,
                full_name VARCHAR(255),
                password_hash VARCHAR(255) NOT NULL,
                avatar_url VARCHAR(1024),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS tenants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255),
                slug VARCHAR(255) UNIQUE,
                is_active TINYINT(1) DEFAULT 1,
                deleted_at DATETIME DEFAULT NULL,
                company_name VARCHAR(255),
                branding_color VARCHAR(32) DEFAULT '#10B981',
                currency VARCHAR(16) DEFAULT 'USD',
                email_from_name VARCHAR(255),
                email_reply_to VARCHAR(255),
                max_members INT DEFAULT 25,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS tenant_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT,
                user_id INT,
                role VARCHAR(32) DEFAULT 'member',
                joined_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT,
                name VARCHAR(255),
                client_name VARCHAR(255),
                client_email VARCHAR(255),
                price DOUBLE DEFAULT 0,
                status VARCHAR(64) DEFAULT 'draft',
                pricing_type VARCHAR(64) DEFAULT 'fixed',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS scope_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT,
                project_id INT,
                description TEXT,
                sort_order INT DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS change_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT,
                project_id INT,
                client_token VARCHAR(255) UNIQUE,
                status VARCHAR(64) DEFAULT 'draft',
                request_description TEXT,
                estimated_value DOUBLE DEFAULT 0,
                estimated_hours DOUBLE DEFAULT 0,
                timeline_impact TEXT,
                email_content TEXT,
                created_by INT,
                sent_at DATETIME,
                responded_at DATETIME,
                decline_reason TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS activity_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT,
                user_id INT,
                action VARCHAR(255),
                description TEXT,
                project_id INT,
                change_order_id INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS tenant_invitations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT,
                email VARCHAR(255),
                role VARCHAR(32),
                invited_by INT,
                token VARCHAR(255) UNIQUE,
                expires_at DATETIME,
                accepted_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                token VARCHAR(255) UNIQUE,
                expires_at DATETIME,
                used_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bucket_key VARCHAR(255),
                hit_count INT,
                window_start DATETIME,
                window_end DATETIME
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS security_audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT,
                user_id INT,
                event_type VARCHAR(255),
                ip_address VARCHAR(255),
                user_agent VARCHAR(1024),
                metadata TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS email_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT,
                to_address VARCHAR(255),
                subject VARCHAR(255),
                status VARCHAR(64),
                error_text TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }
}

// Duplicate MySQL connection block removed to avoid overriding earlier connection/fallback logic.
// The initial connection and fallback logic above sets $pdo, $dbAvailable and $dbErrorMessage.

define('DB_AVAILABLE', $dbAvailable);
define('DB_ERROR_MESSAGE', trim($dbErrorMessage));
