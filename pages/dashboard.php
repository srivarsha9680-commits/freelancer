<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();
$tenant = requireTenant();
$projects = getTenantProjects();
$flash = getFlash();
$usingFallback = defined('DB_AVAILABLE') && DB_AVAILABLE && strpos(DB_ERROR_MESSAGE, 'MySQL connection failed:') === 0;
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container" style="padding-top:32px;">
    <?php if ($usingFallback): ?>
    <div class="card" style="background:#FEF3C7; border:1px solid #FCD34D; margin-bottom:16px;">
        <strong>Local database fallback is active.</strong>
        <div>The app is running on SQLite because MySQL is unavailable. Data is stored locally in <code>data/demo.sqlite</code>.</div>
    </div>
    <?php endif; ?>
    <?php if ($flash): ?>
    <div class="card" style="background:#ECFDF5; border:1px solid #A7F3D0; margin-bottom:16px;"><?= escape($flash['message']) ?></div>
    <?php endif; ?>

    <div class="flex justify-between items-center mb-4">
        <div>
            <h2>Welcome, <?= escape($_SESSION['user_name'] ?? 'User') ?></h2>
            <p class="text-muted">Tenant: <?= escape($tenant['name'] ?? 'Default') ?></p>
        </div>
        <a href="<?= appPath('/pages/logout.php') ?>" class="btn btn-secondary">Sign Out</a>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <h3>Quick Actions</h3>
        <div class="flex gap-2" style="flex-wrap:wrap; margin-top:16px;">
            <a href="projects.php" class="btn btn-primary">View Projects</a>
            <a href="project-create.php" class="btn btn-secondary">New Project</a>
        </div>
    </div>

    <div class="card">
        <h3>Your Projects</h3>
        <?php if (!$projects): ?>
            <p class="text-muted">No projects yet. Create one to get started.</p>
        <?php else: ?>
            <ul style="margin:0; padding:0; list-style:none;">
                <?php foreach ($projects as $project): ?>
                    <li style="border-bottom:1px solid #e5e7eb; padding:12px 0;"><?= escape($project['name']) ?> &mdash; <?= formatMoney((float)$project['price'], $tenant['currency'] ?? 'USD') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
