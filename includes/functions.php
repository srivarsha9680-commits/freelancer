<?php
// FIX: Harden session before starting it
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $cookieParams = [
        'lifetime' => 7200,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    $currentHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $localhostHosts = ['localhost', '127.0.0.1', '[::1]'];
    if ($currentHost !== '' && !in_array($currentHost, $localhostHosts, true)) {
        $appDomain = strtolower(APP_DOMAIN);
        if ($currentHost === $appDomain || substr($currentHost, -strlen('.' . $appDomain)) === '.' . $appDomain) {
            $cookieParams['domain'] = APP_DOMAIN;
        }
    }
    session_set_cookie_params($cookieParams);
    session_start();
}

class ValidationException extends RuntimeException {}
class AuthorizationException extends RuntimeException {}
class RateLimitException extends RuntimeException {}

function loginUser(int $userId, string $name): void {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $name;
    $_SESSION['created_at'] = time();
}

function switchTenant(int $tenantId, string $tenantSlug): void {
    $_SESSION['tenant_id'] = $tenantId;
    $_SESSION['tenant_slug'] = $tenantSlug;
    session_regenerate_id(true);
}

function destroySession(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_regenerate_id(true);
    session_destroy();
}

if (!function_exists('appPath')) {
    function appPath(string $path): string {
        $path = '/' . ltrim($path, '/');
        return APP_BASE !== '' ? APP_BASE . $path : $path;
    }
}

function checkRateLimit(string $bucketKey, int $maxHits, int $windowSeconds): void {
    global $pdo;
    // If DB isn't available, use a session-backed simple counter.
    if (!isset($pdo) || $pdo === null) {
        if (!isset($_SESSION['rate_limits'])) $_SESSION['rate_limits'] = [];
        $nowTs = time();
        $bucket = $_SESSION['rate_limits'][$bucketKey] ?? ['count' => 0, 'start' => $nowTs];
        if ($nowTs - $bucket['start'] > $windowSeconds) {
            $bucket = ['count' => 0, 'start' => $nowTs];
        }
        $bucket['count']++;
        $_SESSION['rate_limits'][$bucketKey] = $bucket;
        if ($bucket['count'] > $maxHits) throw new RateLimitException("Too many attempts. Please wait.");
        return;
    }

    $now = date('Y-m-d H:i:s');
    $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

    try {
        $pdo->prepare("DELETE FROM rate_limits WHERE window_end < ?")->execute([$now]);
        $stmt = $pdo->prepare("SELECT hit_count, window_start FROM rate_limits WHERE bucket_key = ? AND window_start >= ? ORDER BY window_start DESC LIMIT 1");
        $stmt->execute([$bucketKey, $windowStart]);
        $row = $stmt->fetch();

        if ($row && (int)$row['hit_count'] >= $maxHits) throw new RateLimitException("Too many attempts. Please wait.");
        if ($row) {
            $pdo->prepare("UPDATE rate_limits SET hit_count = hit_count + 1 WHERE bucket_key = ? AND window_start = ?")->execute([$bucketKey, $row['window_start']]);
        } else {
            $pdo->prepare("INSERT INTO rate_limits (bucket_key, hit_count, window_start, window_end) VALUES (?, 1, ?, ?)")->execute([$bucketKey, $now, date('Y-m-d H:i:s', time() + $windowSeconds)]);
        }
    } catch (PDOException $e) {
        // If the rate_limits table is missing or DB rate limiting fails, fallback to session storage.
        if (!isset($_SESSION['rate_limits'])) $_SESSION['rate_limits'] = [];
        $nowTs = time();
        $bucket = $_SESSION['rate_limits'][$bucketKey] ?? ['count' => 0, 'start' => $nowTs];
        if ($nowTs - $bucket['start'] > $windowSeconds) {
            $bucket = ['count' => 0, 'start' => $nowTs];
        }
        $bucket['count']++;
        $_SESSION['rate_limits'][$bucketKey] = $bucket;
        if ($bucket['count'] > $maxHits) throw new RateLimitException("Too many attempts. Please wait.");
    }
}

