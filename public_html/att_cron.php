<?php
declare(strict_types=1);

/**
 * Attendance housekeeping — COMMAND LINE ONLY.
 *
 *     php att_cron.php
 *
 * Two jobs:
 *  1. Forgotten exits: entries still open well after their shift ended are
 *     closed at the shift end (plus approved overtime) and marked "not
 *     scanned out" — Setup › Settings, "Automatic OUT".
 *  2. Delete the pictures taken with flagged entries once they are older than
 *     the retention period (Setup › Settings, 90 days unless changed). The
 *     flag, its reason and its review are kept; only the image goes.
 *
 * Both also run by themselves when phones send scans and when HR or Reports
 * screens are opened, so a timer is a back-up, not a requirement. Every 30
 * minutes keeps the automatic OUT on time even on a quiet night:
 *
 *     0,30 * * * *  php /path/to/public_html/att_cron.php
 *
 * It refuses to run over HTTP, for the same reason att_migrate.php does.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/storage.php';
foreach (['Db', 'Time', 'Policy', 'Audit', 'Roles', 'Employees', 'Shifts', 'Receipts', 'AttendanceService', 'SyncService', 'Http', 'Storage', 'Images', 'PhotoService'] as $c) {
    require_once __DIR__ . "/includes/att/$c.php";
}

use Att\AttendanceService;
use Att\Db;
use Att\PhotoService;
use Att\Storage;

try {
    Db::useUtc();
    $a = AttendanceService::autoCloseDue();
    echo empty($a['enabled']) ? "Automatic OUT is switched off.\n" : sprintf("Automatic OUT: %d forgotten exit(s) closed.\n", $a['closed']);
    if (!Storage::configured()) {
        echo "Photo storage is not connected; no photos to clear.\n";
        exit(0);
    }
    $r = PhotoService::purgeExpired(1000);
    printf("Flagged photos older than %d days: %d deleted, %d could not be deleted.\n", $r['retention_days'], $r['purged'], $r['failed']);
    exit($r['failed'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ATTENDANCE HOUSEKEEPING FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
