<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireGuest();

$token = $_GET['token'] ?? '';
$resetUser = null;
$error = '';
$success = false;

if ($token) {
    try {
        $resetUser = consumePasswordReset($token);
    } catch (ValidationException $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $resetUser && !$error) {
    try {
        if (!verifyCsrf()) throw new ValidationException("Invalid request.");

        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if ($password !== $confirm) throw new ValidationException("Passwords do not match.");
        if (strlen($password) < 8) throw new ValidationException("Password must be at least 8 characters.");

        completePasswordReset($token, $password);
        $success = true;
    } catch (ValidationException $e) {
        $error = $e->getMessage();
        $stmt = $pdo->prepare("SELECT pr.*, u.email, u.full_name FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE pr.token = ? AND pr.used_at IS NULL AND pr.expires_at > CURRENT_TIMESTAMP");
        $stmt->execute([$token]);
        $resetUser = $stmt->fetch();
    }
}
?>
<div class="auth-layout"><div class="auth-card">
    <div class="brand"><span class="brand-icon">🛡️</span><?= escape(APP_NAME) ?></div>

    <?php if ($success): ?>
    <div style="text-align: center; padding: 20px 0;">
        <div style="font-size: 3rem; margin-bottom: 16px;">✅</div>
        <h3 style="margin-bottom: 8px;">Password Updated</h3>
        <p class="text-sm text-muted">Your password has been changed. You can now sign in.</p>
        <a href="login.php" class="btn btn-primary" style="margin-top: 24px;">Sign In</a>
    </div>
    <?php elseif ($error && !$resetUser): ?>
    <div style="text-align: center; padding: 20px 0;">
        <div style="font-size: 3rem; margin-bottom: 16px;">❌</div>
        <h3 style="margin-bottom: 8px;">Link Invalid</h3>
        <p class="text-sm text-muted"><?= escape($error) ?></p>
        <a href="forgot-password.php" class="btn btn-secondary" style="margin-top: 24px;">Request New Link</a>
    </div>
    <?php else: ?>
    <?php if ($error): ?><div class="badge badge-red mb-4" style="display:block; padding: 8px 16px;"><?= escape($error) ?></div><?php endif; ?>

    <h3 style="margin-bottom: 16px;">Set New Password</h3>
    <p class="text-sm text-muted mb-4">For <?= escape($resetUser['email'] ?? '') ?></p>

    <form method="POST"><?= csrfField() ?>
        <div class="form-group">
            <input type="password" name="password" class="form-control" placeholder="New password (8+ characters)" required minlength="8" autofocus>
        </div>
        <div class="form-group">
            <input type="password" name="password_confirm" class="form-control" placeholder="Confirm new password" required minlength="8">
        </div>
        <button type="submit" class="btn btn-primary w-full">Reset Password</button>
    </form>

    <p style="margin-top: 24px; font-size: 0.875rem;">
        <a href="login.php" style="color: var(--gray-600);">← Back to Sign In</a>
    </p>
    <?php endif; ?>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