function auditLog(string $eventType, ?int $userId = null, ?int $tenantId = null, ?array $metadata = null): void {
    global $pdo;
    if (!isset($pdo) || $pdo === null) {
        error_log(sprintf('Audit log skipped: %s %s', $eventType, json_encode($metadata, JSON_THROW_ON_ERROR)));
        return;
    }
    try {
        $pdo->prepare("INSERT INTO security_audit_log (tenant_id, user_id, event_type, ip_address, user_agent, metadata) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$tenantId, $userId, $eventType, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null]);
    } catch (PDOException $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

function requireAuth(): void {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'];
        redirect('/pages/login.php');
    }
}

function requireGuest(): void {
    if (isset($_SESSION['user_id'])) redirect('/pages/dashboard.php');
}

function getCurrentUser(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    global $pdo;
    if (!isset($pdo) || $pdo === null) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id, email, full_name, avatar_url, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function resolveTenant(): ?array {
    global $pdo;
    if (!isset($pdo) || $pdo === null) {
        return null;
    }
    $slug = null;
    if (SUBDOMAIN_MODE && isset($_SERVER['HTTP_HOST'])) {
        $host = strtolower($_SERVER['HTTP_HOST']);
        $base = strtolower(preg_replace('/^www\./', '', APP_DOMAIN));
        $host = preg_replace('/^www\./', '', $host);
        if ($host !== $base && substr($host, -strlen('.' . $base)) === '.' . $base) {
            $slug = substr($host, 0, -(strlen('.' . $base)));
        }
    }
    if (!$slug && isset($_GET['tenant'])) {
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['tenant']));
    }
    if (!$slug && isset($_SESSION['tenant_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? AND is_active = 1 AND deleted_at IS NULL");
        $stmt->execute([$_SESSION['tenant_id']]);
        $t = $stmt->fetch();
        if ($t) return $t;
        unset($_SESSION['tenant_id'], $_SESSION['tenant_slug']);
        return null;
    }
    if ($slug) {
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE slug = ? AND is_active = 1 AND deleted_at IS NULL");
        $stmt->execute([$slug]);
        $t = $stmt->fetch();
        if ($t) { $_SESSION['tenant_id'] = $t['id']; $_SESSION['tenant_slug'] = $t['slug']; return $t; }
    }
    return null;
}

function requireTenant(): array {
    $tenant = resolveTenant();
    if (!$tenant) redirect('/pages/tenant-select.php');
    if (!getTenantMember($tenant['id'], $_SESSION['user_id'])) {
        unset($_SESSION['tenant_id'], $_SESSION['tenant_slug']);
        redirect('/pages/tenant-select.php');
    }
    return $tenant;
}

function tenantId(): int { return (int)($_SESSION['tenant_id'] ?? 0); }

function getTenantMember(int $tenantId, int $userId): ?array {
    global $pdo;
    if (!isset($pdo) || $pdo === null) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT tm.*, u.email, u.full_name, u.avatar_url FROM tenant_members tm JOIN users u ON u.id = tm.user_id WHERE tm.tenant_id = ? AND tm.user_id = ?");
    $stmt->execute([$tenantId, $userId]);
    return $stmt->fetch() ?: null;
}

function requireTenantRole(string $requiredRole): void {
    $member = getTenantMember(tenantId(), $_SESSION['user_id']);
    if (!$member) redirect('/pages/tenant-select.php');
    $h = ['viewer' => 0, 'member' => 1, 'admin' => 2, 'owner' => 3];
    if (($h[$member['role']] ?? 0) < ($h[$requiredRole] ?? 0)) {
        http_response_code(403);
        die("Insufficient permissions.");
    }
}

function isTenantOwner(int $tenantId, int $userId): bool {
    global $pdo;
    if (!isset($pdo) || $pdo === null) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT id FROM tenant_members WHERE tenant_id = ? AND user_id = ? AND role = 'owner'");
    $stmt->execute([$tenantId, $userId]);
    return (bool)$stmt->fetch();
}

function getUserTenants(int $userId): array {
    global $pdo;
    if (!isset($pdo) || $pdo === null) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT t.*, tm.role, tm.joined_at FROM tenants t JOIN tenant_members tm ON tm.tenant_id = t.id WHERE tm.user_id = ? AND t.is_active = 1 AND t.deleted_at IS NULL ORDER BY t.name ASC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getTenantMembers(int $tenantId): array {
    global $pdo;
    if (!isset($pdo) || $pdo === null) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT tm.*, u.email, u.full_name, u.avatar_url FROM tenant_members tm JOIN users u ON u.id = tm.user_id WHERE tm.tenant_id = ? ORDER BY CASE tm.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 WHEN 'member' THEN 2 WHEN 'viewer' THEN 3 END, u.full_name ASC");
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll();
}

function getTenantProjectCountLocked(int $tenantId, bool $forUpdate = false): int {
    global $pdo;
    if (!isset($pdo) || $pdo === null) {
        return 0;
    }
    $sql = "SELECT COUNT(*) FROM projects WHERE tenant_id = ? AND status != 'archived'" . ($forUpdate ? " FOR UPDATE" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tenantId]);
    return (int)$stmt->fetchColumn();
}

function getTenantMemberCount(int $tenantId): int {
    global $pdo;
    if (!isset($pdo) || $pdo === null) {
        return 0;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenant_members WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);
    return (int)$stmt->fetchColumn();
}

function validateString(string $v, int $min = 1, int $max = 255, string $f = 'Field'): string {
    $v = trim($v);
    $length = function_exists('mb_strlen') ? mb_strlen($v) : strlen($v);
    if ($length < $min) throw new ValidationException("{$f} must be at least {$min} characters.");
    if ($length > $max) throw new ValidationException("{$f} must be no more than {$max} characters.");
    return $v;
}

function validateEmail(string $v, string $f = 'Email'): string {
    $v = trim($v);
    if (!filter_var($v, FILTER_VALIDATE_EMAIL)) throw new ValidationException("{$f} must be valid.");
    return strtolower($v);
}

function validateFloat(string $v, float $min = 0.0, ?float $max = null, string $f = 'Value'): float {
    $val = floatval($v);
    if ($val < $min) throw new ValidationException("{$f} must be at least {$min}.");
    if ($max !== null && $val > $max) throw new ValidationException("{$f} must be no more than {$max}.");
    return $val;
}

function validateEnum(string $v, array $a, string $f = 'Selection'): string {
    if (!in_array($v, $a, true)) throw new ValidationException("Invalid {$f}.");
    return $v;
}

function validateSlug(string $v, string $f = 'Slug'): string {
    $v = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($v)));
    if (strlen($v) < 3) throw new ValidationException("{$f} must be at least 3 characters.");
    return $v;
}

function generateUniqueSlug(string $name, PDO $pdo): string {
    $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim(str_replace(' ', '-', $name))));
    if ($slug === '') {
        $slug = 'tenant';
    }
    $slug = substr($slug, 0, 64);
    $baseSlug = $slug;
    $counter = 1;
    $stmt = $pdo->prepare('SELECT 1 FROM tenants WHERE slug = ? LIMIT 1');
    while (true) {
        $stmt->execute([$slug]);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = substr($baseSlug, 0, 56) . '-' . $counter;
        $counter++;
    }
}

