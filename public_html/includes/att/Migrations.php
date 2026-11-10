<?php
declare(strict_types=1);

namespace Att;

use PDO;
use RuntimeException;

/**
 * Forward-only, numbered migrations with a recorded rollback for each.
 *
 * WHY NOT THE EXISTING HABIT. This repository migrates by putting
 * `CREATE TABLE IF NOT EXISTS ...` and `ALTER TABLE ...` inside try/catch on
 * every page load. That works, and for the existing tables it stays exactly as
 * it is — nothing here touches it. It cannot, however, answer three questions
 * the attendance work has to answer: in what order did this run, has it run at
 * all, and how do I undo it. The blueprint asks for a rollback plan per
 * milestone, so those questions have to be answerable.
 *
 * Rules of the road:
 *  - Files live in public_html/sql/att, named NNNN_description.sql|php.
 *  - Each has a partner in sql/att/rollback/NNNN_down.sql|php.
 *  - A .php migration returns a callable(PDO): used where DDL has to be
 *    conditional, which plain SQL cannot express (migration 0005 has to cope
 *    with `users` existing in production and not existing in a test schema).
 *  - Applied files are recorded with a checksum. Editing a migration that has
 *    already run is refused, because the database and the file would then
 *    disagree and nobody would know.
 *  - It runs from the CLI only. A migration that fires on a page load is a
 *    migration that fires fifty times at once under traffic.
 */
final class Migrations
{
    public function __construct(
        private string $dir,
        private ?string $rollbackDir = null
    ) {
        $this->rollbackDir ??= rtrim($dir, '/') . '/rollback';
    }

    public static function default(): self
    {
        return new self(dirname(__DIR__, 2) . '/sql/att');
    }

