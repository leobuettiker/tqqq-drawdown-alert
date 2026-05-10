<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/functions.php';

$jobName = 'send_periodic_summary';
$startedAt = now_sql();

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function summary_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

function summary_send_mail(array $mailConfig, string $subject, string $body): void
{
    if (!(bool)($mailConfig['enabled'] ?? false)) {
        throw new RuntimeException('Mail is disabled in configuration.');
    }

    $to = trim((string)($mailConfig['to_email'] ?? ''));
    $fromEmail = trim((string)($mailConfig['from_email'] ?? ''));
    $fromName = trim((string)($mailConfig['from_name'] ?? 'TQQQ Drawdown Alert'));
    $replyTo = trim((string)($mailConfig['reply_to'] ?? $fromEmail));

    if ($to === '' || $fromEmail === '') {
        throw new RuntimeException('Missing mail.to_email or mail.from_email configuration.');
    }

    $headers = [
        'From: ' . summary_header_value($fromName) . ' <' . summary_header_value($fromEmail) . '>',
        'Reply-To: ' . summary_header_value($replyTo),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
    ];

    if (!mail($to, $subject, $body, implode("\r\n", $headers))) {
        throw new RuntimeException('mail() returned false while sending the periodic summary.');
    }
}

function summary_openai_response(array $summaryConfig, string $prompt): string
{
    $apiKey = trim((string)($summaryConfig['openai_api_key'] ?? ''));
    if ($apiKey === '') {
        throw new RuntimeException('Missing summary.openai_api_key configuration.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required for the OpenAI API request.');
    }

    $model = trim((string)($summaryConfig['openai_model'] ?? 'gpt-5'));
    $endpoint = 'https://api.openai.com/v1/responses';

    $payload = [
        'model' => $model,
        'tools' => [
            [
                'type' => 'web_search',
                'external_web_access' => true,
            ],
        ],
        'tool_choice' => 'auto',
        'input' => $prompt,
    ];

    if (strpos($model, 'gpt-5') === 0) {
        $payload['reasoning'] = ['effort' => (string)($summaryConfig['reasoning_effort'] ?? 'low')];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => (int)($summaryConfig['openai_timeout_seconds'] ?? 90),
    ]);

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        throw new RuntimeException('OpenAI API request failed: ' . $error);
    }

    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('OpenAI API returned invalid JSON. HTTP status: ' . $status);
    }

    if ($status < 200 || $status >= 300) {
        $message = $json['error']['message'] ?? ('HTTP status ' . $status);
        throw new RuntimeException('OpenAI API error: ' . $message);
    }

    if (isset($json['output_text']) && trim((string)$json['output_text']) !== '') {
        return trim((string)$json['output_text']);
    }

    $parts = [];
    foreach (($json['output'] ?? []) as $item) {
        foreach (($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                $parts[] = (string)$content['text'];
            }
        }
    }

    $text = trim(implode("\n", $parts));
    if ($text === '') {
        throw new RuntimeException('OpenAI API response did not contain output text.');
    }

    return $text;
}