function validateHexColor(string $v): string {
    $v = preg_replace('/[^#0-9a-fA-F]/', '', $v);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : '#10B981';
}

function getTenantProject(int $id): ?array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$id, tenantId()]);
    return $stmt->fetch() ?: null;
}

function getTenantProjects(): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT p.*, COUNT(DISTINCT si.id) as scope_count, COUNT(DISTINCT co.id) as change_count, COALESCE(SUM(CASE WHEN co.status = 'approved' THEN co.estimated_value END), 0) as saved_amount FROM projects p LEFT JOIN scope_items si ON si.tenant_id = p.tenant_id AND si.project_id = p.id LEFT JOIN change_orders co ON co.tenant_id = p.tenant_id AND co.project_id = p.id WHERE p.tenant_id = ? AND p.status != 'archived' GROUP BY p.id ORDER BY p.created_at DESC");
    $stmt->execute([tenantId()]);
    return $stmt->fetchAll();
}

function getTenantScopeItems(int $pid): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM scope_items WHERE tenant_id = ? AND project_id = ? ORDER BY sort_order");
    $stmt->execute([tenantId(), $pid]);
    return $stmt->fetchAll();
}

function getTenantChangeOrders(int $pid): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM change_orders WHERE tenant_id = ? AND project_id = ? ORDER BY created_at DESC");
    $stmt->execute([tenantId(), $pid]);
    return $stmt->fetchAll();
}

