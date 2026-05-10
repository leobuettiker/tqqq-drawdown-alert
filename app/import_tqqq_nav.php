<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/functions.php';

$jobName = 'import_tqqq_nav';
$startedAt = now_sql();
$verbose = PHP_SAPI === 'cli' && in_array('--verbose', $argv ?? [], true);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $config = app_config();
    $url = $config['source']['tqqq_nav_csv_url'];
    $sourceName = $config['source']['source_name'];
    $expectedTicker = strtoupper($config['source']['ticker'] ?? 'TQQQ');
    $minimumValidRows = (int)($config['source']['minimum_valid_rows'] ?? 100);

    $csv = fetch_url($url);
    $rows = csv_rows_from_string($csv);

    if (count($rows) < 2) {
        throw new RuntimeException('CSV contains no data rows.');
    }

    $header = array_map(static function ($v) {
        // ProFunds currently serves the CSV without a UTF-8 BOM, but stripping it
        // here makes the importer robust if that ever changes.
        return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$v));
    }, $rows[0]);
    $expectedHeader = [
        'Date',
        'ProShares Name',
        'Ticker',
        'NAV',
        'Prior NAV',
        'NAV Change (%)',
        'NAV Change ($)',
        'Shares Outstanding (000)',
        'Assets Under Management',
    ];

    if ($header !== $expectedHeader) {
        throw new RuntimeException(
            'Unexpected CSV header. Expected: ' . implode(',', $expectedHeader) . ' Found: ' . implode(',', $header)
        );
    }

    $importedAt = now_sql();
    $validRows = [];
    $invalidRows = 0;

    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (count(array_filter($row, function ($v) { return trim((string)$v) !== ''; })) === 0) {
            continue;
        }
        if (count($row) < count($expectedHeader)) {
            $invalidRows++;
            continue;
        }

        $date = parse_date_to_sql($row[0] ?? '');
        $name = trim((string)($row[1] ?? ''));
        $ticker = strtoupper(trim((string)($row[2] ?? '')));
        $nav = parse_decimal($row[3] ?? null);

        if ($date === null || $name === '' || $ticker !== $expectedTicker || $nav === null || (float)$nav <= 0) {
            $invalidRows++;
            continue;
        }

        $validRows[] = [
            'price_date' => $date,
            'name' => $name,
            'ticker' => $ticker,
            'nav' => $nav,
            'prior_nav' => parse_decimal($row[4] ?? null),
            'nav_change_percent' => parse_decimal($row[5] ?? null),
            'nav_change_amount' => parse_decimal($row[6] ?? null),
            'shares_outstanding_000' => parse_decimal($row[7] ?? null),
            'assets_under_management' => parse_decimal($row[8] ?? null),
            'source' => $sourceName,
            'imported_at' => $importedAt,
        ];
    }

    if (count($validRows) < $minimumValidRows) {
        throw new RuntimeException('Only ' . count($validRows) . ' valid rows found; expected at least ' . $minimumValidRows . '.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    $pdo->exec('DELETE FROM price_history');

    $insert = $pdo->prepare(
        'INSERT INTO price_history
         (price_date, name, ticker, nav, prior_nav, nav_change_percent, nav_change_amount, shares_outstanding_000, assets_under_management, source, imported_at)
         VALUES
         (:price_date, :name, :ticker, :nav, :prior_nav, :nav_change_percent, :nav_change_amount, :shares_outstanding_000, :assets_under_management, :source, :imported_at)'
    );

    foreach ($validRows as $item) {
        $insert->execute($item);
    }

    $verifyStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM price_history WHERE ticker = :ticker');
    $verifyStmt->execute(['ticker' => $expectedTicker]);
    $insertedRows = (int)($verifyStmt->fetch()['cnt'] ?? 0);
    if ($insertedRows !== count($validRows)) {
        throw new RuntimeException('Insert verification failed. Expected ' . count($validRows) . ' rows in price_history for ' . $expectedTicker . ', found ' . $insertedRows . '.');
    }

    $dbNameStmt = $pdo->query('SELECT DATABASE() AS db_name');
    $activeDbName = $dbNameStmt ? (string)($dbNameStmt->fetch()['db_name'] ?? '') : '';

    $athStmt = $pdo->prepare(
        'SELECT price_date, nav
         FROM price_history
         WHERE ticker = :ticker
         ORDER BY nav DESC, price_date ASC
         LIMIT 1'
    );
    $athStmt->execute(['ticker' => $expectedTicker]);
    $computedAth = $athStmt->fetch();

    if (!$computedAth) {
        throw new RuntimeException('ATH could not be computed after import.');
    }

    $oldAthStmt = $pdo->prepare('SELECT * FROM asset_ath WHERE ticker = :ticker');
    $oldAthStmt->execute(['ticker' => $expectedTicker]);
    $oldAth = $oldAthStmt->fetch() ?: null;

    $isNewEconomicAth = false;
    $athChanged = false;
    $athChangeReason = 'Initial ATH computed';
    if ($oldAth === null) {
        $athChanged = true;
    } else {
        $oldNav = (float)$oldAth['ath_nav'];
        $newNav = (float)$computedAth['nav'];
        $oldDate = (string)$oldAth['ath_date'];
        $newDate = (string)$computedAth['price_date'];

        $athChanged = $oldDate !== $newDate || abs($oldNav - $newNav) > 0.000001;

        // A higher NAV alone is not enough to start a new drawdown cycle.
        // Reverse splits or ProFunds history rebasings can mechanically lift the whole NAV series
        // while the economic high-water mark is still the same historical date.
        // Therefore confirmed/open alert states are reset only if the ATH is both higher
        // and on a later trading date than the previously stored ATH.
        $isNewEconomicAth = $newNav > $oldNav + 0.000001 && $newDate > $oldDate;

        if ($isNewEconomicAth) {
            $athChangeReason = 'New economic ATH';
        } elseif ($athChanged) {
            $athChangeReason = 'ATH data correction or split/rebase adjustment; alert cycle kept';
        } else {
            $athChangeReason = 'ATH unchanged';
        }
    }

    $upsertAth = $pdo->prepare(
        'INSERT INTO asset_ath
            (ticker, ath_date, ath_nav, previous_ath_date, previous_ath_nav, computed_at)
         VALUES
            (:ticker, :ath_date, :ath_nav, :previous_ath_date, :previous_ath_nav, :computed_at)
         ON DUPLICATE KEY UPDATE
            previous_ath_date = IF(ath_date <> VALUES(ath_date) OR ABS(ath_nav - VALUES(ath_nav)) > 0.000001, ath_date, previous_ath_date),
            previous_ath_nav = IF(ath_date <> VALUES(ath_date) OR ABS(ath_nav - VALUES(ath_nav)) > 0.000001, ath_nav, previous_ath_nav),
            ath_date = VALUES(ath_date),
            ath_nav = VALUES(ath_nav),
            computed_at = VALUES(computed_at)'
    );
    $upsertAth->execute([
        'ticker' => $expectedTicker,
        'ath_date' => $computedAth['price_date'],
        'ath_nav' => $computedAth['nav'],
        'previous_ath_date' => $oldAth['ath_date'] ?? null,
        'previous_ath_nav' => $oldAth['ath_nav'] ?? null,
        'computed_at' => now_sql(),
    ]);

    $resetCount = 0;
    if ($oldAth !== null && $isNewEconomicAth) {
        $reset = $pdo->prepare(
            'UPDATE drawdown_alert_state
             SET reset_at = :reset_at,
                 reset_reason = :reset_reason
             WHERE ticker = :ticker
               AND reset_at IS NULL'
        );
        $reset->execute([
            'reset_at' => now_sql(),
            'reset_reason' => 'New economic ATH',
            'ticker' => $expectedTicker,
        ]);
        $resetCount = $reset->rowCount();
    }

    $message = 'Imported ' . count($validRows) . ' rows. Invalid/skipped rows: ' . $invalidRows . '. ATH: ' . $computedAth['nav'] . ' on ' . $computedAth['price_date'] . '. ATH status: ' . $athChangeReason . '. Reset alert states: ' . $resetCount . '.';
    log_job($jobName, 'success', $message, $startedAt, count($validRows));

    $pdo->commit();

    if ($verbose) {
        echo 'OK import_tqqq_nav' . PHP_EOL;
        echo 'Database: ' . $activeDbName . PHP_EOL;
        echo 'Ticker: ' . $expectedTicker . PHP_EOL;
        echo 'Valid CSV rows: ' . count($validRows) . PHP_EOL;
        echo 'Rows in price_history after insert: ' . $insertedRows . PHP_EOL;
        echo 'Invalid/skipped rows: ' . $invalidRows . PHP_EOL;
        echo 'ATH: ' . $computedAth['nav'] . ' on ' . $computedAth['price_date'] . PHP_EOL;
        echo 'ATH status: ' . $athChangeReason . PHP_EOL;
        echo 'Reset alert states: ' . $resetCount . PHP_EOL;
    }

    // Important: no output on normal success. Many simple hosting cron tools can email command output.
} catch (Throwable $e) {
    try {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $rollbackError) {
        // Ignore rollback errors and report the original error below.
    }
    log_job($jobName, 'error', $e->getMessage(), $startedAt, null);
    echo cron_error_output($jobName, 'NAV import failed.', $e);
    exit(1);
} finally {
    restore_error_handler();
}
