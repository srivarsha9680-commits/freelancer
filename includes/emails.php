<?php
/**
 * Email sending layer.
 *
 * Uses PHP mail() by default (works on all shared hosting).
 * To upgrade to SMTP, install PHPMailer via Composer and set SMTP_ENABLED=true
 * in config/database.php, then uncomment the PHPMailer block below.
 */

function sendEmail(string $to, string $subject, string $body, ?string $fromName = null, ?string $replyTo = null, ?int $tenantId = null): bool {
    $to = filter_var($to, FILTER_VALIDATE_EMAIL);
    if (!$to) return false;

    $fromEmail = SMTP_FROM_EMAIL;
    $appName = SMTP_FROM_NAME;

    if ($fromName) {
        $fromName = str_replace(["\r", "\n", "\x00"], '', $fromName);
    } else {
        $fromName = $appName;
    }

    $encodedFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: ScopeCreepDefender/1.0',
        'From: ' . $encodedFrom . ' <' . $fromEmail . '>',
    ];

    if ($replyTo) {
        $replyTo = filter_var($replyTo, FILTER_VALIDATE_EMAIL);
        if ($replyTo) $headers[] = 'Reply-To: ' . $replyTo;
    }

    $result = mail($to, $encodedSubject, $body, implode("\r\n", $headers));

    logEmail($to, $subject, $result, $result ? null : error_get_last()['message'] ?? 'Unknown error', $tenantId);

    return $result;
}

function logEmail(string $to, string $subject, bool $success, ?string $error = null, ?int $tenantId = null): void {
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO email_log (tenant_id, to_address, subject, status, error_text) VALUES (?, ?, ?, ?, ?)")
            ->execute([$tenantId, $to, mb_substr($subject, 0, 500), $success ? 'sent' : 'failed', $error]);
    } catch (Throwable $e) {
        error_log("Failed to log email: " . $e->getMessage());
    }
}

/* ============================================================
   Specific Email Templates
   ============================================================ */

function sendChangeOrderEmail(array $change, string $approvalLink, array $tenant): bool {
    $project = [
        'client_name' => $change['client_name'] ?? 'Client',
        'name' => $change['project_name'] ?? 'Project',
    ];

    $body = generateChangeOrderEmail($project, $change, $change['email_tone'] ?? 'friendly');
    $body .= "\n\n---\nTo review and respond, click here:\n" . $approvalLink;

    return sendEmail(
        $change['client_email'],
        'Change Order Request — ' . $change['project_name'],
        $body,
        $tenant['email_from_name'] ?? null,
        $tenant['email_reply_to'] ?? null,
        $tenant['id'] ?? null
    );
}

function sendInviteEmail(string $email, string $tenantName, string $inviterName, string $acceptLink): bool {
    $body = "Hi,\n\n{$inviterName} has invited you to join the \"{$tenantName}\" workspace on " . APP_NAME . ".\n\nTo accept, click here:\n{$acceptLink}\n\nThis link expires in 7 days.\n\n— " . APP_NAME;
    return sendEmail($email, "You're invited to {$tenantName}", $body);
}

function sendPasswordResetEmail(string $email, string $name, string $token): bool {
    $link = APP_URL . "/pages/reset-password.php?token=" . $token;
    $body = "Hi {$name},\n\nYou requested a password reset for your " . APP_NAME . " account.\n\nClick here to set a new password (expires in 1 hour):\n{$link}\n\nIf you didn't request this, you can safely ignore this email.\n\n— " . APP_NAME;
    return sendEmail($email, 'Password Reset — ' . APP_NAME, $body);
}

function sendApprovalNotification(array $change, string $tenantName, bool $approved): bool {
    global $pdo;
    $stmt = $pdo->prepare("SELECT u.email, u.full_name FROM users u JOIN change_orders co ON co.created_by = u.id WHERE co.id = ?");
    $stmt->execute([$change['id']]);
    $creator = $stmt->fetch();
    if (!$creator) return false;

    $status = $approved ? 'APPROVED' : 'DECLINED';
    $amount = '$' . number_format((float)$change['estimated_value'], 2);
    $body = "Hi {$creator['full_name']},\n\nYour client has {$status} the change order for \"{$change['project_name']}\".\n\n"
        . "Request: {$change['request_description']}\n"
        . "Amount: {$amount}\n";

    if (!$approved && !empty($change['decline_reason'])) {
        $body .= "Reason: {$change['decline_reason']}\n";
    }

    $body .= "\n— " . APP_NAME;
    return sendEmail($creator['email'], "Change Order {$status} — {$change['project_name']}", $body, $tenantName);
}
