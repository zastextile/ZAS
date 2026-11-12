<?php
declare(strict_types=1);

namespace Att;

use PDO;
use RuntimeException;

/**
 * A backup of the attendance tables only, that can be PROVED to restore.
 *
 * Cloudways already backs up the whole database, and that remains the main
 * backup. This exists for the two things a whole-server backup does not
 * give you on its own:
 *
 *   1. A restore test you can run any day without touching live data.
 *      `verify` loads the file into a parallel set of tables (attbk_…),
 *      compares every table's row count and a fingerprint of every row with
 *      what was recorded when the backup was taken, and drops them again.
 *      Blueprint §6: "restoration tested".
 *   2. Encryption at rest, if config.php has an `att_backup_key`. Without
 *      one the file is gzip only, and the tool says so every time.
 *
 * ONE CONSISTENT MOMENT. The tables are read inside one REPEATABLE READ
 * snapshot, so a backup taken while the gate is scanning is still a single
 * point in time: no entry without its gate event, no confirmation without
 * its entry.
 *
 * Generated columns (open_employee_id, live_employee_id, crosses_midnight…)
 * are not written: the database works them out again on restore, which is
 * also the proof they are still consistent.
 */
final class AttBackup
{
    private const MAGIC = "ZASATTENC1";

    public static function defaultDir(string $publicRoot): string
    {
        // Cloudways gives every application a private_html beside public_html:
        // outside the web root, so a backup can never be downloaded by URL.
        return dirname($publicRoot) . '/private_html/att-backups';
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return array_values(array_map('strval', array_column(Db::all(
            "SELECT TABLE_NAME AS t FROM information_schema.tables
              WHERE table_schema = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE 'att\\_%'
              ORDER BY TABLE_NAME"), 't')));
    }

    /**
     * @return array{ok:bool,file?:string,bytes?:int,tables?:int,rows?:int,encrypted?:bool,error?:string}
     */
    public static function create(string $dir, ?string $key, int $keep = 14): array
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return ['ok' => false, 'error' => "Cannot create the backup folder $dir. Give a folder with --dir=."];
        }
        if (!is_writable($dir)) {
            return ['ok' => false, 'error' => "The backup folder $dir is not writable. Give another one with --dir=."];
        }
        @file_put_contents($dir . '/.htaccess', "Deny from all\n");
        $pdo = Db::conn();
        $name = 'att-' . gmdate('Ymd-His') . '.sql.gz';
        $tmp = $dir . '/.' . $name . '.part';
        $gz = gzopen($tmp, 'wb6');
        if ($gz === false) {
            return ['ok' => false, 'error' => 'Cannot write the backup file.'];
        }
        $manifest = ['format' => 1, 'created_at' => gmdate('c'), 'database' => (string) Db::value('SELECT VERSION()'), 'tables' => []];
        $rowsTotal = 0;
        try {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            gzwrite($gz, "-- ZAS attendance backup. Restore with: php att_backup.php restore <file>\n"
                . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET time_zone='+00:00';\n");
            foreach (self::tables() as $t) {
                $create = (string) (Db::one('SHOW CREATE TABLE `' . $t . '`')['Create Table'] ?? '');
                gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n" . str_replace("\n", ' ', $create) . ";\n");
                [$n, $hash] = self::dumpRows($pdo, $t, $gz);
                $manifest['tables'][$t] = ['rows' => $n, 'sha256' => $hash];
                $rowsTotal += $n;
            }
            $pdo->exec('COMMIT');
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n-- manifest: " . json_encode($manifest) . "\n");
            gzclose($gz);
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable) {}
            @gzclose($gz);
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Backup failed: ' . $e->getMessage()];
        }

        $final = $dir . '/' . $name;
        $encrypted = false;
        if ($key !== null && $key !== '') {
            $plain = (string) file_get_contents($tmp);
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher === false) {
                @unlink($tmp);
                return ['ok' => false, 'error' => 'Encryption failed.'];
            }
            $final .= '.enc';
            file_put_contents($tmp, self::MAGIC . $iv . $tag . $cipher);
            $encrypted = true;
        }
        rename($tmp, $final);
        @chmod($final, 0600);
        self::prune($dir, $keep);
        self::note('att_backup_last', ['file' => basename($final), 'at' => gmdate('Y-m-d H:i:s'), 'bytes' => (int) filesize($final),
                                       'tables' => count($manifest['tables']), 'rows' => $rowsTotal, 'encrypted' => $encrypted]);
        return ['ok' => true, 'file' => $final, 'bytes' => (int) filesize($final), 'tables' => count($manifest['tables']), 'rows' => $rowsTotal, 'encrypted' => $encrypted];
    }

    /** @return array{0:int,1:string} rows written, fingerprint */
    private static function dumpRows(PDO $pdo, string $t, $gz): array
    {
        $cols = self::plainColumns($t);
        $order = self::primaryKey($t) ?: $cols;
        $sql = 'SELECT ' . implode(',', array_map(fn($c) => "`$c`", $cols)) . " FROM `$t` ORDER BY " . implode(',', array_map(fn($c) => "`$c`", $order));
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $h = hash_init('sha256');
        $n = 0;
        $batch = [];
        $head = "INSERT INTO `$t` (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ') VALUES ';
        try {
            $st = $pdo->query($sql);
            while (($row = $st->fetch(PDO::FETCH_NUM)) !== false) {
                hash_update($h, self::rowPrint($row));
                $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                $n++;
                if (count($batch) >= 200) {
                    gzwrite($gz, $head . implode(',', $batch) . ";\n");
                    $batch = [];
                }
            }
            $st->closeCursor();
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        if ($batch !== []) {
            gzwrite($gz, $head . implode(',', $batch) . ";\n");
        }
        return [$n, hash_final($h)];
    }

    /**
     * Load a backup into attbk_… tables, compare every table with what was
     * recorded when it was taken, then drop them. Live tables are not touched.
     *
     * @return array{ok:bool,tables?:array<string,array{rows:int,match:bool}>,error?:string}
     */
    public static function verify(string $file, ?string $key): array
    {
        $r = self::read($file, $key);
        if (!$r['ok']) {
            return $r;
        }
        $sql = $r['sql'];
        if (!preg_match('/^-- manifest: (\{.*\})\s*$/m', $sql, $m) || !is_array($manifest = json_decode($m[1], true))) {
            return ['ok' => false, 'error' => 'The file has no manifest at the end: it is incomplete or not an attendance backup.'];
        }
        $pdo = Db::conn();
        $out = [];
        $all = true;
        self::dropScratch();
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach (self::statements($sql) as $s) {
                if (str_starts_with($s, '--') || stripos($s, 'SET FOREIGN_KEY_CHECKS=1') === 0) {
                    continue;
                }
                // Into parallel tables, never the live ones. Foreign key names
                // are per database, so they are renamed as well.
                $s = str_replace(['`att_', 'CONSTRAINT `fk_'], ['`attbk_', 'CONSTRAINT `fkbk_'], $s);
                if (preg_match('/^(DROP|CREATE)\s+TABLE\s+(IF EXISTS\s+)?`(?!attbk_)/i', $s) || preg_match('/^INSERT\s+INTO\s+`(?!attbk_)/i', $s)) {
                    throw new RuntimeException('Refusing a statement that does not target a scratch table.');
                }
                $pdo->exec($s);
            }
            foreach ($manifest['tables'] as $t => $want) {
                $bk = 'attbk_' . substr($t, 4);
                $cols = self::plainColumns($bk);
                $order = self::primaryKey($bk) ?: $cols;
                $h = hash_init('sha256');
                $n = 0;
                $st = $pdo->query('SELECT ' . implode(',', array_map(fn($c) => "`$c`", $cols)) . " FROM `$bk` ORDER BY " . implode(',', array_map(fn($c) => "`$c`", $order)));
                while (($row = $st->fetch(PDO::FETCH_NUM)) !== false) {
                    hash_update($h, self::rowPrint($row));
                    $n++;
                }
                $match = $n === (int) $want['rows'] && hash_equals((string) $want['sha256'], hash_final($h));
                $all = $all && $match;
                $out[$t] = ['rows' => $n, 'expected_rows' => (int) $want['rows'], 'match' => $match];
            }
        } catch (\Throwable $e) {
            self::dropScratch();
            return ['ok' => false, 'error' => 'The backup did not load: ' . $e->getMessage()];
        } finally {
            try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (\Throwable) {}
        }
        self::dropScratch();
        self::note('att_backup_last_verified', ['file' => basename($file), 'at' => gmdate('Y-m-d H:i:s'), 'passed' => $all]);
        return ['ok' => $all, 'tables' => $out, 'created_at' => $manifest['created_at'] ?? null,
                'error' => $all ? null : 'Some tables do not match what was backed up.'];
    }

    /**
     * Put the attendance tables back as they were in $file. A fresh backup
     * of the current state is taken first, so a restore can be undone.
     */
    public static function restore(string $file, ?string $key, string $dir): array
    {
        $check = self::verify($file, $key);
        if (!$check['ok']) {
            return ['ok' => false, 'error' => 'Not restored: ' . ($check['error'] ?? 'the file failed its check.')];
        }
        $safety = self::create($dir, $key, 1000);
        if (!$safety['ok']) {
            return ['ok' => false, 'error' => 'Not restored: could not first back up the current data. ' . ($safety['error'] ?? '')];
        }
        $r = self::read($file, $key);
        $pdo = Db::conn();
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach (self::statements($r['sql']) as $s) {
                if (str_starts_with($s, '--')) {
                    continue;
                }
                $pdo->exec($s);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'The restore stopped part way: ' . $e->getMessage() . ' Put the current data back with: php att_backup.php restore ' . $safety['file'], 'safety_backup' => $safety['file']];
        } finally {
            try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (\Throwable) {}
        }
        return ['ok' => true, 'restored_from' => $file, 'safety_backup' => $safety['file']];
    }

