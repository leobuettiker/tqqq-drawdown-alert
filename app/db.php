<?php

declare(strict_types=1);

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $configPath = __DIR__ . '/../local/config.php';
        if (!is_file($configPath)) {
            $configPath = __DIR__ . '/config.php';
        }
        if (!is_file($configPath)) {
            throw new RuntimeException('Configuration file not found. Expected local/config.php. Copy examples/config.example.php to local/config.php and adjust it.');
        }
        $config = require $configPath;
        $tz = $config['app']['timezone'] ?? 'UTC';
        date_default_timezone_set($tz);
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    $db = $config['db'];
    $charset = $db['charset'] ?? 'utf8mb4';
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['database'], $charset);

    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
