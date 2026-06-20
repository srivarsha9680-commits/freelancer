<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();
$tenant = requireTenant();
require_once __DIR__ . '/../includes/emails.php';

$cid = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT co.*, p.name as project_name, p.client_name, p.client_email, u.full_name as creator_name FROM change_orders co JOIN projects p ON p.id = co.project_id JOIN users u ON u.id = co.created_by WHERE co.id = ? AND co.tenant_id = ?");
$stmt->execute([$cid, $tenant['id']]);
$change = $stmt->fetch();
if (!$change) redirect('projects.php');

require_once __DIR__ . '/../includes/header.php';

$currency = $tenant['currency'] ?? 'USD';
$_SESSION['user_name'] = $tenant['email_from_name'] ?? $change['creator_name'];
$emailContent = generateChangeOrderEmail($change, $change, $change['email_tone'] ?? 'friendly');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['tone']) && verifyCsrf()) {
            $tone = validateEnum($_POST['tone'], ['friendly', 'formal', 'assertive'], 'Tone');
            $pdo->prepare("UPDATE change_orders SET email_tone = ? WHERE id = ? AND tenant_id = ?")->execute([$tone, $cid, $tenant['id']]);
            $emailContent = generateChangeOrderEmail($change, $change, $tone);
            $change['email_tone'] = $tone;
        }

        if (isset($_POST['send']) && verifyCsrf()) {
            $token = generateClientToken();
            $approvalLink = APP_URL . "/client/approve.php?token=" . $token;

            $pdo->prepare("UPDATE change_orders SET status = 'pending', sent_at = CURRENT_TIMESTAMP, email_content = ?, client_token = ? WHERE id = ? AND tenant_id = ?")
                ->execute([$emailContent, $token, $cid, $tenant['id']]);

            $emailSent = sendChangeOrderEmail($change, $approvalLink, $tenant);

            logActivity($tenant['id'], $_SESSION['user_id'], 'change.sent', "Sent to {$change['client_email']}.", $change['project_id'], $cid);
            auditLog('change.sent', $_SESSION['user_id'], $tenant['id'], ['change_id' => $cid]);

            if ($emailSent) {
                redirect('projects.php?sent=1');
            } else {
                setFlash('warning', "Change order saved as pending, but the email failed to send. Copy this link to send manually: " . $approvalLink);
                redirect("change-preview.php?id=$cid");
            }
        }
    } catch (ValidationException $e) {
        $error = $e->getMessage();
    }
}

$flash = getFlash();
?>
<div class="container" style="padding-top: 32px; max-width: 700px;">
    <a href="change-log.php?project_id=<?= (int)$change['project_id'] ?>" class="btn btn-secondary mb-4">← Back</a>
    <h2 class="mb-4">Review & Send</h2>

    <?php if ($flash): ?>
    <div class="card" style="background:#FFFBEB; border:1px solid #FCD34D;">
        <strong>![⚠️](https://fonts.gstatic.com/s/e/notoemoji/17.0/26a0_fe0f/72.png) <?= escape($flash['message']) ?></strong>
    </div>
    <?php endif; ?>

    <?php if ($error): ?><div class="badge badge-red mb-4" style="display:block; padding:8px 16px;"><?= escape($error) ?></div><?php endif; ?>

    <div class="card">
        <div class="email-preview">
            <p><strong>To:</strong> <?= escape($change['client_email']) ?></p>
            <p><strong>Subject:</strong> Change Order</p>
            <hr style="margin:16px 0; border:none; border-top:1px solid var(--gray-200);">
            <pre style="white-space:pre-wrap; font-family:inherit;"><?= nl2br(escape($emailContent)) ?></pre>
        </div>
    </div>

    <form method="POST" class="card mt-4"><?= csrfField() ?>
        <div class="form-group"><label>Tone</label>
            <select name="tone" class="form-control" onchange="this.form.submit()">
                <?php foreach (['friendly','formal','assertive'] as $t): ?>
                <option value="<?= $t ?>" <?= ($change['email_tone'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <form method="POST" class="flex gap-4 mt-4"><?= csrfField() ?>
        <button type="submit" name="send" value="1" class="btn btn-primary">Send to Client</button>
        <a href="projects.php" class="btn btn-secondary">Save & Send Later</a>
    </form>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
