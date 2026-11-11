<?php
declare(strict_types=1);

/**
 * Attendance housekeeping — COMMAND LINE ONLY.
 *
 *     php att_cron.php
 *
 * One job today: delete the pictures taken with flagged entries once they are
 * older than the retention period (Setup › Settings, 90 days unless changed).
 * The flag, its reason and its review are kept; only the image goes.
 *
 * The same clean-up also runs, at most once a day, whenever HR opens the
 * Reconciliation screen — so retention is kept even if this is never put on a
 * timer. A daily timer makes it independent of anybody opening a screen:
 *
 *     15 2 * * *  php /path/to/public_html/att_cron.php
 *
 * It refuses to run over HTTP, for the same reason att_migrate.php does.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/storage.php';
foreach (['Db', 'Time', 'Policy', 'Audit', 'Roles', 'Employees', 'Shifts', 'Receipts', 'SyncService', 'Http', 'Storage', 'Images', 'PhotoService'] as $c) {
    require_once __DIR__ . "/includes/att/$c.php";
}

use Att\Db;
use Att\PhotoService;
use Att\Storage;

try {
    Db::useUtc();
    if (!Storage::configured()) {
        echo "Photo storage is not connected; nothing to do.\n";
        exit(0);
    }
    $r = PhotoService::purgeExpired(1000);
    printf("Flagged photos older than %d days: %d deleted, %d could not be deleted.\n", $r['retention_days'], $r['purged'], $r['failed']);
    exit($r['failed'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ATTENDANCE HOUSEKEEPING FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
