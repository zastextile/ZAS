<?php
declare(strict_types=1);

/**
 * Attendance migration runner — COMMAND LINE ONLY.
 *
 * Usage, from the directory this file is in:
 *     php att_migrate.php status
 *     php att_migrate.php up
 *     php att_migrate.php up --to=3
 *     php att_migrate.php down --to=0      (undoes everything the module added)
 *
 * It refuses to run over HTTP. A migration that can be triggered by a URL is a
 * migration that can be triggered by a crawler, by a double-tap on a phone, or
 * by fifty concurrent requests at once — and schema changes do not survive
 * being run fifty times at once.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/att/Db.php';
require_once __DIR__ . '/includes/att/Migrations.php';

use Att\Db;
use Att\Migrations;

/* Connect the same way the app does, unless ATT_DSN is set — which is how the
   test harness points this at a scratch database instead of the live one. */
$dsn = getenv('ATT_DSN');
if ($dsn !== false && $dsn !== '') {
    Db::bind(new PDO($dsn, (string) getenv('ATT_DB_USER'), (string) getenv('ATT_DB_PASS'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]));
} else {
    require_once __DIR__ . '/includes/bootstrap.php';
}

Db::useUtc();   // seeded rows and the migration log are stamped in UTC like everything else

$argvLocal = $argv ?? [];
$command   = $argvLocal[1] ?? 'status';
$to        = null;
foreach ($argvLocal as $a) {
    if (preg_match('/^--to=(\d+)$/', (string) $a, $m)) {
        $to = (int) $m[1];
    }
}

$m = Migrations::default();

try {
    switch ($command) {
        case 'status':
            printf("%-8s %-36s %-9s %s\n", 'VERSION', 'FILE', 'STATE', 'APPLIED AT');
            foreach ($m->status() as $row) {
                printf(
                    "%-8s %-36s %-9s %s\n",
                    sprintf('%04d', $row['version']),
                    $row['file'],
                    $row['state'],
                    $row['applied_at'] ?? '-'
                );
            }
            break;

        case 'up':
            foreach ($m->up($to) as $line) {
                echo $line, "\n";
            }
            break;

        case 'down':
            if ($to === null) {
                fwrite(STDERR, "down needs an explicit target, e.g. --to=0\n");
                exit(2);
            }
            foreach ($m->down($to) as $line) {
                echo $line, "\n";
            }
            break;

        default:
            fwrite(STDERR, "Unknown command '$command'. Use status, up or down.\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'MIGRATION FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
