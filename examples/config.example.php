<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => 'localhost',
        'database' => 'CHANGE_ME_DATABASE',
        'user' => 'CHANGE_ME_USER',
        'password' => 'CHANGE_ME_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    'source' => [
        'tqqq_nav_csv_url' => 'https://accounts.profunds.com/etfdata/ByFund/TQQQ-historical_nav.csv',
        'source_name' => 'ProFunds historical NAV CSV',
        'ticker' => 'TQQQ',
        'minimum_valid_rows' => 100,
    ],

    'paths' => [
        'strategy_csv' => __DIR__ . '/drawdown_strategy.csv',
    ],

    'app' => [
        'timezone' => 'UTC',
        'confirm_password' => 'CHANGE_ME_CONFIRM_PASSWORD',
        'dashboard_title' => 'TQQQ NAV Monitor',
    ],

    'mail' => [
        'enabled' => true,
        'to_email' => 'CHANGE_ME_TO@example.com',
        'from_email' => 'CHANGE_ME_FROM@example.com',
        'from_name' => 'TQQQ Drawdown Alert',
        'reply_to' => 'CHANGE_ME_FROM@example.com',
        'subject_prefix' => '[TQQQ]',
    ],
];
