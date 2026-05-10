<?php

declare(strict_types=1);

require_once __DIR__ . '/app/functions.php';

$config = app_config();
$mail = $config['mail'] ?? [];

$enabled = (bool)($mail['enabled'] ?? false);
$testEnabled = (bool)($mail['test_enabled'] ?? false);
$to = trim((string)($mail['to_email'] ?? ''));
$fromEmail = trim((string)($mail['from_email'] ?? ''));
$fromName = trim((string)($mail['from_name'] ?? 'TQQQ Drawdown Alert'));
$replyTo = trim((string)($mail['reply_to'] ?? $fromEmail));
$subjectPrefix = trim((string)($mail['subject_prefix'] ?? '[TQQQ]'));

function mail_test_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mail_test_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

$result = null;
$error = null;

if (!$testEnabled) {
    $error = 'Mail test is disabled. Set mail.test_enabled to true in local/config.php to run this test.';
} elseif (!$enabled) {
    $error = 'Mail is disabled in local/config.php.';
} elseif ($to === '' || $fromEmail === '') {
    $error = 'Missing mail configuration. Please set mail.to_email and mail.from_email in local/config.php.';
} else {
    $subject = $subjectPrefix . ' Mail test';
    $body = "TQQQ Drawdown Alert mail test\n\n";
    $body .= "If you received this message, PHP mail() works on this hosting account.\n";
    $body .= "Generated at: " . now_sql() . "\n";

    $fromHeader = mail_test_header_value($fromName) . ' <' . mail_test_header_value($fromEmail) . '>';
    $headers = [
        'From: ' . $fromHeader,
        'Reply-To: ' . mail_test_header_value($replyTo),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
    ];

    $ok = mail($to, $subject, $body, implode("\r\n", $headers));
    $result = $ok ? 'Mail sent successfully.' : 'mail() returned false.';
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mail test</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f8fafc;color:#0f172a;font-family:system-ui,sans-serif}
        main{width:min(560px,calc(100% - 32px));background:white;border:1px solid #e2e8f0;border-radius:22px;padding:24px;box-shadow:0 20px 60px rgba(15,23,42,.08)}
        h1{margin:0 0 8px;font-size:1.7rem;letter-spacing:-.04em}
        p{color:#64748b;line-height:1.5}
        code{background:#f1f5f9;border-radius:6px;padding:2px 5px}
        .box{margin-top:18px;padding:14px;border-radius:14px;font-weight:800}
        .ok{color:#064e3b;background:#d1fae5}
        .error{color:#7f1d1d;background:#fee2e2}
        dl{display:grid;grid-template-columns:120px 1fr;gap:8px 12px;margin:18px 0 0;font-size:.95rem}
        dt{color:#64748b;font-weight:800}
        dd{margin:0;word-break:break-word}
    </style>
</head>
<body>
<main>
    <h1>Mail test</h1>
    <p>This page sends one test email using PHP <code>mail()</code> and the mail settings from <code>local/config.php</code>.</p>
    <p>It only runs when <code>mail.test_enabled</code> is explicitly set to <code>true</code>.</p>

    <?php if ($error !== null): ?>
        <div class="box error"><?= mail_test_h($error) ?></div>
    <?php else: ?>
        <div class="box <?= $result === 'Mail sent successfully.' ? 'ok' : 'error' ?>"><?= mail_test_h((string)$result) ?></div>
    <?php endif; ?>

    <dl>
        <dt>Test enabled</dt><dd><?= $testEnabled ? 'yes' : 'no' ?></dd>
        <dt>Mail enabled</dt><dd><?= $enabled ? 'yes' : 'no' ?></dd>
        <dt>To</dt><dd><?= mail_test_h($to) ?></dd>
        <dt>From</dt><dd><?= mail_test_h($fromName . ' <' . $fromEmail . '>') ?></dd>
        <dt>Reply-To</dt><dd><?= mail_test_h($replyTo) ?></dd>
        <dt>Subject</dt><dd><?= mail_test_h(($subjectPrefix ?: '[TQQQ]') . ' Mail test') ?></dd>
    </dl>
</main>
</body>
</html>
