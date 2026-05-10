<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/functions.php';

$jobName = 'check_drawdown_alerts';
$startedAt = now_sql();

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $config = app_config();
    $strategies = read_strategy_csv($config['paths']['strategy_csv']);
    if (count($strategies) === 0) {
        throw new RuntimeException('No drawdown strategies configured.');
    }

    $pdo = db();
    $outputBlocks = [];
    $stateIdsForOutput = [];
    $processed = 0;

    foreach ($strategies as $strategy) {
        $processed++;
        $ticker = strtoupper($strategy['asset_ticker']);
        $latest = latest_price_for_ticker($ticker);
        $ath = ath_for_ticker($ticker);

        if (!$latest) {
            throw new RuntimeException('No latest price found for ticker ' . $ticker . '.');
        }
        if (!$ath) {
            throw new RuntimeException('No ATH found for ticker ' . $ticker . '. Run import first.');
        }

        $currentNav = (float)$latest['nav'];
        $athNav = (float)$ath['ath_nav'];
        $currentDrawdown = calculate_drawdown_percent($currentNav, $athNav);
        $levelDrawdown = (float)$strategy['drawdown_percent'];

        if ($currentDrawdown > $levelDrawdown) {
            continue;
        }

        $pdo->beginTransaction();

        $findState = $pdo->prepare(
            'SELECT * FROM drawdown_alert_state
             WHERE ticker = :ticker
               AND level_name = :level_name
               AND ath_date = :ath_date
               AND reset_at IS NULL
             LIMIT 1'
        );
        $findState->execute([
            'ticker' => $ticker,
            'level_name' => $strategy['level_name'],
            'ath_date' => $ath['ath_date'],
        ]);
        $state = $findState->fetch() ?: null;

        if ($state === null) {
            $insert = $pdo->prepare(
                'INSERT INTO drawdown_alert_state
                    (ticker, level_name, drawdown_percent, ath_date, ath_nav, triggered_at, trigger_price_date, trigger_nav, trigger_drawdown_percent)
                 VALUES
                    (:ticker, :level_name, :drawdown_percent, :ath_date, :ath_nav, :triggered_at, :trigger_price_date, :trigger_nav, :trigger_drawdown_percent)'
            );
            $insert->execute([
                'ticker' => $ticker,
                'level_name' => $strategy['level_name'],
                'drawdown_percent' => $strategy['drawdown_percent'],
                'ath_date' => $ath['ath_date'],
                'ath_nav' => $ath['ath_nav'],
                'triggered_at' => now_sql(),
                'trigger_price_date' => $latest['price_date'],
                'trigger_nav' => $latest['nav'],
                'trigger_drawdown_percent' => number_format($currentDrawdown, 6, '.', ''),
            ]);
            $stateId = (int)$pdo->lastInsertId();

            $stateRead = $pdo->prepare('SELECT * FROM drawdown_alert_state WHERE id = :id');
            $stateRead->execute(['id' => $stateId]);
            $state = $stateRead->fetch();
        }

        $pdo->commit();

        if ($state && $state['confirmed_at'] === null) {
            $stateIdsForOutput[] = (int)$state['id'];
            $outputBlocks[] = [
                'state' => $state,
                'strategy' => $strategy,
                'latest' => $latest,
                'ath' => $ath,
                'current_drawdown' => $currentDrawdown,
            ];
        }
    }

    if (count($outputBlocks) > 0) {
        $tickers = array_values(array_unique(array_map(function ($b) { return $b['state']['ticker']; }, $outputBlocks)));
        $first = $outputBlocks[0];

        $text = "TQQQ drawdown alerts active" . PHP_EOL;
        $text .= str_repeat('=', 30) . PHP_EOL . PHP_EOL;
        $text .= "Current status" . PHP_EOL;
        $text .= "Ticker: " . implode(', ', $tickers) . PHP_EOL;
        $text .= "Latest NAV date: " . $first['latest']['price_date'] . PHP_EOL;
        $text .= "Current NAV: " . format_decimal($first['latest']['nav'], 6) . PHP_EOL;
        $text .= "ATH date: " . $first['ath']['ath_date'] . PHP_EOL;
        $text .= "ATH-NAV: " . format_decimal($first['ath']['ath_nav'], 6) . PHP_EOL;
        $text .= "Current drawdown: " . format_percent($first['current_drawdown'], 2) . PHP_EOL . PHP_EOL;
        $text .= "Active unconfirmed alerts" . PHP_EOL;
        $text .= str_repeat('-', 36) . PHP_EOL;

        foreach ($outputBlocks as $idx => $block) {
            $state = $block['state'];
            $strategy = $block['strategy'];
            $text .= PHP_EOL;
            $text .= ($idx + 1) . ") " . $state['level_name'] . PHP_EOL;
            $text .= "Threshold: " . format_percent($state['drawdown_percent'], 2) . PHP_EOL;
            $text .= "Strategy buy amount: " . format_decimal($strategy['amount_to_buy'], 2) . PHP_EOL;
            $text .= "Triggered at: " . $state['triggered_at'] . PHP_EOL;
            $text .= "Trigger NAV: " . format_decimal($state['trigger_nav'], 6) . PHP_EOL;
            $text .= "Trigger drawdown: " . format_percent($state['trigger_drawdown_percent'], 2) . PHP_EOL;
        }

        $text .= PHP_EOL . "Please confirm each buy individually in the protected confirmation area." . PHP_EOL;

        $placeholders = implode(',', array_fill(0, count($stateIdsForOutput), '?'));
        $update = $pdo->prepare('UPDATE drawdown_alert_state SET last_output_at = ? WHERE id IN (' . $placeholders . ')');
        $update->execute(array_merge([now_sql()], $stateIdsForOutput));

        log_job($jobName, 'success', 'Alert output produced for ' . count($stateIdsForOutput) . ' active alert(s).', $startedAt, $processed);
        echo $text;
    } else {
        log_job($jobName, 'success', 'No active alerts.', $startedAt, $processed);
        // Important: no output when no alert is active.
    }
} catch (Throwable $e) {
    try {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $rollbackError) {
        // Ignore rollback errors and report the original error below.
    }
    log_job($jobName, 'error', $e->getMessage(), $startedAt, null);
    echo cron_error_output($jobName, 'Drawdown check failed.', $e);
    exit(1);
} finally {
    restore_error_handler();
}
