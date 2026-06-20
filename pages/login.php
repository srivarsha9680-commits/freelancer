<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Ensure guests only
requireGuest();

$usingFallback = defined('DB_AVAILABLE') && DB_AVAILABLE && DB_ERROR_MESSAGE !== '';
$dbErrorMessage = defined('DB_ERROR_MESSAGE') ? DB_ERROR_MESSAGE : '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        checkRateLimit('login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), RATE_LIMIT_LOGIN_MAX, RATE_LIMIT_LOGIN_WINDOW);
        if (defined('DB_AVAILABLE') && DB_AVAILABLE) {
            if (!verifyCsrf()) throw new ValidationException("Invalid request.");
        }

        if (isset($pdo) && $pdo instanceof PDO) {
            $email = validateEmail($_POST['email'] ?? '', 'Email');
            $stmt = $pdo->prepare("SELECT * FROM users WHERE lower(email) = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
                loginUser($user['id'], $user['full_name']);
                auditLog('auth.login.success', $user['id']);
                $tenants = getUserTenants($user['id']);
                if (count($tenants) === 1) { switchTenant($tenants[0]['id'], $tenants[0]['slug']); redirect('/pages/dashboard.php'); }
                else redirect('/pages/tenant-select.php');
            } else {
                auditLog('auth.login.failed', null, null, ['email' => $email]);
                throw new ValidationException("Invalid email or password.");
            }
        } else {
            throw new ValidationException('Unable to authenticate because the database connection is unavailable.');
        }
    } catch (RateLimitException | ValidationException | PDOException $e) {
        error_log('Login error: ' . $e->getMessage());
        if ($e instanceof PDOException) {
            $error = 'Unable to complete login due to a server error. Please try again later.';
        } else {
            $error = $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="auth-layout"><div class="auth-card">
    <div class="auth-brand">
        <div class="logo">S</div>
        <div>
            <h1><?= escape(APP_NAME) ?></h1>
            <p>Sign in to manage projects, tenants, and scope changes.</p>
        </div>
    </div>
    <?php if ($usingFallback): ?>
        <div class="alert alert-warning">
            Local SQLite fallback is active. The app can store data locally in <code>data/demo.sqlite</code>.
            <?php if ($dbErrorMessage): ?><div><strong>Error:</strong> <?= escape($dbErrorMessage) ?></div><?php endif; ?>
            <div style="margin-top:6px;">Sign in with an existing account or register a new one.</div>
        </div>
    <?php elseif ($dbErrorMessage): ?>
        <div class="alert alert-warning">
            The application cannot connect to the configured database.
            <?php if ($dbErrorMessage): ?><div><strong>Error:</strong> <?= escape($dbErrorMessage) ?></div><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= escape($error) ?></div><?php endif; ?>
    <form method="POST"><?= csrfField() ?>
        <div class="form-group">
            <label class="input-label" for="email">Email</label>
            <input id="email" type="email" name="email" class="form-control" placeholder="you@example.com" required autofocus>
        </div>
        <div class="form-group">
            <label class="input-label" for="password">Password</label>
            <input id="password" type="password" name="password" class="form-control" placeholder="Enter your password" required>
        </div>
        <button type="submit" class="btn btn-primary">Sign In</button>
    </form>
    <div class="divider">or</div>
    <a href="register.php" class="btn btn-secondary">Create an Account</a>
    <p class="small-note">
        <a href="forgot-password.php">Forgot password?</a>
    </p>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
