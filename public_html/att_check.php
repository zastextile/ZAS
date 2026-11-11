<?php
declare(strict_types=1);

/**
 * Is this server ready for attendance? — COMMAND LINE ONLY.
 *
 *   php att_check.php
 *
 * Prints every check as OK / WARN / FAIL with what to do, and exits 1 if
 * anything FAILS. The same list is on Setup › System in the browser, which
 * also tests that the database files cannot be downloaded from the web.
 * Read-only: it changes nothing.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/storage.php';
foreach (['Db', 'Time', 'Migrations', 'Policy', 'Audit', 'Roles', 'Employees', 'Shifts', 'AdminService', 'Storage', 'AttBackup', 'SystemCheck'] as $c) {
    require_once __DIR__ . "/includes/att/$c.php";
}

use Att\Db;
use Att\SystemCheck;

Db::useUtc();
$r = SystemCheck::run(false);
$group = '';
foreach ($r['checks'] as $c) {
    if ($c['group'] !== $group) { $group = $c['group']; echo "\n== $group ==\n"; }
    printf("%-5s %s — %s\n", strtoupper($c['state']), $c['label'], $c['detail']);
    if ($c['state'] !== 'ok' && $c['fix'] !== '') { echo "      → {$c['fix']}\n"; }
}
printf("\n%d OK, %d WARN, %d FAIL — %s\n", $r['counts']['ok'], $r['counts']['warn'], $r['counts']['fail'], $r['ready'] ? 'READY' : 'NOT READY: fix the FAIL lines first');
exit($r['ready'] ? 0 : 1);
