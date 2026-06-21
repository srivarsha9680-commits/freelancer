<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();
$tenant = requireTenant();
$flash = getFlash();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf()) throw new ValidationException('Invalid request.');
        $name = validateString($_POST['name'] ?? '', 3, 255, 'Project name');
        $clientName = validateString($_POST['client_name'] ?? '', 3, 255, 'Client name');
        $clientEmail = validateEmail($_POST['client_email'] ?? '', 'Client email');
        $price = validateFloat($_POST['price'] ?? '0', 0, 10000000, 'Price');
        $pricingType = validateEnum($_POST['pricing_type'] ?? 'fixed', ['fixed', 'hourly'], 'Pricing type');
        $status = 'draft';

        $stmt = $pdo->prepare("INSERT INTO projects (tenant_id, name, client_name, client_email, price, status, pricing_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([tenantId(), $name, $clientName, $clientEmail, $price, $status, $pricingType]);

        setFlash('success', 'Project created successfully.');
        redirect('projects.php');
    } catch (ValidationException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        error_log('Project create error: ' . $e->getMessage());
        $error = 'Unable to save the project right now. Please try again later.';
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container" style="padding-top:32px; padding-bottom:32px;">
    <div class="card" style="max-width: 720px; margin: 0 auto;">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h2>Create Project</h2>
                <p class="text-muted">Add a new project to track client scope, pricing, and changes.</p>
            </div>
            <a href="projects.php" class="btn btn-secondary btn-sm">Back to Projects</a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-info"><?= escape($flash['message']) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= escape($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>
            <div class="field-grid">
                <div class="form-group">
                    <label class="input-label" for="name">Project Name</label>
                    <input id="name" name="name" type="text" class="form-control" value="<?= escape($_POST['name'] ?? '') ?>" placeholder="e.g. Website redesign" required>
                </div>
                <div class="form-group">
                    <label class="input-label" for="client_name">Client Name</label>
                    <input id="client_name" name="client_name" type="text" class="form-control" value="<?= escape($_POST['client_name'] ?? '') ?>" placeholder="e.g. Acme Corp" required>
                </div>
                <div class="form-group">
                    <label class="input-label" for="client_email">Client Email</label>
                    <input id="client_email" name="client_email" type="email" class="form-control" value="<?= escape($_POST['client_email'] ?? '') ?>" placeholder="client@example.com" required>
                </div>
                <div class="form-group">
                    <label class="input-label" for="price">Estimated Price</label>
                    <input id="price" name="price" type="number" step="0.01" class="form-control" value="<?= escape($_POST['price'] ?? '0') ?>" placeholder="0" required>
                </div>
                <div class="form-group">
                    <label class="input-label" for="pricing_type">Pricing Type</label>
                    <select id="pricing_type" name="pricing_type" class="form-control">
                        <option value="fixed" <?= (($_POST['pricing_type'] ?? '') === 'fixed') ? 'selected' : '' ?>>Fixed fee</option>
                        <option value="hourly" <?= (($_POST['pricing_type'] ?? '') === 'hourly') ? 'selected' : '' ?>>Hourly</option>
                    </select>
                </div>
            </div>
            <div style="margin-top: 1.5rem; display:flex; justify-content:flex-end; gap:0.75rem; flex-wrap:wrap;">
                <a href="projects.php" class="btn btn-secondary btn-sm">Cancel</a>
                <button type="submit" class="btn btn-primary btn-sm">Create Project</button>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>