try {
    $config = app_config();
    $summaryConfig = $config['summary'] ?? [];
    if (!(bool)($summaryConfig['enabled'] ?? false)) {
        throw new RuntimeException('Periodic summary is disabled in configuration.');
    }

    $mailConfig = $config['mail'] ?? [];
    $ticker = strtoupper((string)($config['source']['ticker'] ?? 'TQQQ'));
    $minimumDays = max(1, (int)($summaryConfig['minimum_interval_days'] ?? 7));

    $pdo = db();

    $lastRunStmt = $pdo->prepare("SELECT finished_at FROM job_log WHERE job_name = :job_name AND status = 'success' ORDER BY finished_at DESC LIMIT 1");
    $lastRunStmt->execute(['job_name' => $jobName]);
    $lastRun = $lastRunStmt->fetch();

    $now = new DateTimeImmutable(now_sql());
    $minimumStart = $now->modify('-' . $minimumDays . ' days');
    $lastRunStart = null;
    if ($lastRun && !empty($lastRun['finished_at'])) {
        $lastRunStart = new DateTimeImmutable((string)$lastRun['finished_at']);
    }

    // Use the longer lookback: at least the configured minimum interval, or longer if the last successful run is older.
    $periodStart = $lastRunStart === null ? $minimumStart : min($minimumStart, $lastRunStart);
    $periodStartSql = $periodStart->format('Y-m-d H:i:s');
    $periodStartDate = $periodStart->format('Y-m-d');
    $periodEndSql = $now->format('Y-m-d H:i:s');

    $latest = latest_price_for_ticker($ticker);
    $ath = ath_for_ticker($ticker);
    if (!$latest || !$ath) {
        throw new RuntimeException('Missing latest NAV or ATH. Run the import job first.');
    }

    $startStmt = $pdo->prepare('SELECT * FROM price_history WHERE ticker = :ticker AND price_date >= :period_start ORDER BY price_date ASC LIMIT 1');
    $startStmt->execute(['ticker' => $ticker, 'period_start' => $periodStartDate]);
    $startPrice = $startStmt->fetch();
    if (!$startPrice) {
        $fallbackStmt = $pdo->prepare('SELECT * FROM price_history WHERE ticker = :ticker ORDER BY price_date ASC LIMIT 1');
        $fallbackStmt->execute(['ticker' => $ticker]);
        $startPrice = $fallbackStmt->fetch();
    }
    if (!$startPrice) {
        throw new RuntimeException('No start price found for summary period.');
    }

    $statsStmt = $pdo->prepare(
        'SELECT COUNT(*) AS rows_count, MIN(nav) AS min_nav, MAX(nav) AS max_nav
         FROM price_history
         WHERE ticker = :ticker AND price_date >= :period_start'
    );
    $statsStmt->execute(['ticker' => $ticker, 'period_start' => $periodStartDate]);
    $stats = $statsStmt->fetch() ?: [];

    $activeStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM drawdown_alert_state WHERE ticker = :ticker AND confirmed_at IS NULL AND reset_at IS NULL');
    $activeStmt->execute(['ticker' => $ticker]);
    $activeAlerts = (int)($activeStmt->fetch()['cnt'] ?? 0);

    $confirmedStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM drawdown_alert_state WHERE ticker = :ticker AND confirmed_at >= :period_start');
    $confirmedStmt->execute(['ticker' => $ticker, 'period_start' => $periodStartSql]);
    $confirmedBuys = (int)($confirmedStmt->fetch()['cnt'] ?? 0);

    $latestNav = (float)$latest['nav'];
    $startNav = (float)$startPrice['nav'];
    $periodChangePercent = $startNav > 0 ? (($latestNav / $startNav - 1.0) * 100.0) : 0.0;
    $currentDrawdown = calculate_drawdown_percent($latestNav, (float)$ath['ath_nav']);

    $data = [
        'ticker' => $ticker,
        'period_start' => $periodStartSql,
        'period_end' => $periodEndSql,
        'latest_nav_date' => $latest['price_date'],
        'latest_nav' => format_decimal($latest['nav'], 4),
        'period_start_nav_date' => $startPrice['price_date'],
        'period_start_nav' => format_decimal($startPrice['nav'], 4),
        'period_change_percent' => format_percent($periodChangePercent, 2),
        'ath_date' => $ath['ath_date'],
        'ath_nav' => format_decimal($ath['ath_nav'], 4),
        'current_drawdown' => format_percent($currentDrawdown, 2),
        'period_min_nav' => format_decimal($stats['min_nav'] ?? null, 4),
        'period_max_nav' => format_decimal($stats['max_nav'] ?? null, 4),
        'period_rows' => (int)($stats['rows_count'] ?? 0),
        'active_unconfirmed_alerts' => $activeAlerts,
        'confirmed_buys_in_period' => $confirmedBuys,
    ];

    $prompt = "You are writing a short upbeat periodic investment summary email in English.\n";
    $prompt .= "Use the local NAV data below and perform web research about the recent market context for TQQQ, Nasdaq-100, QQQ, large-cap technology stocks, interest rates, and AI/semiconductor sentiment during the same period.\n";
    $prompt .= "Write exactly three short paragraphs. Keep it positive and calm. If performance was weak, frame it constructively as volatility and a potential disciplined buying opportunity.\n";
    $prompt .= "Do not give personalized financial advice, do not mention that you are an AI, and do not include markdown headings or bullet points. Plain text only.\n\n";
    $prompt .= "Local data:\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $summaryText = summary_openai_response($summaryConfig, $prompt);

    $subjectPrefix = trim((string)($mailConfig['subject_prefix'] ?? '[TQQQ]'));
    $subject = $subjectPrefix . ' Periodic summary: ' . $periodStart->format('Y-m-d') . ' to ' . $now->format('Y-m-d');
    $body = $summaryText . "\n\n";
    $body .= "---\n";
    $body .= "Period: " . $periodStart->format('Y-m-d') . " to " . $now->format('Y-m-d') . "\n";
    $body .= "Latest NAV: " . $data['latest_nav'] . " on " . $data['latest_nav_date'] . "\n";
    $body .= "Period change: " . $data['period_change_percent'] . "\n";
    $body .= "Current drawdown: " . $data['current_drawdown'] . "\n";

    summary_send_mail($mailConfig, $subject, $body);
    log_job($jobName, 'success', 'Periodic summary sent for ' . $ticker . ' from ' . $periodStartSql . ' to ' . $periodEndSql . '.', $startedAt, (int)$data['period_rows']);

    // No output on success.
} catch (Throwable $e) {
    log_job($jobName, 'error', $e->getMessage(), $startedAt, null);
    echo cron_error_output($jobName, 'Periodic summary failed.', $e);
    exit(1);
} finally {
    restore_error_handler();
}