function getTenantKPIs(int $tid): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN co.status = 'approved' THEN co.estimated_value END), 0) as revenue_saved, COUNT(DISTINCT CASE WHEN co.status = 'pending' THEN co.id END) as pending_changes, COUNT(DISTINCT CASE WHEN p.status = 'active' THEN p.id END) as active_projects, COUNT(DISTINCT co.id) as total_changes, COUNT(DISTINCT CASE WHEN co.status = 'declined' THEN co.id END) as declined_changes FROM change_orders co JOIN projects p ON p.id = co.project_id AND p.tenant_id = co.tenant_id WHERE co.tenant_id = ?");
    $stmt->execute([$tid]);
    return $stmt->fetch();
}

function getRecentActivity(int $tid, int $limit = 10): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT al.*, u.full_name as user_name, p.name as project_name FROM activity_log al JOIN users u ON u.id = al.user_id LEFT JOIN projects p ON p.id = al.project_id WHERE al.tenant_id = ? ORDER BY al.created_at DESC LIMIT ?");
    $stmt->execute([$tid, $limit]);
    return $stmt->fetchAll();
}

function logActivity(int $tid, int $uid, string $a, string $d, ?int $pid = null, ?int $coid = null): void {
    global $pdo;
    $pdo->prepare("INSERT INTO activity_log (tenant_id, user_id, action, description, project_id, change_order_id) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$tid, $uid, $a, $d, $pid, $coid]);
}

function createInvitation(int $tid, int $by, string $email, string $role): string {
    global $pdo;
    $token = bin2hex(random_bytes(32));
    $exp = date('Y-m-d H:i:s', strtotime('+7 days'));

    $stmt = $pdo->prepare("SELECT id FROM tenant_members tm JOIN users u ON u.id = tm.user_id WHERE tm.tenant_id = ? AND u.email = ?");
    $stmt->execute([$tid, $email]);
    if ($stmt->fetch()) throw new ValidationException("User is already a member of this workspace.");

    $stmt = $pdo->prepare("SELECT id FROM tenant_invitations WHERE tenant_id = ? AND email = ? AND accepted_at IS NULL AND expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$tid, $email]);
    if ($stmt->fetch()) throw new ValidationException("An invitation is already pending for this email.");

    $pdo->prepare("INSERT INTO tenant_invitations (tenant_id, email, role, invited_by, token, expires_at) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$tid, $email, $role, $by, $token, $exp]);
    return $token;
}

function acceptInvitation(string $token, int $uid): array {
    global $pdo;
    $stmt = $pdo->prepare("SELECT ti.*, t.name as tenant_name, t.slug as tenant_slug FROM tenant_invitations ti JOIN tenants t ON t.id = ti.tenant_id WHERE ti.token = ? AND ti.accepted_at IS NULL AND ti.expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$token]);
    $inv = $stmt->fetch();
    if (!$inv) throw new RuntimeException("Invalid or expired invitation.");

    $stmt = $pdo->prepare("SELECT id FROM tenant_members WHERE tenant_id = ? AND user_id = ?");
    $stmt->execute([$inv['tenant_id'], $uid]);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO tenant_members (tenant_id, user_id, role, joined_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)")
            ->execute([$inv['tenant_id'], $uid, $inv['role']]);
    }

    $pdo->prepare("UPDATE tenant_invitations SET accepted_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$inv['id']]);
    return $inv;
}

function getPendingInvitations(int $uid): array {
    $u = getCurrentUser();
    if (!$u) return [];
    global $pdo;
    $stmt = $pdo->prepare("SELECT ti.*, t.name as tenant_name, t.slug as tenant_slug, u.full_name as inviter_name FROM tenant_invitations ti JOIN tenants t ON t.id = ti.tenant_id JOIN users u ON u.id = ti.invited_by WHERE ti.email = ? AND ti.accepted_at IS NULL AND ti.expires_at > CURRENT_TIMESTAMP ORDER BY ti.created_at DESC");
    $stmt->execute([$u['email']]);
    return $stmt->fetchAll();
}

function generateClientToken(): string { return bin2hex(random_bytes(32)); }

function getChangeOrderByToken(string $token): ?array {
    global $pdo;
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) return null;
    $stmt = $pdo->prepare("SELECT co.*, p.name as project_name, p.price as project_price, p.client_name, p.client_email, t.name as tenant_name, t.company_name, t.branding_color, u.full_name as freelancer_name FROM change_orders co JOIN projects p ON p.id = co.project_id JOIN tenants t ON t.id = co.tenant_id JOIN users u ON u.id = co.created_by WHERE co.client_token = ? AND co.status = 'pending'");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function formatMoney(float $amount, string $currency = 'USD'): string {
    $s = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'C$', 'AUD' => 'A$'];
    return ($s[$currency] ?? '$') . number_format($amount, 0);
}

function escape(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function redirect(string $url): void {
    if (substr($url, 0, 1) === '/') {
        $url = appPath($url);
    }
    header("Location: $url");
    exit;
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): bool {
    $t = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($t) && hash_equals(csrfToken(), $t);
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . escape(csrfToken()) . '">';
}

function userInitial(): string {
    return escape(strtoupper(mb_substr($_SESSION['user_name'] ?? 'U', 0, 1)));
}

/* ============================================================
   FIX: Password Reset Functions
   ============================================================ */

function createPasswordReset(string $email): void {
    global $pdo;
    if (!isset($pdo) || $pdo === null) {
        throw new ValidationException('Server error: unable to access the database. Please try again later.');
    }
    $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE lower(email) = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) return;

    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $pdo->prepare("UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL")->execute([$user['id']]);
    $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)")->execute([$user['id'], $token, $expires]);

    require_once __DIR__ . '/emails.php';
    sendPasswordResetEmail($user['email'], $user['full_name'], $token);
}

function consumePasswordReset(string $token): array {
    global $pdo;
    if (!isset($pdo) || $pdo === null) {
        throw new ValidationException('Server error: unable to access the database. Please try again later.');
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) throw new ValidationException("Invalid reset link.");

    $stmt = $pdo->prepare("SELECT pr.*, u.email, u.full_name FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token = ? AND pr.used_at IS NULL AND pr.expires_at > CURRENT_TIMESTAMP");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();
    if (!$reset) throw new ValidationException("This reset link is invalid or has expired.");
    return $reset;
}

function completePasswordReset(string $token, string $newPassword): void {
    global $pdo;
    if (!isset($pdo) || $pdo === null) {
        throw new ValidationException('Server error: unable to access the database. Please try again later.');
    }
    $reset = consumePasswordReset($token);
    if (strlen($newPassword) < 8) throw new ValidationException("Password must be at least 8 characters.");
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $reset['user_id']]);
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE token = ?")->execute([$token]);
    auditLog('auth.password_reset', $reset['user_id']);
}

