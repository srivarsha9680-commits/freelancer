<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();
$tenant = requireTenant();
$projectId = (int)($_GET['id'] ?? 0);
$project = getTenantProject($projectId);
if (!$project) redirect('projects.php');
$scopeItems = getTenantScopeItems($projectId);
$changeOrders = getTenantChangeOrders($projectId);
$currency = $tenant['currency'] ?? 'USD';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="container" style="padding-top:32px; padding-bottom:32px;">
    <div class="card">
        <div class="justify-between items-center flex mb-4">
            <div>
                <h2><?= escape($project['name']) ?></h2>
                <p class="text-muted">Client: <?= escape($project['client_name']) ?> · <?= escape($project['client_email']) ?></p>
            </div>
            <div class="flex gap-2" style="flex-wrap:wrap;">
                <a href="projects.php" class="btn btn-secondary btn-sm">Back to Projects</a>
                <a href="change-log.php?project_id=<?= $projectId ?>" class="btn btn-primary btn-sm">Change Log</a>
            </div>
        </div>
        <div class="project-card">
            <div>
                <strong>Status</strong>
                <div class="badge badge-<?= $project['status'] === 'active' ? 'green' : ($project['status'] === 'draft' ? 'yellow' : 'gray') ?>" style="margin-top:0.5rem; display:inline-block;"><?= escape(ucfirst($project['status'])) ?></div>
            </div>
            <div>
                <strong>Price</strong>
                <div style="margin-top:0.5rem;"><?= escape(formatMoney((float)$project['price'], $currency)) ?> (<?= escape($project['pricing_type']) ?>)</div>
            </div>
            <div>
                <strong>Scope items</strong>
                <div style="margin-top:0.5rem;"><?= count($scopeItems) ?></div>
            </div>
            <div>
                <strong>Change orders</strong>
                <div style="margin-top:0.5rem;"><?= count($changeOrders) ?></div>
            </div>
        </div>

        <div class="card" style="margin-top:16px;">
            <h3>Project Scope</h3>
            <?php if (!$scopeItems): ?>
                <p class="text-muted">No scope items defined yet.</p>
            <?php else: ?>
                <ul style="margin:0; padding:0; list-style:none;">
                    <?php foreach ($scopeItems as $item): ?>
                        <li style="padding:14px 0; border-bottom:1px solid #e5e7eb;"><?= escape($item['description']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card" style="margin-top:16px;">
            <h3>Change orders</h3>
            <?php if (!$changeOrders): ?>
                <p class="text-muted">No change orders yet. Use the change log to add one.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Description</th><th>Status</th><th>Amount</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($changeOrders as $change): ?>
                            <tr>
                                <td><?= escape($change['request_description'] ?? 'No description') ?></td>
                                <td><span class="badge badge-<?= $change['status'] === 'pending' ? 'yellow' : ($change['status'] === 'approved' ? 'green' : 'gray') ?>"><?= escape(ucfirst($change['status'])) ?></span></td>
                                <td><?= escape(formatMoney((float)$change['estimated_value'], $currency)) ?></td>
                                <td><a href="change-preview.php?id=<?= (int)$change['id'] ?>" class="btn btn-secondary btn-sm">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>