    /** @return array{ok:true,sql:string}|array{ok:false,error:string} */
    public static function read(string $file, ?string $key): array
    {
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return ['ok' => false, 'error' => 'Cannot read ' . $file];
        }
        if (str_starts_with($raw, self::MAGIC)) {
            if ($key === null || $key === '') {
                return ['ok' => false, 'error' => 'This backup is encrypted and config.php has no att_backup_key.'];
            }
            $o = strlen(self::MAGIC);
            $plain = openssl_decrypt(substr($raw, $o + 28), 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, substr($raw, $o, 12), substr($raw, $o + 12, 16));
            if ($plain === false) {
                return ['ok' => false, 'error' => 'This backup cannot be decrypted with the att_backup_key in config.php (wrong key, or the file is damaged).'];
            }
            $raw = $plain;
        }
        $sql = @gzdecode($raw);
        if ($sql === false) {
            return ['ok' => false, 'error' => 'The file is not a readable backup (damaged or cut short).'];
        }
        return ['ok' => true, 'sql' => $sql];
    }

    /* A short note in the database of the last backup and the last restore
       test: name, time, size — never the contents. The backup folder is
       private to the SSH user, so the web page (another user) cannot look
       inside it; it reads this note instead. */
    private static function note(string $key, array $data): void
    {
        try {
            Db::run(
                "INSERT INTO att_policies (policy_key, value, value_type, description) VALUES (?, ?, 'string', '')
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [$key, json_encode($data)]
            );
        } catch (\Throwable) {
            // the backup itself is what matters; the note is a convenience
        }
    }

    /** @return array<string,mixed>|null the note left by the last create / verify */
    public static function lastNote(string $key = 'att_backup_last'): ?array
    {
        $v = Db::value('SELECT value FROM att_policies WHERE policy_key = ?', [$key]);
        $d = is_string($v) ? json_decode($v, true) : null;
        return is_array($d) && isset($d['at']) ? $d : null;
    }

    /** @return array{file:string,age_hours:int}|null */
    public static function latest(string $dir): ?array
    {
        $files = glob(rtrim($dir, '/') . '/att-*.sql.gz*') ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return ['file' => $files[0], 'age_hours' => (int) floor((time() - (int) filemtime($files[0])) / 3600)];
    }

    // ---------------------------------------------------------------- helpers

    /** Statements end with ";\n": quoted values never hold a raw newline (PDO::quote escapes it). @return list<string> */
    private static function statements(string $sql): array
    {
        return array_values(array_filter(array_map('trim', explode(";\n", $sql)), fn($s) => $s !== ''));
    }

    /** @return list<string> */
    private static function plainColumns(string $t): array
    {
        return array_values(array_map('strval', array_column(Db::all(
            "SELECT COLUMN_NAME AS c FROM information_schema.columns
              WHERE table_schema = DATABASE() AND TABLE_NAME = ?
                AND COALESCE(GENERATION_EXPRESSION, '') = '' AND EXTRA NOT LIKE '%GENERATED%' AND EXTRA NOT LIKE '%PERSISTENT%'
              ORDER BY ORDINAL_POSITION", [$t]), 'c')));
    }

    /** @return list<string> */
    private static function primaryKey(string $t): array
    {
        return array_values(array_map('strval', array_column(Db::all(
            "SELECT COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
              WHERE table_schema = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION", [$t]), 'c')));
    }

    /** @param list<mixed> $row */
    private static function rowPrint(array $row): string
    {
        return json_encode(array_map(fn($v) => $v === null ? null : (string) $v, $row), JSON_UNESCAPED_UNICODE) . "\n";
    }

    private static function dropScratch(): void
    {
        $pdo = Db::conn();
        $t = array_column(Db::all("SELECT TABLE_NAME AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND TABLE_NAME LIKE 'attbk\\_%'"), 't');
        if ($t === []) {
            return;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($t as $x) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $x . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private static function prune(string $dir, int $keep): void
    {
        $files = glob(rtrim($dir, '/') . '/att-*.sql.gz*') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, max(1, $keep)) as $f) {
            @unlink($f);
        }
    }
}