/* ============================================================
   Email Generation
   ============================================================ */

function generateChangeOrderEmail(array $project, array $change, string $tone = 'friendly'): string {
    $cn = str_replace(["\r", "\n", "\x00"], '', $project['client_name'] ?? 'Client');
    $pn = str_replace(["\r", "\n", "\x00"], '', $project['name'] ?? 'Project');
    $sn = str_replace(["\r", "\n", "\x00"], '', $_SESSION['user_name'] ?? 'Manager');
    $tones = [
        'friendly' => ['g' => "Hi {$cn},", 'o' => "Thanks for the great feedback on {$pn}.", 's' => "This falls outside our agreed scope", 'c' => "Let me know if you have questions!"],
        'formal' => ['g' => "Dear {$cn},", 'o' => "Thank you for your continued collaboration on {$pn}.", 's' => "Please note this request is outside the agreed scope", 'c' => "Please don't hesitate to reach out."],
        'assertive' => ['g' => "Hi {$cn},", 'o' => "Following up on your request regarding {$pn}.", 's' => "This request is outside our signed scope", 'c' => "Please confirm your decision at your earliest convenience."]
    ];
    $t = $tones[$tone] ?? $tones['friendly'];
    return "{$t['g']}\n\n{$t['o']} I wanted to quickly follow up on the requested addition.\n\n{$t['s']}. I'd be happy to add this:\n\n• {$change['request_description']}\n• {$change['estimated_hours']} hours estimated\n• {$change['timeline_impact']}\n\nAdditional cost: " . formatMoney((float)$change['estimated_value']) . "\n\n[Approve] [Decline] [Discuss]\n\n{$t['c']}\n\nBest,\n{$sn}";
}

/* ============================================================
   Flash messages (for cross-request notifications)
   ============================================================ */

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}
