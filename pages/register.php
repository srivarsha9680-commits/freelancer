<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Ensure guests only
requireGuest();

$usingFallback = defined('DB_AVAILABLE') && DB_AVAILABLE && DB_ERROR_MESSAGE !== '';
$allowRegistration = defined('DB_AVAILABLE') && DB_AVAILABLE;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        checkRateLimit('register:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), RATE_LIMIT_LOGIN_MAX, RATE_LIMIT_LOGIN_WINDOW);
        if (!verifyCsrf()) throw new ValidationException('Invalid request.');

        $email = validateEmail($_POST['email'] ?? '', 'Email');
        $fullName = validateString($_POST['full_name'] ?? '', 3, 255, 'Full name');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (trim($password) === '') throw new ValidationException('Password is required.');
        if ($password !== $confirm) throw new ValidationException('Passwords do not match.');
        if (strlen($password) < 8) throw new ValidationException('Password must be at least 8 characters.');

        if (!defined('DB_AVAILABLE') || !DB_AVAILABLE || !isset($pdo) || $pdo === null) {
            throw new ValidationException('Registration is currently unavailable because the database is not configured. Please try again later.');
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE lower(email) = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) throw new ValidationException('An account with that email already exists.');

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO users (email, full_name, password_hash) VALUES (?, ?, ?)')->execute([$email, $fullName, $hash]);
        $userId = (int)$pdo->lastInsertId();

        loginUser($userId, $fullName);
        $tenantSlug = generateUniqueSlug($fullName, $pdo);
        $pdo->prepare('INSERT INTO tenants (name, slug, is_active) VALUES (?, ?, 1)')->execute([$fullName . "'s Workspace", $tenantSlug]);
        $tenantId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO tenant_members (tenant_id, user_id, role) VALUES (?, ?, ?)')->execute([$tenantId, $userId, 'owner']);

        redirect('/pages/dashboard.php');
    } catch (RateLimitException | ValidationException | PDOException $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="auth-layout"><div class="auth-card">
    <div class="auth-brand">
        <div class="logo">S</div>
        <div>
            <h1>Create your account</h1>
            <p>Set up your workspace and start managing your projects in one place.</p>
        </div>
    </div>
    <?php if ($error): ?><div class="alert alert-error"><?= escape($error) ?></div><?php endif; ?>
    <?php if ($usingFallback): ?>
        <div class="alert alert-warning">Local SQLite fallback is active. Your account will be created in <code>data/demo.sqlite</code>.</div>
    <?php elseif (!$allowRegistration): ?>
        <div class="alert alert-warning">Registration is unavailable until the application is connected to a database. Enable <code>pdo_sqlite</code> or configure a MySQL database in <code>config/database.php</code>.</div>
    <?php endif; ?>
    <form method="POST" <?= $allowRegistration ? '' : 'aria-disabled="true"' ?>><?= csrfField() ?>
        <div class="form-group">
            <label class="input-label" for="full_name">Full name</label>
            <input id="full_name" type="text" name="full_name" class="form-control" placeholder="Your full name" required value="<?= escape($_POST['full_name'] ?? '') ?>" <?= $allowRegistration ? '' : 'disabled' ?>>
        </div>
        <div class="form-group">
            <label class="input-label" for="email">Email</label>
            <input id="email" type="email" name="email" class="form-control" placeholder="you@example.com" required value="<?= escape($_POST['email'] ?? '') ?>" <?= $allowRegistration ? '' : 'disabled' ?>>
        </div>
        <div class="form-group">
            <label class="input-label" for="password">Password</label>
            <input id="password" type="password" name="password" class="form-control" placeholder="Create a password" required <?= $allowRegistration ? '' : 'disabled' ?>>
        </div>
        <div class="form-group">
            <label class="input-label" for="confirm_password">Confirm password</label>
            <input id="confirm_password" type="password" name="confirm_password" class="form-control" placeholder="Repeat your password" required <?= $allowRegistration ? '' : 'disabled' ?>>
        </div>
        <button type="submit" class="btn btn-primary" <?= $allowRegistration ? '' : 'disabled' ?>>Create Account</button>
    </form>
    <div class="divider">or</div>
    <a href="login.php" class="btn btn-secondary">Sign in instead</a>
    <p class="small-note">Already have an account? <a href="login.php">Sign in here</a>.</p>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
