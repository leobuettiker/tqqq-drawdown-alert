<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function now_sql(): string
{
    return date('Y-m-d H:i:s');
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parse_decimal($value): ?string
{
    if ($value === null) {
        return null;
    }
    $s = trim((string)$value);
    if ($s === '' || strtoupper($s) === 'N/A' || $s === '-') {
        return null;
    }

    $negative = false;
    if (preg_match('/^\((.*)\)$/', $s, $m)) {
        $negative = true;
        $s = $m[1];
    }

    $s = str_replace([',', '$', '%', "\xc2\xa0", ' '], '', $s);
    if ($s === '' || !is_numeric($s)) {
        return null;
    }

    $number = (float)$s;
    if ($negative) {
        $number *= -1;
    }

    return number_format($number, 6, '.', '');
}

function parse_date_to_sql(?string $value): ?string
{
    $s = trim((string)$value);
    if ($s === '') {
        return null;
    }

    $formats = ['Y-m-d', 'm/d/Y', 'n/j/Y', 'm/d/y', 'n/j/y', 'd.m.Y', 'j.n.Y'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat('!' . $format, $s);
        $errors = DateTime::getLastErrors();
        $hasErrors = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
        if ($dt instanceof DateTime && !$hasErrors) {
            return $dt->format('Y-m-d');
        }
    }

    $timestamp = strtotime($s);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }

    return null;
}

function fetch_url(string $url, int $timeoutSeconds = 30): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'header' => "User-Agent: TQQQ-Drawdown-Monitor/1.0\r\nAccept: text/csv,*/*\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $data = @file_get_contents($url, false, $context);
    if ($data !== false && trim($data) !== '') {
        return $data;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'TQQQ-Drawdown-Monitor/1.0',
        ]);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result !== false && trim((string)$result) !== '' && ($statusCode === 0 || ($statusCode >= 200 && $statusCode < 300))) {
            return (string)$result;
        }

        throw new RuntimeException('Download failed. HTTP status: ' . $statusCode . '. cURL error: ' . $error);
    }

    throw new RuntimeException('Download failed. file_get_contents returned no data and cURL is not available.');
}

function log_job(string $jobName, string $status, ?string $message, string $startedAt, ?int $rowsProcessed = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO job_log (job_name, status, message, started_at, finished_at, rows_processed)
             VALUES (:job_name, :status, :message, :started_at, :finished_at, :rows_processed)'
        );
        $stmt->execute([
            'job_name' => $jobName,
            'status' => $status,
            'message' => $message,
            'started_at' => $startedAt,
            'finished_at' => now_sql(),
            'rows_processed' => $rowsProcessed,
        ]);
    } catch (Throwable $e) {
        // Never let logging failures hide the original result of a cron job.
    }
}

function cron_error_output(string $jobName, string $message, ?Throwable $e = null): string
{
    $text = 'ERROR ' . $jobName . ': ' . $message . PHP_EOL;
    if ($e !== null) {
        $text .= 'Details: ' . $e->getMessage() . PHP_EOL;
    }
    return $text;
}

function csv_rows_from_string(string $csv): array
{
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        throw new RuntimeException('Could not create temporary CSV stream.');
    }
    fwrite($handle, $csv);
    rewind($handle);

    $rows = [];
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

function read_strategy_csv(string $path): array
{
    if (!is_readable($path)) {
        throw new RuntimeException('Strategy CSV is not readable: ' . $path);
    }

    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('Strategy CSV could not be opened: ' . $path);
    }

    $header = fgetcsv($handle, 0, ',', '"', '\\');
    $expected = ['asset_ticker', 'level_name', 'drawdown_percent', 'amount_to_buy'];
    if ($header !== $expected) {
        fclose($handle);
        throw new RuntimeException('Unexpected strategy CSV header. Expected: ' . implode(',', $expected));
    }

    $strategies = [];
    $line = 1;
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $line++;
        if (count(array_filter($row, function ($v) { return trim((string)$v) !== ''; })) === 0) {
            continue;
        }
        if (count($row) < 4) {
            fclose($handle);
            throw new RuntimeException('Invalid strategy CSV line ' . $line . '.');
        }

        $ticker = strtoupper(trim($row[0]));
        $levelName = trim($row[1]);
        $drawdown = parse_decimal($row[2]);
        $amount = parse_decimal($row[3]);

        if ($ticker === '' || $levelName === '' || $drawdown === null || (float)$drawdown >= 0) {
            fclose($handle);
            throw new RuntimeException('Invalid strategy values on line ' . $line . '. drawdown_percent must be negative.');
        }

        $strategies[] = [
            'asset_ticker' => $ticker,
            'level_name' => $levelName,
            'drawdown_percent' => $drawdown,
            'amount_to_buy' => $amount ?? '0.000000',
        ];
    }
    fclose($handle);

    return $strategies;
}

function latest_price_for_ticker(string $ticker): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM price_history WHERE ticker = :ticker ORDER BY price_date DESC LIMIT 1'
    );
    $stmt->execute(['ticker' => strtoupper($ticker)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ath_for_ticker(string $ticker): ?array
{
    $stmt = db()->prepare('SELECT * FROM asset_ath WHERE ticker = :ticker');
    $stmt->execute(['ticker' => strtoupper($ticker)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function calculate_drawdown_percent(float $currentNav, float $athNav): float
{
    if ($athNav <= 0) {
        throw new InvalidArgumentException('ATH NAV must be greater than zero.');
    }
    return ($currentNav / $athNav - 1.0) * 100.0;
}

function format_decimal($value, int $decimals = 2): string
{
    if ($value === null || $value === '') {
        return '–';
    }
    return number_format((float)$value, $decimals, '.', "'");
}

function format_percent($value, int $decimals = 2): string
{
    if ($value === null || $value === '') {
        return '–';
    }
    return number_format((float)$value, $decimals, '.', "'") . ' %';
}


function short_text(string $text, int $maxLength = 240): string
{
    $text = trim($text);
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $maxLength, '…', 'UTF-8');
    }
    if (strlen($text) <= $maxLength) {
        return $text;
    }
    return substr($text, 0, max(0, $maxLength - 3)) . '...';
}

function recent_job(string $jobName): ?array
{
    $stmt = db()->prepare('SELECT * FROM job_log WHERE job_name = :job_name ORDER BY finished_at DESC LIMIT 1');
    $stmt->execute(['job_name' => $jobName]);
    $row = $stmt->fetch();
    return $row ?: null;
}
