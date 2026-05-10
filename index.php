<?php

declare(strict_types=1);

require_once __DIR__ . '/app/functions.php';

$config = app_config();
$ticker = strtoupper($config['source']['ticker'] ?? 'TQQQ');
$title = $config['app']['dashboard_title'] ?? 'TQQQ NAV Monitor';

$pdo = db();
$latest = latest_price_for_ticker($ticker);
$ath = ath_for_ticker($ticker);

$drawdown = null;
if ($latest && $ath) {
    $drawdown = calculate_drawdown_percent((float)$latest['nav'], (float)$ath['ath_nav']);
}

$recentStmt = $pdo->prepare(
    'SELECT price_date, nav
     FROM price_history
     WHERE ticker = :ticker
     ORDER BY price_date DESC
     LIMIT 30'
);
$recentStmt->execute(['ticker' => $ticker]);
$recentRows = array_reverse($recentStmt->fetchAll());

$countStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM price_history WHERE ticker = :ticker');
$countStmt->execute(['ticker' => $ticker]);
$priceCount = (int)($countStmt->fetch()['cnt'] ?? 0);

$importJob = recent_job('import_tqqq_nav');
$alertJob = recent_job('check_drawdown_alerts');

$errorStmt = $pdo->query("SELECT * FROM job_log WHERE status = 'error' ORDER BY finished_at DESC LIMIT 1");
$lastError = $errorStmt ? ($errorStmt->fetch() ?: null) : null;

$labels = array_map(function ($r) { return $r['price_date']; }, $recentRows);
$navValues = array_map(function ($r) { return (float)$r['nav']; }, $recentRows);
$athValues = array_map(function () use ($ath) { return $ath ? (float)$ath['ath_nav'] : null; }, $recentRows);
$drawdownValues = [];
foreach ($navValues as $i => $navValue) {
    $athValue = $athValues[$i] ?? null;
    $drawdownValues[] = ($athValue !== null && $athValue > 0) ? calculate_drawdown_percent((float)$navValue, (float)$athValue) : null;
}

$latestNav = $latest ? (float)$latest['nav'] : null;
$priorNav = $latest && $latest['prior_nav'] !== null ? (float)$latest['prior_nav'] : null;
$dailyChange = ($latestNav !== null && $priorNav !== null && $priorNav > 0) ? (($latestNav / $priorNav - 1.0) * 100.0) : null;

$drawdownClass = $drawdown === null ? 'neutral' : ($drawdown <= -30 ? 'danger' : ($drawdown <= -20 ? 'warn' : 'ok'));
$statusText = $latest ? 'Data loaded' : 'No data yet';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="page-shell">
    <header class="hero">
        <div class="hero__glow"></div>
        <nav class="topbar">
            <div class="brand">
                <div class="brand__mark">T</div>
                <div>
                    <div class="brand__title"><?= h($title) ?></div>
                    <div class="brand__subtitle">Public NAV dashboard</div>
                </div>
            </div>
            <div class="status-pill status-pill--<?= h($drawdownClass) ?>">
                <span class="status-dot"></span>
                <?= h($statusText) ?>
            </div>
        </nav>

        <section class="hero-grid">
            <div class="hero-copy">
                <div class="eyebrow">ProShares UltraPro QQQ</div>
                <h1><?= h($ticker) ?> NAV Monitor</h1>
                <p>
                    Current NAV, all-time high and drawdown based on imported historical NAV data.
                    No strategy, buy or alert details are shown publicly.
                </p>
            </div>
            <div class="hero-card hero-card--main">
                <div class="metric-label">Latest NAV</div>
                <div class="metric-value"><?= $latestNav !== null ? format_decimal($latestNav, 4) : '–' ?></div>
                <div class="metric-subline">
                    <?= $latest ? h($latest['price_date']) : 'No import yet' ?>
                    <?php if ($dailyChange !== null): ?>
                        <span class="mini-change <?= $dailyChange >= 0 ? 'up' : 'down' ?>">
                            <?= $dailyChange >= 0 ? '+' : '' ?><?= format_percent($dailyChange, 2) ?> vs. Prior NAV
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </header>

    <main>
        <section class="cards-grid">
            <article class="stat-card">
                <div class="stat-card__icon">◇</div>
                <div>
                    <div class="stat-card__label">ATH NAV</div>
                    <div class="stat-card__value"><?= $ath ? format_decimal($ath['ath_nav'], 4) : '–' ?></div>
                    <div class="stat-card__hint"><?= $ath ? h($ath['ath_date']) : 'Not computed yet' ?></div>
                </div>
            </article>
            <article class="stat-card stat-card--<?= h($drawdownClass) ?>">
                <div class="stat-card__icon">↘</div>
                <div>
                    <div class="stat-card__label">Current drawdown</div>
                    <div class="stat-card__value"><?= $drawdown !== null ? format_percent($drawdown, 2) : '–' ?></div>
                    <div class="stat-card__hint">Against stored ATH</div>
                </div>
            </article>
            <article class="stat-card">
                <div class="stat-card__icon">↻</div>
                <div>
                    <div class="stat-card__label">Latest import</div>
                    <div class="stat-card__value stat-card__value--small"><?= $importJob ? h($importJob['finished_at']) : '–' ?></div>
                    <div class="stat-card__hint"><?= $importJob ? h($importJob['status']) . ' · ' . (int)$importJob['rows_processed'] . ' rows' : 'No job log' ?></div>
                </div>
            </article>
            <article class="stat-card">
                <div class="stat-card__icon">☷</div>
                <div>
                    <div class="stat-card__label">History</div>
                    <div class="stat-card__value"><?= format_decimal($priceCount, 0) ?></div>
                    <div class="stat-card__hint">stored NAV rows</div>
                </div>
            </article>
        </section>

        <section class="content-grid">
            <article class="panel panel--chart chart-surface" id="chartSurface">
                <div class="panel-header">
                    <div>
                        <h2>NAV history, last 30 trading days</h2>
                        <p>The ATH line shows the currently stored all-time high.</p>
                    </div>
                    <div class="chart-actions">
                        <div class="chart-badge">NAV · USD</div>
                        <button type="button" class="chart-action-button" id="chartFullscreenBtn" aria-label="Show chart fullscreen">⛶ Fullscreen</button>
                        <button type="button" class="chart-close-button" id="chartFullscreenClose" aria-label="Close fullscreen">×</button>
                    </div>
                </div>
                <div class="chart-wrap">
                    <canvas id="navChart"></canvas>
                </div>
            </article>

            <aside class="panel panel--side">
                <h2>System status</h2>
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="timeline-dot"></div>
                        <div>
                            <strong>Data import</strong>
                            <span><?= $importJob ? h($importJob['finished_at']) : 'Never run' ?></span>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-dot"></div>
                        <div>
                            <strong>Alert-Check</strong>
                            <span><?= $alertJob ? h($alertJob['finished_at']) : 'Never run' ?></span>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-dot <?= $lastError ? 'timeline-dot--error' : 'timeline-dot--ok' ?>"></div>
                        <div>
                            <strong>Latest error</strong>
                            <span><?= $lastError ? h($lastError['finished_at']) : 'No errors in the log' ?></span>
                        </div>
                    </div>
                </div>

                <?php if ($lastError): ?>
                    <div class="error-box">
                        <strong><?= h($lastError['job_name']) ?></strong>
                        <p><?= h(short_text((string)$lastError['message'], 240)) ?></p>
                    </div>
                <?php else: ?>
                    <div class="calm-box">
                        <strong>All quiet</strong>
                        <p>No error is currently stored in the job log.</p>
                    </div>
                <?php endif; ?>
            </aside>
        </section>
    </main>
