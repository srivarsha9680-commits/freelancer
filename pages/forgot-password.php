<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireGuest();
require_once __DIR__ . '/../includes/emails.php';

$success = false;
$error = '';
require_once __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf()) throw new ValidationException("Invalid request.");
        checkRateLimit('reset:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), RATE_LIMIT_RESET_MAX, RATE_LIMIT_RESET_WINDOW);

        $email = validateEmail($_POST['email'] ?? '', 'Email');
        createPasswordReset($email);

        $success = true;
        auditLog('auth.password_reset.requested', null, null, ['email' => $email]);
    } catch (RateLimitException | ValidationException $e) {
        $error = $e->getMessage();
    }
}
?>
<div class="auth-layout"><div class="auth-card">
    <div class="brand">![🛡️](https://fonts.gstatic.com/s/e/notoemoji/17.0/1f6e1_fe0f/72.png) <?= escape(APP_NAME) ?></div>

    <?php if ($success): ?>
    <div style="text-align: center; padding: 20px 0;">
        <div style="font-size: 3rem; margin-bottom: 16px;">![📧](https://fonts.gstatic.com/s/e/notoemoji/17.0/1f4e7/72.png)</div>
        <h3 style="margin-bottom: 8px;">Check your email</h3>
        <p class="text-sm text-muted">If an account exists with that email, we've sent a password reset link. It expires in 1 hour.</p>
        <a href="login.php" class="btn btn-primary" style="margin-top: 24px;">Back to Sign In</a>
    </div>
    <?php else: ?>
    <?php if ($error): ?><div class="badge badge-red mb-4" style="display:block; padding: 8px 16px;"><?= escape($error) ?></div><?php endif; ?>

    <h3 style="margin-bottom: 16px;">Reset Password</h3>
    <p class="text-sm text-muted mb-4">Enter the email address associated with your account.</p>

    <form method="POST"><?= csrfField() ?>
        <div class="form-group"><input type="email" name="email" class="form-control" placeholder="Email" required autofocus></div>
        <button type="submit" class="btn btn-primary w-full">Send Reset Link</button>
    </form>

    <p style="margin-top: 24px; font-size: 0.875rem;">
        <a href="login.php" style="color: var(--gray-600);">← Back to Sign In</a>
    </p>
    <?php endif; ?>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
