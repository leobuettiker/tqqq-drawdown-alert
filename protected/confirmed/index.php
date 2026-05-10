<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../app/functions.php';

$config = app_config();
$ticker = strtoupper($config['source']['ticker'] ?? 'TQQQ');
$password = (string)($config['app']['confirm_password'] ?? '');

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

$message = null;
$error = null;

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $submitted = (string)($_POST['password'] ?? '');
    if ($password !== '' && hash_equals($password, $submitted)) {
        $_SESSION['confirm_authenticated'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $error = 'The password is incorrect.';
}

if (isset($_GET['logout'])) {
    unset($_SESSION['confirm_authenticated']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$authenticated = (bool)($_SESSION['confirm_authenticated'] ?? false);

if ($authenticated && isset($_POST['action']) && $_POST['action'] === 'confirm') {
    try {
        if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Invalid form token. Please reload the page.');
        }

        $id = (int)($_POST['id'] ?? 0);
        $quantity = parse_decimal($_POST['quantity'] ?? null);
        $price = parse_decimal($_POST['price'] ?? null);
        $confirmedAtRaw = trim((string)($_POST['confirmed_at'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        if ($id <= 0) {
            throw new RuntimeException('Invalid alert ID.');
        }
        if ($quantity === null || (float)$quantity <= 0) {
            throw new RuntimeException('Please enter a valid quantity greater than 0.');
        }
        if ($price === null || (float)$price <= 0) {
            throw new RuntimeException('Please enter a valid buy price greater than 0.');
        }

        $confirmedAt = null;
        if ($confirmedAtRaw !== '') {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $confirmedAtRaw);
            if (!$dt instanceof DateTime) {
                throw new RuntimeException('Invalid date/time format.');
            }
            $confirmedAt = $dt->format('Y-m-d H:i:s');
        } else {
            $confirmedAt = now_sql();
        }

        $stmt = db()->prepare(
            'UPDATE drawdown_alert_state
             SET confirmed_at = :confirmed_at,
                 confirmed_quantity = :quantity,
                 confirmed_price = :price,
                 confirmed_note = :note
             WHERE id = :id
               AND confirmed_at IS NULL
               AND reset_at IS NULL'
        );
        $stmt->execute([
            'confirmed_at' => $confirmedAt,
            'quantity' => $quantity,
            'price' => $price,
            'note' => $note === '' ? null : $note,
            'id' => $id,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The alert could not be confirmed. It may already be confirmed or reset.');
        }

        $_SESSION['flash_message'] = 'Buy confirmation saved.';
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_SESSION['flash_message'])) {
    $message = (string)$_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

$openAlerts = [];
$strategyByKey = [];
$latest = null;
$ath = null;
$currentDrawdown = null;

if ($authenticated) {
    try {
        foreach (read_strategy_csv($config['paths']['strategy_csv']) as $row) {
            $strategyByKey[$row['asset_ticker'] . '|' . $row['level_name']] = $row;
        }

        $stmt = db()->query(
            'SELECT * FROM drawdown_alert_state
             WHERE confirmed_at IS NULL
               AND reset_at IS NULL
             ORDER BY triggered_at ASC, ticker ASC, drawdown_percent DESC'
        );
        $openAlerts = $stmt ? $stmt->fetchAll() : [];

        $latest = latest_price_for_ticker($ticker);
        $ath = ath_for_ticker($ticker);
        if ($latest && $ath) {
            $currentDrawdown = calculate_drawdown_percent((float)$latest['nav'], (float)$ath['ath_nav']);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$defaultDateTime = date('Y-m-d\TH:i');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TQQQ Confirm</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="shell">
    <header class="header-card">
        <div class="brand-row">
            <div class="brand-mark">✓</div>
            <div>
                <div class="kicker">Protected area</div>
                <h1>Confirm drawdown buys</h1>
            </div>
        </div>
        <?php if ($authenticated): ?>
            <a class="logout" href="?logout=1">Log out</a>
        <?php endif; ?>
    </header>

    <?php if (!$authenticated): ?>
        <main class="login-card">
            <h2>Login</h2>
            <p>This area stores buy confirmations for active drawdown alerts.</p>
            <?php if ($error): ?><div class="alert alert--error"><?= h($error) ?></div><?php endif; ?>
            <form method="post" class="form-stack">
                <input type="hidden" name="action" value="login">
                <label>
                    <span>Password</span>
                    <input type="password" name="password" autocomplete="current-password" required autofocus>
                </label>
                <button type="submit">Log in</button>
            </form>
        </main>
    <?php else: ?>
        <?php if ($message): ?><div class="alert alert--success"><?= h($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert--error"><?= h($error) ?></div><?php endif; ?>

        <section class="summary-grid">
            <article class="summary-card">
                <span>Latest NAV</span>
                <strong><?= $latest ? format_decimal($latest['nav'], 4) : '–' ?></strong>
                <small><?= $latest ? h($latest['price_date']) : 'No data' ?></small>
            </article>
            <article class="summary-card">
                <span>ATH</span>
                <strong><?= $ath ? format_decimal($ath['ath_nav'], 4) : '–' ?></strong>
                <small><?= $ath ? h($ath['ath_date']) : 'Not computed' ?></small>
            </article>
            <article class="summary-card summary-card--accent">
                <span>Current drawdown</span>
                <strong><?= $currentDrawdown !== null ? format_percent($currentDrawdown, 2) : '–' ?></strong>
                <small>against ATH</small>
            </article>
            <article class="summary-card">
                <span>Open alerts</span>
                <strong><?= count($openAlerts) ?></strong>
                <small>confirm individually</small>
            </article>
        </section>

        <main class="alerts-grid">
            <?php if (count($openAlerts) === 0): ?>
                <section class="empty-state">
                    <div class="empty-icon">◎</div>
                    <h2>No open alerts</h2>
                    <p>There are currently no unconfirmed drawdown levels.</p>
                </section>
            <?php endif; ?>

            <?php foreach ($openAlerts as $alert): ?>
                <?php
                    $key = $alert['ticker'] . '|' . $alert['level_name'];
                    $strategy = $strategyByKey[$key] ?? null;
                ?>
                <article class="alert-card">
                    <div class="alert-card__header">
                        <div>
                            <div class="kicker"><?= h($alert['ticker']) ?></div>
                            <h2><?= h($alert['level_name']) ?></h2>
                        </div>
                        <div class="level-pill"><?= format_percent($alert['drawdown_percent'], 2) ?></div>
                    </div>

                    <dl class="facts">
                        <div><dt>Triggered</dt><dd><?= h($alert['triggered_at']) ?></dd></div>
                        <div><dt>Trigger NAV</dt><dd><?= format_decimal($alert['trigger_nav'], 4) ?></dd></div>
                        <div><dt>Trigger drawdown</dt><dd><?= format_percent($alert['trigger_drawdown_percent'], 2) ?></dd></div>
                        <div><dt>Strategy buy amount</dt><dd><?= $strategy ? format_decimal($strategy['amount_to_buy'], 2) : '–' ?></dd></div>
                    </dl>

                    <form method="post" class="confirm-form">
                        <input type="hidden" name="action" value="confirm">
                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                        <input type="hidden" name="id" value="<?= (int)$alert['id'] ?>">

                        <div class="form-grid">
                            <label>
                                <span>Buy date/time</span>
                                <input type="datetime-local" name="confirmed_at" value="<?= h($defaultDateTime) ?>" required>
                            </label>
                            <label>
                                <span>Quantity</span>
                                <input type="number" name="quantity" min="0" step="0.000001" inputmode="decimal" required>
                            </label>
                            <label>
                                <span>Buy price</span>
                                <input type="number" name="price" min="0" step="0.000001" inputmode="decimal" required>
                            </label>
                            <label class="span-all">
                                <span>Note</span>
                                <textarea name="note" rows="3" placeholder="Optional"></textarea>
                            </label>
                        </div>
                        <button type="submit">Confirm this buy</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </main>
    <?php endif; ?>
</div>
</body>
</html>