    public function ensureTable(): void
    {
        Db::conn()->exec(
            'CREATE TABLE IF NOT EXISTS att_migrations (
                version      INT UNSIGNED NOT NULL PRIMARY KEY,
                filename     VARCHAR(190) NOT NULL,
                checksum     CHAR(64)     NOT NULL,
                applied_at   DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<int,array{version:int,filename:string,path:string}> */
    public function available(): array
    {
        $out = [];
        foreach (glob(rtrim($this->dir, '/') . '/*.{sql,php}', GLOB_BRACE) ?: [] as $path) {
            $base = basename($path);
            if (!preg_match('/^(\d{4})_/', $base, $m)) {
                continue;
            }
            $out[(int) $m[1]] = ['version' => (int) $m[1], 'filename' => $base, 'path' => $path];
        }
        ksort($out);
        return $out;
    }

    /** @return array<int,array{version:int,filename:string,checksum:string,applied_at:string}> */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = Db::all('SELECT version, filename, checksum, applied_at FROM att_migrations ORDER BY version');
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['version']] = $r;
        }
        return $out;
    }

    public function pending(): array
    {
        $applied = $this->applied();
        return array_values(array_filter(
            $this->available(),
            static fn(array $m) => !isset($applied[$m['version']])
        ));
    }

    /**
     * Apply every pending migration in order.
     *
     * @return list<string> human-readable log lines
     */
    public function up(?int $to = null): array
    {
        $this->ensureTable();
        $applied = $this->applied();
        $log = [];

        foreach ($this->available() as $m) {
            if (isset($applied[$m['version']])) {
                $this->assertUnchanged($m, $applied[$m['version']]);
                continue;
            }
            if ($to !== null && $m['version'] > $to) {
                break;
            }

            $this->runFile($m['path']);
            Db::run(
                'INSERT INTO att_migrations (version, filename, checksum) VALUES (?, ?, ?)',
                [$m['version'], $m['filename'], $this->checksum($m['path'])]
            );
            $log[] = sprintf('applied  %04d  %s', $m['version'], $m['filename']);
        }

        if ($log === []) {
            $log[] = 'nothing to apply — database is up to date';
        }
        return $log;
    }

    /**
     * Roll back every applied migration above $downTo, newest first.
     * down(0) undoes everything the module installed.
     *
     * @return list<string>
     */
    public function down(int $downTo): array
    {
        $this->ensureTable();
        $log = [];
        $applied = $this->applied();
        krsort($applied);

        foreach ($applied as $version => $row) {
            if ($version <= $downTo) {
                continue;
            }
            $path = $this->rollbackPath($version);
            if ($path === null) {
                throw new RuntimeException(
                    sprintf('No rollback file for migration %04d (%s).', $version, $row['filename'])
                );
            }
            $this->runFile($path);
            Db::run('DELETE FROM att_migrations WHERE version = ?', [$version]);
            $log[] = sprintf('rolled back  %04d  %s', $version, basename($path));
        }

        if ($log === []) {
            $log[] = 'nothing to roll back';
        }
        return $log;
    }

    public function status(): array
    {
        $applied = $this->applied();
        $rows = [];
        foreach ($this->available() as $m) {
            $rows[] = [
                'version'  => $m['version'],
                'file'     => $m['filename'],
                'state'    => isset($applied[$m['version']]) ? 'applied' : 'pending',
                'applied_at' => $applied[$m['version']]['applied_at'] ?? null,
            ];
        }
        return $rows;
    }

    // ---------------------------------------------------------------- internals

    private function assertUnchanged(array $m, array $appliedRow): void
    {
        $now = $this->checksum($m['path']);
        if (!hash_equals((string) $appliedRow['checksum'], $now)) {
            throw new RuntimeException(sprintf(
                'Migration %04d (%s) has been edited after it was applied. '
                . 'The database no longer matches the file. Write a new migration instead.',
                $m['version'],
                $m['filename']
            ));
        }
    }

    private function checksum(string $path): string
    {
        return hash('sha256', (string) file_get_contents($path));
    }

    private function rollbackPath(int $version): ?string
    {
        foreach (['sql', 'php'] as $ext) {
            $p = sprintf('%s/%04d_down.%s', rtrim((string) $this->rollbackDir, '/'), $version, $ext);
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    private function runFile(string $path): void
    {
        if (str_ends_with($path, '.php')) {
            $fn = require $path;
            if (!is_callable($fn)) {
                throw new RuntimeException("Migration $path must return a callable(PDO).");
            }
            $fn(Db::conn());
            return;
        }

        $sql = (string) file_get_contents($path);
        foreach (self::splitStatements($sql) as $stmt) {
            Db::conn()->exec($stmt);
        }
    }

    /**
     * Split a .sql file into statements.
     *
     * Semicolons inside quoted strings and inside comments are not statement
     * ends. A naive explode(';') corrupts any default value or comment that
     * happens to contain one, and the failure shows up as a syntax error in
     * the middle of a migration — half applied, which is the worst outcome.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $inSingle = $inDouble = $inBacktick = false;
        $inLine = $inBlock = false;

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLine) {
                $buf .= $c;
                if ($c === "\n") { $inLine = false; }
                continue;
            }
            if ($inBlock) {
                $buf .= $c;
                if ($c === '*' && $next === '/') { $buf .= $next; $i++; $inBlock = false; }
                continue;
            }
            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($c === '-' && $next === '-') { $inLine = true; $buf .= $c; continue; }
                if ($c === '#') { $inLine = true; $buf .= $c; continue; }
                if ($c === '/' && $next === '*') { $inBlock = true; $buf .= $c; continue; }
            }

            if ($c === "'" && !$inDouble && !$inBacktick) {
                // A doubled '' or a backslash-escape keeps us inside the string.
                $escaped = ($i > 0 && $sql[$i - 1] === '\\');
                if (!$escaped) { $inSingle = !$inSingle; }
            } elseif ($c === '"' && !$inSingle && !$inBacktick) {
                $escaped = ($i > 0 && $sql[$i - 1] === '\\');
                if (!$escaped) { $inDouble = !$inDouble; }
            } elseif ($c === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            }

            if ($c === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $t = trim($buf);
                if ($t !== '') { $out[] = $t; }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }

        $t = trim($buf);
        if ($t !== '' && !preg_match('/^(--|#|\/\*)/', $t)) {
            $out[] = $t;
        }
        // Drop anything that is only a comment.
        return array_values(array_filter($out, static function (string $s): bool {
            $stripped = trim(preg_replace('!/\*.*?\*/!s', '', preg_replace('/^\s*(--|#).*$/m', '', $s)) ?? '');
            return $stripped !== '';
        }));
    }
}