</div>

<script>
const labels = <?= json_encode($labels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const navValues = <?= json_encode($navValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const athValues = <?= json_encode($athValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const drawdownValues = <?= json_encode($drawdownValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

const ctx = document.getElementById('navChart');
if (ctx && labels.length > 0) {
    const navChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'NAV',
                    data: navValues,
                    borderWidth: 3,
                    tension: 0.35,
                    pointRadius: 2,
                    pointHoverRadius: 5,
                    fill: true,
                    backgroundColor: 'rgba(79, 70, 229, 0.14)',
                    borderColor: 'rgba(79, 70, 229, 1)'
                },
                {
                    label: 'ATH',
                    data: athValues,
                    borderWidth: 2,
                    borderDash: [8, 6],
                    pointRadius: 0,
                    fill: false,
                    borderColor: 'rgba(245, 158, 11, 1)'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    labels: {
                        usePointStyle: true,
                        color: '#1f2937',
                        font: { weight: '600' }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = Number(context.parsed.y);
                            if (context.dataset.label === 'NAV') {
                                return 'NAV: ' + value.toFixed(4);
                            }
                            if (context.dataset.label === 'ATH') {
                                return 'ATH: ' + value.toFixed(4);
                            }
                            return context.dataset.label + ': ' + value.toFixed(4);
                        },
                        afterBody: function(items) {
                            if (!items || items.length === 0) {
                                return [];
                            }
                            const index = items[0].dataIndex;
                            const value = drawdownValues[index];
                            if (value === null || typeof value === 'undefined') {
                                return [];
                            }
                            const sign = value > 0 ? '+' : '';
                            return ['Drawdown: ' + sign + Number(value).toFixed(2) + ' %'];
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: '#64748b', maxTicksLimit: 6 }
                },
                y: {
                    grid: { color: 'rgba(148, 163, 184, 0.22)' },
                    ticks: { color: '#64748b' }
                }
            }
        }
    });


    const chartSurface = document.getElementById('chartSurface');
    const fullscreenBtn = document.getElementById('chartFullscreenBtn');
    const fullscreenClose = document.getElementById('chartFullscreenClose');

    function resizeChartSoon() {
        window.setTimeout(function () { navChart.resize(); }, 80);
        window.setTimeout(function () { navChart.resize(); }, 260);
    }

    function enterChartFullscreen() {
        if (!chartSurface) {
            return;
        }
        chartSurface.classList.add('is-fullscreen');
        document.body.classList.add('chart-fullscreen-open');
        resizeChartSoon();
        if (chartSurface.requestFullscreen) {
            chartSurface.requestFullscreen().catch(function () {});
        }
    }

    function exitChartFullscreen() {
        if (!chartSurface) {
            return;
        }
        chartSurface.classList.remove('is-fullscreen');
        document.body.classList.remove('chart-fullscreen-open');
        if (document.fullscreenElement && document.exitFullscreen) {
            document.exitFullscreen().catch(function () {});
        }
        resizeChartSoon();
    }

    if (fullscreenBtn) {
        fullscreenBtn.addEventListener('click', enterChartFullscreen);
    }
    if (fullscreenClose) {
        fullscreenClose.addEventListener('click', exitChartFullscreen);
    }
    document.addEventListener('fullscreenchange', function () {
        if (!document.fullscreenElement && chartSurface) {
            chartSurface.classList.remove('is-fullscreen');
            document.body.classList.remove('chart-fullscreen-open');
            resizeChartSoon();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            exitChartFullscreen();
        }
    });
}
</script>
</body>
</html>
