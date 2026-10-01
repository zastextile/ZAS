<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: text/plain; charset=utf-8');

/*
 * Check common configuration locations.
 */
$possibleConfigPaths = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/app/config.php',
    __DIR__ . '/config.php',
];

$configPath = null;

foreach ($possibleConfigPaths as $path) {
    if (is_file($path)) {
        $configPath = $path;
        break;
    }
}

if ($configPath === null) {
    exit(
        "CONFIG FILE NOT FOUND\n\nChecked:\n" .
        implode("\n", $possibleConfigPaths)
    );
}

/*
 * Load configuration.
 */
try {
    $config = require $configPath;
} catch (Throwable $e) {
    exit(
        "CONFIG ERROR\n" .
        "Message: " . $e->getMessage() . "\n" .
        "File: " . $e->getFile() . "\n" .
        "Line: " . $e->getLine()
    );
}

if (!is_array($config)) {
    exit("CONFIG ERROR: The configuration file did not return an array.");
}

echo "Configuration loaded: OK\n";
echo "Configuration path: {$configPath}\n";

/*
 * Verify required database settings.
 */
$requiredKeys = [
    'db_host',
    'db_name',
    'db_user',
    'db_pass',
];

foreach ($requiredKeys as $key) {
    if (!array_key_exists($key, $config)) {
        exit("CONFIG ERROR: Missing setting: {$key}");
    }
}

echo "Database configuration keys: OK\n";

/*
 * Test database connection.
 */
try {
    $charset = $config['db_charset'] ?? 'utf8mb4';

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['db_host'],
        $config['db_name'],
        $charset
    );

    $pdo = new PDO(
        $dsn,
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );

    echo "Database connection: OK\n";

    $tableCount = $pdo
        ->query('SHOW TABLES')
        ->rowCount();

    echo "Database tables found: {$tableCount}\n";
} catch (Throwable $e) {
    exit(
        "DATABASE ERROR\n" .
        "Message: " . $e->getMessage()
    );
}

/*
 * Test PHP sessions.
 */
if (!empty($config['session_name'])) {
    session_name((string) $config['session_name']);
}

session_start();

$_SESSION['diagnostic_counter'] =
    ($_SESSION['diagnostic_counter'] ?? 0) + 1;

echo "Session test counter: " .
    $_SESSION['diagnostic_counter'] .
    "\n";

echo "\nDIAGNOSTIC COMPLETED";