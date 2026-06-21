<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();
$tenant = requireTenant();
$projects = getTenantProjects();
$currency = $tenant['currency'] ?? 'USD';
$search = trim($_GET['q'] ?? '');
if ($search) {
    $projects = array_filter($projects, function ($p) use ($search) {
        return stripos($p['name'], $search) !== false || stripos($p['client_name'], $search) !== false;
    });
}
$flash = getFlash();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container" style="padding-top: 32px;">
    <?php if ($flash): ?>
    <div class="card" style="background:#FFFBEB; border:1px solid #FCD34D;">
        <strong>![⚠️](https://fonts.gstatic.com/s/e/notoemoji/17.0/26a0_fe0f/72.png) <?= escape($flash['message']) ?></strong>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['sent'])): ?>
    <div class="card" style="background:#ECFDF5; border:1px solid #A7F3D0;">Change order sent to client.</div>
    <?php endif; ?>

    <div class="flex justify-between items-center mb-4"><h2>Projects</h2><a href="project-create.php" class="btn btn-primary">+ New Project</a></div>
    <div class="card"><form method="GET"><input type="text" name="q" class="form-control" placeholder="Search projects..." value="<?= escape($search) ?>"></form></div>
    <?php if (!$projects): ?>
    <div class="empty-state">
        <div class="empty-icon">📋</div>
        <h3><?= $search ? 'No matches' : 'No projects yet' ?></h3>
        <p><?= $search ? 'Try a different search term or create a new project.' : 'Create a project to begin tracking scope and changes.' ?></p>
        <a href="project-create.php" class="btn btn-primary" style="margin-top:24px;">Create Project</a>
    </div>
    <?php else: foreach ($projects as $p):
        $sc = 'gray';
        if (($p['status'] ?? '') === 'active') {
            $sc = 'green';
        } elseif (($p['status'] ?? '') === 'draft') {
            $sc = 'yellow';
        }
    ?>
    <div class="card project-card">
        <div><div class="flex gap-2 items-center"><strong><?= escape($p['name']) ?></strong><span class="badge badge-<?= $sc ?>"><?= escape(ucfirst($p['status'])) ?></span></div><div class="project-meta">Client: <?= escape($p['client_name']) ?> · <?= formatMoney((float)$p['price'], $currency) ?> (<?= escape($p['pricing_type']) ?>)</div><div class="project-meta">Scope: <?= (int)$p['scope_count'] ?> · Changes: <?= (int)$p['change_count'] ?> · Saved: <span class="text-green"><?= formatMoney((float)$p['saved_amount'], $currency) ?></span></div></div>
        <div class="flex gap-2"><a href="project-detail.php?id=<?= (int)$p['id'] ?>" class="btn btn-secondary btn-sm">View</a><a href="change-log.php?project_id=<?= (int)$p['id'] ?>" class="btn btn-primary btn-sm">Log Change</a></div>
    </div>
    <?php endforeach; endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
