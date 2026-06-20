<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container" style="padding-top:32px;">
    <div class="card">
        <h2>Demo Dashboard</h2>
        <p>Welcome, <?= escape($_SESSION['user_name'] ?? 'Demo User') ?> — this is a lightweight demo view that doesn't require a database.</p>
        <p style="margin-top:12px;"><a href="<?= appPath('/pages/logout.php') ?>" class="btn btn-secondary">Sign Out</a></p>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
