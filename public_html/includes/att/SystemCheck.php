<?php
declare(strict_types=1);

namespace Att;

/**
 * "Is this server ready?" — one list of checks, each pass / warn / fail with
 * what to do about it. Used by `php att_check.php` and Setup › System.
 *
 * It only READS. The one thing that writes anything — the photo storage
 * round-trip — is a separate button (PhotoService::selfTest), not part of
 * this list.
 *
 * It never prints a password, a key or a secret: where a setting matters it
 * says whether it is set, not what it is.
 */
final class SystemCheck
{
    /** @var list<array{group:string,id:string,label:string,state:string,detail:string,fix:string}> */
    private array $rows = [];

    public static function run(bool $web = false): array
    {
        $c = new self();
        $c->server();
        $c->database();
        $c->files();
        $c->security($web);
        $c->setup();
        $c->housekeeping();
        $count = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($c->rows as $r) {
            $count[$r['state']]++;
        }
        return ['ok' => true, 'checks' => $c->rows, 'counts' => $count, 'ready' => $count['fail'] === 0,
                'generated_at' => Time::iso(Time::nowUtc()),
                // For the browser to try: none of these may be readable from the web.
                'web_probes' => ['sql/att/0001_departments_employees.sql', 'sql/att/0014_overtime.sql', 'includes/att/SystemCheck.php']];
    }

    private function add(string $group, string $id, string $label, string $state, string $detail, string $fix = ''): void
    {
        $this->rows[] = ['group' => $group, 'id' => $id, 'label' => $label, 'state' => $state, 'detail' => $detail, 'fix' => $fix];
    }

    // ------------------------------------------------------------------ server

