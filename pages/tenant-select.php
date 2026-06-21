<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();
$tenants = getUserTenants($_SESSION['user_id']);
$info = '';
if (!$tenants) {
    $info = 'You are not assigned to any workspace yet. Please ask your administrator to add you to a workspace, or create a new workspace if this account is an admin.';
}
require_once __DIR__ . '/../includes/header.php';
$selected = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf()) throw new ValidationException('Invalid request.');
        $selected = validateString($_POST['tenant_id'] ?? '', 1, 50, 'Tenant');
        if (!ctype_digit($selected)) throw new ValidationException('Invalid tenant selection.');
        $tenant = null;
        foreach ($tenants as $t) {
            if ((int)$t['id'] === (int)$selected) {
                $tenant = $t;
                break;
            }
        }
        if (!$tenant) throw new ValidationException('Tenant not found.');
        switchTenant((int)$tenant['id'], $tenant['slug']);
        redirect('/pages/dashboard.php');
    } catch (ValidationException $e) {
        $error = $e->getMessage();
    }
}
?>
<div class="container" style="padding-top:32px;">
    <div class="card">
        <h2>Select Workspace</h2>
        <?php if ($info): ?><div class="badge badge-yellow mb-4"><?= escape($info) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="badge badge-red mb-4"><?= escape($error) ?></div><?php endif; ?>
        <?php if (!$tenants): ?>
            <p class="text-muted">No workspace assignments were found for this account.</p>
            <div style="margin-top:16px;">
                <a href="<?= appPath('/pages/logout.php') ?>" class="btn btn-secondary">Sign out and return to login</a>
            </div>
        <?php else: ?>
            <form method="POST"><?= csrfField() ?>
                <div class="form-group">
                    <label for="tenant_id">Choose a workspace</label>
                    <select name="tenant_id" id="tenant_id" class="form-control" required>
                        <option value="">Select a workspace</option>
                        <?php foreach ($tenants as $tenant): ?>
                            <option value="<?= (int)$tenant['id'] ?>" <?= $selected === (string)$tenant['id'] ? 'selected' : '' ?>><?= escape($tenant['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Continue</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
