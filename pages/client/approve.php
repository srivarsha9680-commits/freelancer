<?php
// Public page — no requireAuth()
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/emails.php';

$token = $_GET['token'] ?? '';
$change = null;
$error = null;

if (strlen($token) === 64 && ctype_xdigit($token)) {
    try {
        checkRateLimit('client:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), RATE_LIMIT_CLIENT_MAX, RATE_LIMIT_CLIENT_WINDOW);
        $change = getChangeOrderByToken($token);
    } catch (RateLimitException $e) {
        $error = $e->getMessage();
    }
}

if (!$change && !$error) {
    http_response_code(404);
    $error = "Invalid or expired link.";
}

$newTotal = (float)$change['project_price'] + (float)$change['estimated_value'];
$brandColor = $change['branding_color'] ?? '#10B981';
$approved = false;
$declined = false;

if ($change && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        checkRateLimit('client_act:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), RATE_LIMIT_CLIENT_MAX, RATE_LIMIT_CLIENT_WINDOW);

        if (isset($_POST['approve'])) {
            $pdo->prepare("UPDATE change_orders SET status = 'approved', responded_at = CURRENT_TIMESTAMP, client_ip = ? WHERE id = ? AND client_token = ?")
                ->execute([$_SERVER['REMOTE_ADDR'] ?? '', $change['id'], $token]);
            $pdo->prepare("INSERT INTO activity_log (tenant_id, user_id, action, description, project_id, change_order_id) VALUES (?, 0, 'change.approved', ?, ?, ?)")
                ->execute([$change['tenant_id'], "Client approved change for '{$change['project_name']}'.", $change['project_id'], $change['id']]);
            auditLog('change.approved.by_client', null, $change['tenant_id'], ['change_id' => $change['id']]);

            sendApprovalNotification($change, $change['tenant_name'] ?? APP_NAME, true);

            $approved = true;
        } elseif (isset($_POST['decline'])) {
            $reason = str_replace(["\r", "\n", "\x00"], '', $_POST['reason'] ?? '');
            $pdo->prepare("UPDATE change_orders SET status = 'declined', decline_reason = ?, responded_at = CURRENT_TIMESTAMP, client_ip = ? WHERE id = ? AND client_token = ?")
                ->execute([$reason, $_SERVER['REMOTE_ADDR'] ?? '', $change['id'], $token]);
            $pdo->prepare("INSERT INTO activity_log (tenant_id, user_id, action, description, project_id, change_order_id) VALUES (?, 0, 'change.declined', ?, ?, ?)")
                ->execute([$change['tenant_id'], "Client declined change for '{$change['project_name']}'.", $change['project_id'], $change['id']]);
            auditLog('change.declined.by_client', null, $change['tenant_id'], ['change_id' => $change['id']]);

            sendApprovalNotification($change, $change['tenant_name'] ?? APP_NAME, false);

            $declined = true;
        }
    } catch (RateLimitException $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Order</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: <?= $brandColor ?>; --gray-50:#F9FAFB; --gray-100:#F3F4F6; --gray-200:#E5E7EB; --gray-600:#4B5563; --gray-900:#1F2937; --radius:8px; }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:var(--gray-50); padding:40px 24px; }
        .container { max-width:600px; margin:0 auto; }
        .logo { text-align:center; margin-bottom:32px; font-family:'Space Grotesk',sans-serif; font-weight:700; color:var(--gray-900); font-size:1.25rem; }
        .card { background:white; border-radius:var(--radius); padding:32px; box-shadow:0 1px 3px rgba(0,0,0,0.1); margin-bottom:16px; }
        .divider { height:1px; background:var(--gray-200); margin:24px 0; }
        .cost-box { background:var(--gray-900); color:white; padding:32px; border-radius:var(--radius); text-align:center; margin:24px 0; }
        .cost-box .amount { font-size:3rem; font-weight:700; }
        .btn { display:block; width:100%; padding:16px; border-radius:var(--radius); font-size:1.125rem; font-weight:600; cursor:pointer; border:none; margin-bottom:12px; text-align:center; font-family:'Inter',sans-serif; }
        .btn-success { background:var(--primary); color:white; }
        .btn-success:hover { opacity:0.9; }
        .btn-outline { background:white; color:var(--gray-600); border:2px solid var(--gray-200); }
        .details { background:var(--gray-50); padding:20px; border-radius:var(--radius); margin:16px 0; }
        .details li { margin-bottom:8px; list-style:none; }
        .footer { text-align:center; color:var(--gray-600); font-size:0.875rem; margin-top:32px; }
        .success-icon { font-size:4rem; margin-bottom:16px; }
        textarea { width:100%; padding:12px; border-radius:var(--radius); border:1px solid var(--gray-200); font-family:'Inter',sans-serif; resize:vertical; min-height:80px; }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($error): ?>
        <div class="card" style="text-align:center;"><h2>Link Error</h2><p style="margin:16px 0; color:var(--gray-600);"><?= htmlspecialchars($error) ?></p></div>
        <?php elseif ($approved): ?>
        <div class="card" style="text-align:center;">
            <div class="success-icon">![✅](https://fonts.gstatic.com/s/e/notoemoji/17.0/2705/72.png)</div>
            <h2>Approved!</h2>
            <p style="margin:16px 0;"><strong><?= htmlspecialchars($change['request_description']) ?></strong></p>
            <div class="divider"></div>
            <p>Added: <strong>$<?= number_format((float)$change['estimated_value'], 2) ?></strong></p>
            <p>New Total: <strong>$<?= number_format($newTotal, 2) ?></strong></p>
        </div>
        <?php elseif ($declined): ?>
        <div class="card" style="text-align:center;">
            <h2>Declined</h2>
            <p style="margin:16px 0;"><strong><?= htmlspecialchars($change['request_description']) ?></strong></p>
            <p style="margin-top:16px; color:var(--gray-600);"><?= htmlspecialchars($change['freelancer_name']) ?> has been notified.</p>
        </div>
        <?php else: ?>
        <div class="logo"><?= htmlspecialchars($change['company_name'] ?? $change['freelancer_name']) ?></div>
        <div class="card">
            <h2>Change Order Request</h2>
            <p style="color:var(--gray-600); margin-top:8px;">Project: <?= htmlspecialchars($change['project_name']) ?><br>From: <?= htmlspecialchars($change['freelancer_name']) ?></p>
            <div class="divider"></div>
            <h3 style="margin-bottom:12px;">Requested:</h3>
            <p><?= htmlspecialchars($change['request_description']) ?></p>
            <div class="details"><h4 style="margin-bottom:12px;">Details</h4><ul><li>• Time: <?= (float)$change['estimated_hours'] ?> hrs</li><li>• Impact: <?= htmlspecialchars($change['timeline_impact'] ?? 'N/A') ?></li></ul></div>
            <div class="cost-box"><div class="amount">$<?= number_format((float)$change['estimated_value'], 2) ?></div><div style="margin-top:8px;">USD</div></div>
            <form method="POST">
                <button type="submit" name="approve" class="btn btn-success">✓ Approve $<?= number_format((float)$change['estimated_value'], 2) ?></button>
                <details><summary class="btn btn-outline" style="list-style:none; cursor:pointer;">✕ Decline</summary>
                    <div style="margin-top:12px;"><textarea name="reason" placeholder="Reason..." maxlength="2000"></textarea><button type="submit" name="decline" class="btn btn-outline" style="margin-top:12px;">Confirm Decline</button></div>
                </details>
            </form>
        </div>
        <?php endif; ?>
        <div class="footer"><p>Questions? Reply to the email.</p><p style="margin-top:8px;">![🔒](https://fonts.gstatic.com/s/e/notoemoji/17.0/1f512/72.png) Secured by <?= isset($change) ? htmlspecialchars($change['tenant_name']) : 'Scope Creep Defender' ?></p></div>
    </div>
</body>
</html>
