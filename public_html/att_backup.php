<?php
declare(strict_types=1);

/**
 * Attendance backup — COMMAND LINE ONLY.
 *
 *   php att_backup.php create            back up every att_ table now
 *   php att_backup.php list              the backups kept, newest first
 *   php att_backup.php verify FILE       prove FILE restores (live data untouched)
 *   php att_backup.php restore FILE --yes-replace-live-attendance-data
 *
 * Backups go to private_html/att-backups beside public_html (outside the web
 * root), the newest 14 are kept. --dir=PATH and --keep=N change that.
 *
 * If config.php has 'att_backup_key' => '…a long random phrase…', backups are
 * encrypted (AES-256-GCM). Keep a copy of that phrase somewhere safe OUTSIDE
 * the server: without it an encrypted backup cannot be read.
 *
 * Cloudways' own daily backup of the whole database stays the main backup.
 * This one exists so a restore can be TESTED on any day (verify), and so the
 * attendance data alone can be put back without rolling back the whole ERP.
 *
 * A daily cron job:  20 2 * * *  cd /path/to/public_html && php att_backup.php create
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';
foreach (['Db', 'Time', 'AttBackup'] as $c) {
    require_once __DIR__ . "/includes/att/$c.php";
}

use Att\AttBackup;
use Att\Db;

Db::useUtc();
$args = array_slice($argv ?? [], 1);
$cmd = $args[0] ?? 'help';
$opt = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', (string) $a, $m)) {
        $opt[$m[1]] = $m[2] ?? true;
    }
}
$dir = is_string($opt['dir'] ?? null) ? (string) $opt['dir'] : AttBackup::defaultDir(__DIR__);
$key = trim((string) ($config['att_backup_key'] ?? ''));
$key = $key !== '' ? $key : null;

switch ($cmd) {
    case 'create':
        $r = AttBackup::create($dir, $key, max(1, (int) ($opt['keep'] ?? 14)));
        if (!$r['ok']) { fwrite(STDERR, $r['error'] . "\n"); exit(1); }
        printf("Backed up %d tables, %d rows: %s (%s KB)%s\n", $r['tables'], $r['rows'], $r['file'], number_format($r['bytes'] / 1024, 1),
            $r['encrypted'] ? ', encrypted' : '');
        if (!$r['encrypted']) {
            echo "NOT ENCRYPTED: add 'att_backup_key' to config.php to encrypt backups. The folder is outside public_html; keep it private.\n";
        }
        exit(0);

    case 'list':
        $files = glob(rtrim($dir, '/') . '/att-*.sql.gz*') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        if ($files === []) { echo "No backups in $dir\n"; exit(0); }
        foreach ($files as $f) { printf("%s  %8s KB  %s\n", gmdate('Y-m-d H:i', (int) filemtime($f)) . ' UTC', number_format(filesize($f) / 1024, 1), $f); }
        exit(0);

    case 'verify':
        $file = (string) ($args[1] ?? '');
        if ($file === '' || str_starts_with($file, '--')) { fwrite(STDERR, "Which file? php att_backup.php verify FILE\n"); exit(2); }
        $r = AttBackup::verify($file, $key);
        foreach ($r['tables'] ?? [] as $t => $x) { printf("%-32s %8d rows  %s\n", $t, $x['rows'], $x['match'] ? 'OK' : 'DIFFERENT (expected ' . $x['expected_rows'] . ')'); }
        echo $r['ok'] ? "RESTORE TEST PASSED: this backup restores completely. Live data was not touched.\n" : 'RESTORE TEST FAILED: ' . ($r['error'] ?? '') . "\n";
        exit($r['ok'] ? 0 : 1);

    case 'restore':
        $file = (string) ($args[1] ?? '');
        if ($file === '' || ($opt['yes-replace-live-attendance-data'] ?? false) !== true) {
            fwrite(STDERR, "This REPLACES the live attendance tables with the backup.\n"
                . "A backup of the current data is taken first. To go ahead:\n"
                . "  php att_backup.php restore FILE --yes-replace-live-attendance-data\n");
            exit(2);
        }
        $r = AttBackup::restore($file, $key, $dir);
        if (!$r['ok']) { fwrite(STDERR, $r['error'] . "\n"); exit(1); }
        echo "Restored from $file.\nThe data as it was just before is in {$r['safety_backup']}\nNow run: php att_migrate.php up\n";
        exit(0);

    default:
        echo "php att_backup.php create | list | verify FILE | restore FILE --yes-replace-live-attendance-data\n";
        exit($cmd === 'help' ? 0 : 2);
}
