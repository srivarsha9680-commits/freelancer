<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireAuth();
$tenant = requireTenant();
requireTenantRole('admin');
require_once __DIR__ . '/../includes/emails.php';
$members = getTenantMembers($tenant['id']);
$mCount = count($members);
$error = '';
$inviteSent = false;
require_once __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invite_email'])) {
    try {
        if (!verifyCsrf()) throw new ValidationException("Invalid request.");
        checkRateLimit("invite:{$tenant['id']}", RATE_LIMIT_INVITE_MAX, RATE_LIMIT_INVITE_WINDOW);
        $email = validateEmail($_POST['invite_email'] ?? '', 'Email');
        $role = validateEnum($_POST['invite_role'] ?? 'member', ['admin','member','viewer'], 'Role');
        if ($mCount >= $tenant['max_members']) throw new ValidationException("Member limit reached.");
        $token = createInvitation($tenant['id'], $_SESSION['user_id'], $email, $role);

        $acceptLink = APP_URL . "/pages/tenant-select.php?accept=" . $token;
        $inviter = getCurrentUser();
        sendInviteEmail($email, $tenant['name'], $inviter['full_name'] ?? 'Someone', $acceptLink);

        $inviteSent = true;
        logActivity($tenant['id'], $_SESSION['user_id'], 'member.invited', "Invited {$email}.");
        $members = getTenantMembers($tenant['id']);
    } catch (RateLimitException | ValidationException | RuntimeException $e) {
        $error = $e->getMessage();
    }
}

if (isset($_POST['change_role']) && verifyCsrf() && isTenantOwner($tenant['id'], $_SESSION['user_id'])) {
    $tid = (int)$_POST['user_id'];
    $nr = validateEnum($_POST['new_role'] ?? '', ['admin','member','viewer'], 'Role');
    $t = $pdo->prepare("SELECT role FROM tenant_members WHERE tenant_id = ? AND user_id = ?");
    $t->execute([$tenant['id'], $tid]);
    $tgt = $t->fetch();
    if ($tgt && $tgt['role'] !== 'owner') {
        $pdo->prepare("UPDATE tenant_members SET role = ? WHERE tenant_id = ? AND user_id = ?")->execute([$nr, $tenant['id'], $tid]);
        redirect('/pages/tenant-members.php');
    }
}

if (isset($_POST['remove_member']) && verifyCsrf()) {
    $tid = (int)$_POST['user_id'];
    $t = $pdo->prepare("SELECT role FROM tenant_members WHERE tenant_id = ? AND user_id = ?");
    $t->execute([$tenant['id'], $tid]);
    $tgt = $t->fetch();
    if ($tgt && $tgt['role'] !== 'owner') {
        $pdo->prepare("DELETE FROM tenant_members WHERE tenant_id = ? AND user_id = ?")->execute([$tenant['id'], $tid]);
        redirect('/pages/tenant-members.php');
    }
}
?>
<div class="container" style="padding-top: 32px;">
    <h2 class="mb-4">Team Members <span class="text-sm text-muted"><?= $mCount ?>/<?= (int)$tenant['max_members'] ?></span></h2>
    <?php if ($inviteSent): ?><div class="card" style="background:#ECFDF5; border:1px solid #A7F3D0;">Invitation sent!</div><?php endif; ?>
    <?php if ($error): ?><div class="badge badge-red mb-4" style="display:block; padding:8px 16px;"><?= escape($error) ?></div><?php endif; ?>
    <div class="card"><h3 style="margin-bottom:16px;">Invite Someone</h3>
        <form method="POST" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;"> <?= csrfField() ?>
            <div class="form-group" style="flex:1; min-width:200px; margin-bottom:0;"><label>Email</label><input type="email" name="invite_email" class="form-control" required maxlength="255"></div>
            <div class="form-group" style="min-width:140px; margin-bottom:0;"><label>Role</label><select name="invite_role" class="form-control"><option value="member">Member</option><option value="admin">Admin</option><option value="viewer">Viewer</option></select></div>
            <button type="submit" class="btn btn-primary" style="height:46px;">Send</button>
        </form>
    </div>
    <div class="card" style="padding:0; overflow:hidden;"><table class="members-table"><thead><tr><th>Member</th><th>Role</th><th>Joined</th><th></th></tr></thead><tbody>
    <?php foreach ($members as $m): ?>
    <tr><td><div class="flex items-center gap-2"><span class="avatar-sm"><?= escape(strtoupper(mb_substr($m['full_name'], 0, 1))) ?></span><div><div style="font-weight:600; font-size:0.875rem;"><?= escape($m['full_name']) ?></div><div class="text-sm text-muted"><?= escape($m['email']) ?></div></div></div></td>
    <td><?php if ($m['role'] === 'owner' || !isTenantOwner($tenant['id'], $_SESSION['user_id'])): ?><span class="badge badge-<?= $m['role'] === 'owner' ? 'green' : 'gray' ?>"><?= escape(ucfirst($m['role'])) ?></span><?php else: ?><form method="POST" style="display:inline;"> <?= csrfField() ?><input type="hidden" name="change_role" value="1"><input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>"><select name="new_role" class="form-control" style="padding:4px 8px; font-size:0.8125rem; width:auto;" onchange="this.form.submit()"><?php foreach (['admin','member','viewer'] as $r): ?><option value="<?= $r ?>" <?= $m['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?></select></form><?php endif; ?></td>
    <td class="text-sm text-muted"><?= date('M j, Y', strtotime($m['joined_at'])) ?></td>
    <td><?php if ($m['role'] !== 'owner'): ?><form method="POST" onsubmit="return confirm('Remove?')"> <?= csrfField() ?><input type="hidden" name="remove_member" value="1"><input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>"><button type="submit" class="btn btn-danger btn-sm">Remove</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