    private function server(): void
    {
        $g = 'Server';
        $this->add($g, 'php', 'PHP version', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'fail', 'PHP ' . PHP_VERSION,
            'The attendance module needs PHP 8.2 or newer. Cloudways: Server › Settings & Packages › PHP version.');
        foreach ([
            ['pdo_mysql', 'fail', 'The database connection.'],
            ['mbstring', 'fail', 'Names in Urdu and other scripts.'],
            ['json', 'fail', 'Every API answer.'],
            ['curl', 'fail', 'Talking to the photo storage.'],
            ['fileinfo', 'warn', 'Checking what an uploaded photo really is.'],
            ['gd', 'warn', 'Cropping and shrinking photos.'],
            ['exif', 'warn', 'Turning phone photos the right way up.'],
            ['openssl', 'warn', 'Encrypting attendance backups.'],
        ] as [$ext, $bad, $why]) {
            $have = extension_loaded($ext);
            $this->add($g, 'ext_' . $ext, 'PHP extension ' . $ext, $have ? 'ok' : $bad, $have ? 'Loaded. ' . $why : 'Missing. Needed for: ' . $why,
                'Ask Cloudways support to enable the PHP ' . $ext . ' extension.');
        }
        $webp = function_exists('imagewebp');
        $this->add($g, 'webp', 'Photos stored as WebP', $webp ? 'ok' : 'warn', $webp ? 'This server can write WebP.' : 'No WebP support: photos are stored as JPEG instead (larger, still fine).');
        $up = self::bytes((string) ini_get('upload_max_filesize'));
        $post = self::bytes((string) ini_get('post_max_size'));
        $need = 10 * 1048576;
        $this->add($g, 'upload', 'Largest upload the server accepts', ($up >= $need && $post >= $need) ? 'ok' : 'warn',
            'upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size'),
            'Photos up to 10 MB are accepted; the screens shrink big ones first, so lower limits mostly still work. To raise: Cloudways › Application Settings › PHP-FPM settings.');
        $mem = self::bytes((string) ini_get('memory_limit'));
        $this->add($g, 'memory', 'PHP memory limit', ($mem < 0 || $mem >= 256 * 1048576) ? 'ok' : 'warn', 'memory_limit ' . ini_get('memory_limit'),
            'Large photos need room to be resized. 256M or more is recommended.');
    }

    // ---------------------------------------------------------------- database

    private function database(): void
    {
        $g = 'Database';
        $ver = (string) Db::value('SELECT VERSION()');
        $maria = stripos($ver, 'mariadb') !== false;
        preg_match('/^(\d+)\.(\d+)/', $ver, $m);
        $num = isset($m[1]) ? ((int) $m[1] + (int) $m[2] / 100) : 0;
        $okVer = $maria ? $num >= 10.05 : $num >= 8.0;
        $this->add($g, 'version', 'Database server', $okVer ? 'ok' : 'warn', ($maria ? 'MariaDB ' : 'MySQL ') . $ver,
            'Built and tested on MySQL 8. MariaDB 10.5 or newer also runs it.');
        $tz = (string) Db::value('SELECT @@session.time_zone');
        $this->add($g, 'utc', 'Times stored in UTC', $tz === '+00:00' ? 'ok' : 'fail', 'This connection uses ' . $tz . '.',
            'Every attendance page pins the connection to UTC. If this fails, includes/att/Db.php is out of date — upload it again.');
        $drift = abs(time() - (int) Db::value('SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP())'));
        $this->add($g, 'clock', 'Web server and database agree on the time', $drift <= 5 ? 'ok' : 'warn', 'Difference: ' . $drift . ' s.',
            'A difference makes "how long ago" figures wrong. Ask Cloudways support to check the server clock (NTP).');
        try {
            $mig = Migrations::default();
            $st = $mig->status();
            $pending = array_values(array_filter($st, fn($r) => $r['state'] !== 'applied'));
            $this->add($g, 'migrations', 'Database steps (att_migrate.php)', $pending === [] ? 'ok' : 'fail',
                count($st) - count($pending) . ' of ' . count($st) . ' applied' . ($pending ? '; waiting: ' . implode(', ', array_map(fn($r) => sprintf('%04d', $r['version']), $pending)) : '.'),
                'Run: php att_migrate.php up');
            $changed = [];
            foreach ($mig->applied() as $v => $row) {
                foreach ($mig->available() as $a) {
                    if ((int) $a['version'] === (int) $v && !hash_equals((string) $row['checksum'], hash('sha256', (string) file_get_contents($a['path'])))) {
                        $changed[] = sprintf('%04d', $v);
                    }
                }
            }
            $this->add($g, 'migration_files', 'Database step files unchanged since they ran', $changed === [] ? 'ok' : 'fail',
                $changed === [] ? 'Every applied step matches its file.' : 'Edited after running: ' . implode(', ', $changed) . '.',
                'Upload the original files again. A change to the database is always a NEW step, never an edit.');
        } catch (\Throwable $e) {
            $this->add($g, 'migrations', 'Database steps (att_migrate.php)', 'fail', 'Could not read them: ' . $e->getMessage(), 'Run: php att_migrate.php status');
        }
        $engines = Db::all("SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.tables WHERE table_schema = DATABASE() AND TABLE_NAME LIKE 'att\\_%' AND (ENGINE IS NULL OR ENGINE <> 'InnoDB') AND TABLE_TYPE = 'BASE TABLE'");
        $this->add($g, 'innodb', 'Attendance tables use InnoDB (transactions, row locks)', $engines === [] ? 'ok' : 'fail',
            $engines === [] ? 'All of them.' : 'Not InnoDB: ' . implode(', ', array_column($engines, 't')), 'Ask Cloudways support; the first-decision-wins rule needs InnoDB.');
    }

    // ------------------------------------------------------------------- files

    private function files(): void
    {
        $g = 'Files and storage';
        $root = dirname(__DIR__, 2);
        $missing = [];
        foreach (['att/index.html', 'att/app.js', 'att/db.js', 'att/sw.js', 'att/app.css', 'att/manifest.webmanifest', 'api/v1/index.php',
                  'att_setup.php', 'att_overtime.php', 'att_reports.php', 'att_hr_reconciliation.php', 'att_migrate.php', 'att_cron.php', 'att_backup.php', 'att_check.php'] as $f) {
            if (!is_file($root . '/' . $f)) { $missing[] = $f; }
        }
        $this->add($g, 'files', 'Attendance files present', $missing === [] ? 'ok' : 'fail', $missing === [] ? 'All of them.' : 'Missing: ' . implode(', ', $missing),
            'Upload the attendance zip again into public_html.');
        $idx = (string) @file_get_contents($root . '/att/index.html');
        $sw  = (string) @file_get_contents($root . '/att/sw.js');
        preg_match('/app\.js\?v=(\d+)/', $idx, $a);
        preg_match("/'app\.js\?v=(\d+)'/", $sw, $b);
        $same = isset($a[1], $b[1]) && $a[1] === $b[1];
        $this->add($g, 'phone_app', 'Phone app files belong together', $same ? 'ok' : 'warn',
            $same ? 'Version ' . $a[1] . ' in both index.html and sw.js.' : 'index.html and sw.js name different versions, so phones may keep an old copy.',
            'Upload all four files of the att folder together: app.js, app.css, index.html, sw.js.');
        $r2 = Storage::configured();
        $this->add($g, 'r2', 'Photo storage (Cloudflare R2) connected', $r2 ? 'ok' : 'warn',
            $r2 ? 'The four r2_ settings are present in config.php. Use Setup › Photos › Test the connection to prove it works.' : 'Not connected: photos cannot be saved or shown. Attendance itself works.',
            'Add r2_endpoint, r2_bucket, r2_access_key and r2_secret to config.php on the server.');
    }

    // ---------------------------------------------------------------- security

    private function security(bool $web): void
    {
        $g = 'Security';
        global $config;
        $cfg = is_array($config ?? null) ? $config : [];
        $looksTest = ($cfg['db_name'] ?? '') === 'zas_att_test' || ($cfg['app_name'] ?? '') === 'ZAS (test)' || ($cfg['r2_access_key'] ?? '') === 'TESTKEY';
        $this->add($g, 'config', 'config.php is the real one', $looksTest ? 'fail' : 'ok',
            $looksTest ? 'config.php looks like the TEST configuration.' : 'It is not the test configuration.',
            'Put the real config.php back from your Cloudways backup. Never upload a config folder.');
        if ($web) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            $this->add($g, 'https', 'This page was opened over HTTPS', $https ? 'ok' : 'fail', $https ? 'Yes.' : 'No — passwords and photos would travel unprotected.',
                'Cloudways › Application › SSL Certificate: install one and turn on HTTPS redirection.');
        }
        $flags = [
            'session.cookie_httponly' => ['1', 'scripts on a page cannot read the sign-in cookie'],
            'session.cookie_secure'   => ['1', 'the sign-in cookie is only sent over HTTPS'],
        ];
        foreach ($flags as $k => [$want, $why]) {
            $v = (string) ini_get($k);
            $on = in_array(strtolower($v), ['1', 'on', 'true'], true);
            $this->add($g, str_replace('.', '_', $k), 'Sign-in cookie: ' . $k, $on ? 'ok' : 'warn', ($on ? 'On' : 'Off') . ' — when on, ' . $why . '.',
                'This is a setting for the whole ERP. Cloudways › Application Settings › PHP-FPM settings, add: php_admin_flag[' . $k . '] = on');
        }
        $ss = (string) ini_get('session.cookie_samesite');
        $this->add($g, 'samesite', 'Sign-in cookie: SameSite', in_array($ss, ['Lax', 'Strict'], true) ? 'ok' : 'warn', $ss === '' ? 'Not set.' : $ss,
            'Recommended Lax. Cloudways › Application Settings › PHP-FPM settings: php_admin_value[session.cookie_samesite] = Lax');
        $de = in_array(strtolower((string) ini_get('display_errors')), ['1', 'on', 'true', 'stdout'], true);
        $this->add($g, 'display_errors', 'Errors are not shown on screen', $de ? 'warn' : 'ok', $de ? 'display_errors is on: an error could show file paths to a visitor.' : 'display_errors is off.',
            'Cloudways › Application Settings › PHP-FPM settings: php_admin_flag[display_errors] = off');
        $root = dirname(__DIR__, 2);
        $leftovers = array_values(array_filter(['tests', 'config/config.php.test', '.git'], fn($p) => file_exists($root . '/' . $p)));
        $zips = glob($root . '/*.zip') ?: [];
        $this->add($g, 'leftovers', 'No test files, git folder or zip files on the web', ($leftovers === [] && $zips === []) ? 'ok' : 'warn',
            ($leftovers === [] && $zips === []) ? 'None found.' : 'Found: ' . implode(', ', array_merge($leftovers, array_map('basename', $zips))),
            'Delete them from public_html with WinSCP. A zip of the site would let anyone download its code.');
    }

    // ------------------------------------------------------------------- setup

    private function setup(): void
    {
        $g = 'Attendance setup';
        $o = AdminService::overview();
        $this->add($g, 'default_shift', 'Default shift chosen', $o['default_shift'] ? 'ok' : 'fail',
            $o['default_shift'] ? '"' . $o['default_shift'] . '"' : 'Not chosen: every gate scan of someone without a shift for the day goes to HR, and does not reach the HOD.',
            'Setup › Settings › Default shift.');
        $this->add($g, 'auto_shift', 'Employees without a shift are let in on the default shift', Policy::bool('auto_shift_on_gate_in', true) ? 'ok' : 'warn',
            Policy::bool('auto_shift_on_gate_in', true) ? 'On.' : 'Off: their scans go to HR.', 'Setup › Settings.');
        $this->add($g, 'employees', 'Employees on the roll', $o['employees'] > 0 ? 'ok' : 'fail', (string) $o['employees'], 'Setup › Employees.');
        $this->add($g, 'gatekeeper', 'At least one working gatekeeper', $o['gatekeepers_ready'] > 0 ? 'ok' : 'fail', (string) $o['gatekeepers_ready'], 'Setup › People › give the Gatekeeper job.');
        $no = $o['departments_without_hod'];
        $this->add($g, 'hods', 'Every department with employees has a HOD', $no === [] ? 'ok' : 'warn', $no === [] ? 'Yes.' : 'No HOD for: ' . implode(', ', $no),
            'Setup › People › give the HOD job with that department.');
        $photos = (int) ($o['employees_with_photo'] ?? 0);
        $this->add($g, 'photos', 'Employees with a photo', $photos >= $o['employees'] && $o['employees'] > 0 ? 'ok' : 'warn', $photos . ' of ' . $o['employees'],
            'Not needed to start; without one the gate sees the name only. Setup › Employees › Photo…');
    }

    // ------------------------------------------------------------ housekeeping

    private function housekeeping(): void
    {
        $g = 'Day-to-day';
        $held = (int) Db::value("SELECT COUNT(*) FROM att_sync_receipts WHERE receipt_status = 'NEEDS_REVIEW' AND review_state IS NULL");
        $this->add($g, 'held', 'Gate scans waiting for HR', $held === 0 ? 'ok' : 'warn', (string) $held, 'Attendance › Reconciliation (HR) › Gate scans that could not be filed.');
        $open = (int) Db::value('SELECT COUNT(*) FROM att_entry_cycles WHERE closed_at IS NULL AND opened_at < ?',
            [Time::sql(Time::nowUtc()->modify('-' . max(1, Policy::int('stale_open_cycle_hours', 16)) . ' hours'))]);
        $this->add($g, 'open', 'Entries left open too long (missed OUT)', $open === 0 ? 'ok' : 'warn', (string) $open, 'Attendance › Reconciliation (HR) › Entries left open.');
        if (Storage::configured() && Db::tableExists('att_flags') && (int) Db::value('SELECT COUNT(*) FROM att_flags WHERE photo_key IS NOT NULL') > 0) {
            $last = (string) (Db::value("SELECT value FROM att_policies WHERE policy_key = 'photo_purge_last_run'") ?? '');
            $fresh = $last !== '' && strtotime($last . ' UTC') > time() - 3 * 86400;
            $this->add($g, 'purge', 'Old flag photos are being cleared', $fresh ? 'ok' : 'warn', $last === '' ? 'Never run yet.' : 'Last run ' . $last . ' UTC.',
                'Opening the HR Reconciliation screen runs it once a day; a daily cron job running php att_cron.php makes it automatic.');
        }
        $root = dirname(__DIR__, 2);
        $dir = AttBackup::defaultDir($root);
        $latest = AttBackup::latest($dir);
        $this->add($g, 'backup', 'Attendance backup in the last 2 days', ($latest !== null && $latest['age_hours'] <= 48) ? 'ok' : 'warn',
            $latest === null ? 'None found in ' . basename(dirname($dir)) . '/' . basename($dir) . '. (Cloudways backs up the whole database separately.)' : basename($latest['file']) . ', ' . $latest['age_hours'] . ' h old.',
            'php att_backup.php create — or a daily cron job. Check it with php att_backup.php verify <file>.');
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }
}
