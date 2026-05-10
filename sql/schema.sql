CREATE TABLE IF NOT EXISTS price_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    price_date DATE NOT NULL,
    name VARCHAR(255) NOT NULL,
    ticker VARCHAR(32) NOT NULL,
    nav DECIMAL(18,6) NOT NULL,
    prior_nav DECIMAL(18,6) NULL,
    nav_change_percent DECIMAL(12,6) NULL,
    nav_change_amount DECIMAL(18,6) NULL,
    shares_outstanding_000 DECIMAL(20,3) NULL,
    assets_under_management DECIMAL(24,2) NULL,
    source VARCHAR(255) NOT NULL,
    imported_at DATETIME NOT NULL,

    UNIQUE KEY uq_price_history_ticker_date (ticker, price_date),
    KEY idx_price_history_ticker_date (ticker, price_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_ath (
    ticker VARCHAR(32) PRIMARY KEY,
    ath_date DATE NOT NULL,
    ath_nav DECIMAL(18,6) NOT NULL,
    previous_ath_date DATE NULL,
    previous_ath_nav DECIMAL(18,6) NULL,
    computed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drawdown_alert_state (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    ticker VARCHAR(32) NOT NULL,
    level_name VARCHAR(100) NOT NULL,
    drawdown_percent DECIMAL(8,3) NOT NULL,

    ath_date DATE NOT NULL,
    ath_nav DECIMAL(18,6) NOT NULL,

    triggered_at DATETIME NOT NULL,
    trigger_price_date DATE NOT NULL,
    trigger_nav DECIMAL(18,6) NOT NULL,
    trigger_drawdown_percent DECIMAL(8,3) NOT NULL,

    last_output_at DATETIME NULL,

    confirmed_at DATETIME NULL,
    confirmed_quantity DECIMAL(18,6) NULL,
    confirmed_price DECIMAL(18,6) NULL,
    confirmed_note TEXT NULL,

    reset_at DATETIME NULL,
    reset_reason VARCHAR(255) NULL,

    UNIQUE KEY uq_alert_state (ticker, level_name, ath_date),
    KEY idx_alert_open (ticker, confirmed_at, reset_at),
    KEY idx_alert_level (ticker, level_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    status ENUM('success', 'warning', 'error') NOT NULL,
    message TEXT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NOT NULL,
    rows_processed INT NULL,

    KEY idx_job_log_name_time (job_name, finished_at),
    KEY idx_job_log_status_time (status, finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS periodic_summary (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticker VARCHAR(32) NOT NULL,
    period_start DATETIME NOT NULL,
    period_end DATETIME NOT NULL,
    subject VARCHAR(255) NOT NULL,
    summary_text MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL,

    KEY idx_periodic_summary_created (created_at),
    KEY idx_periodic_summary_ticker_created (ticker, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
