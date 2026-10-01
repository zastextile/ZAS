<?php
function db(): PDO {
    static $pdo = null;
    global $config;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = $config['db_host'];
    $name = $config['db_name'];
    $charset = $config['db_charset'] ?? 'utf8mb4';
    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";

    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
