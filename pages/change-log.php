<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();
$tenant = requireTenant();
$projectId = (int)($_GET['project_id'] ?? 0);
$project = getTenantProject($projectId);
if (!$project) redirect('projects.php');
$changes = getTenantChangeOrders($projectId);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf()) throw new ValidationException('Invalid request.');
        $requestDescription = validateString($_POST['request_description'] ?? '', 5, 2000, 'Change description');
        $estimatedValue = validateFloat($_POST['estimated_value'] ?? '0', 0, 10000000, 'Estimated value');
        $estimatedHours = validateFloat($_POST['estimated_hours'] ?? '0', 0, 10000, 'Estimated hours');
        $timelineImpact = validateString($_POST['timeline_impact'] ?? 'No change', 1, 255, 'Timeline impact');

        $stmt = $pdo->prepare("INSERT INTO change_orders (tenant_id, project_id, status, request_description, estimated_value, estimated_hours, timeline_impact, created_by, created_at) VALUES (?, ?, 'draft', ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([tenantId(), $projectId, $requestDescription, $estimatedValue, $estimatedHours, $timelineImpact, $_SESSION['user_id']]);
        redirect('change-log.php?project_id=' . $projectId);
    } catch (ValidationException | PDOException $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container" style="padding-top:32px; padding-bottom:32px;">
    <div class="card" style="max-width: 900px; margin:0 auto;">
        <div class="justify-between items-center flex mb-4">
            <div>
                <h2>Change Log</h2>
                <p class="text-muted">Track and send scope changes for <?= escape($project['name']) ?>.</p>
            </div>
            <div class="flex gap-2" style="flex-wrap:wrap;">
                <a href="project-detail.php?id=<?= $projectId ?>" class="btn btn-secondary btn-sm">Back to Project</a>
                <a href="projects.php" class="btn btn-secondary btn-sm">All Projects</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= escape($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="card" style="margin-bottom:16px;">
            <?= csrfField() ?>
            <div class="field-grid">
                <div class="form-group"><label class="input-label" for="request_description">Change description</label><textarea id="request_description" name="request_description" class="form-control" rows="4" required><?= escape($_POST['request_description'] ?? '') ?></textarea></div>
                <div class="form-group"><label class="input-label" for="estimated_value">Estimated value</label><input id="estimated_value" name="estimated_value" type="number" step="0.01" class="form-control" value="<?= escape($_POST['estimated_value'] ?? '0') ?>" required></div>
                <div class="form-group"><label class="input-label" for="estimated_hours">Estimated hours</label><input id="estimated_hours" name="estimated_hours" type="number" step="0.1" class="form-control" value="<?= escape($_POST['estimated_hours'] ?? '0') ?>" required></div>
                <div class="form-group"><label class="input-label" for="timeline_impact">Timeline impact</label><input id="timeline_impact" name="timeline_impact" type="text" class="form-control" value="<?= escape($_POST['timeline_impact'] ?? 'No change') ?>" required></div>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.75rem; flex-wrap:wrap; margin-top:1rem;">
                <button type="submit" class="btn btn-primary btn-sm">Save Change</button>
            </div>
        </form>

        <div class="card">
            <h3>Existing Changes</h3>
            <?php if (!$changes): ?>
                <p class="text-muted">No changes recorded yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Description</th><th>Status</th><th>Estimate</th><th>Date</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($changes as $change): ?>
                            <tr>
                                <td><?= escape($change['request_description'] ?? 'No description') ?></td>
                                <td><span class="badge badge-<?= $change['status'] === 'pending' ? 'yellow' : ($change['status'] === 'approved' ? 'green' : 'gray') ?>"><?= escape(ucfirst($change['status'])) ?></span></td>
                                <td><?= escape(formatMoney((float)$change['estimated_value'], $tenant['currency'] ?? 'USD')) ?></td>
                                <td><?= escape($change['created_at']) ?></td>
                                <td><a href="change-preview.php?id=<?= (int)$change['id'] ?>" class="btn btn-secondary btn-sm">Preview</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>