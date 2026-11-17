<?php
/* RUN THE TABLE CHECKS ONCE, NOT ON EVERY PAGE.

   Each module checks its own tables when it starts ("create the table if it
   is not there, add this column if it is missing"). That is how new
   columns reach the live database without anyone running a script. But it
   ran on EVERY page load: about 100 statements to open an inventory page,
   and — worse — a few "ALTER TABLE … MODIFY" lines that briefly lock their
   table, so one slow report could hold up every other user behind it.

   Now each check remembers it has been done, in the small table
   zas_schema_marks, and runs again only when:
     · the file that holds the check has changed (a new version uploaded), or
     · the number of tables in the database has changed (another module
       made a new table, which a check may want to add a column to).
   So it stays automatic — upload a new file and its check runs once by
   itself — and costs one quick read per page the rest of the time.

   To make every check run again on the next page: empty zas_schema_marks.
   If anything here fails, the check simply runs, as it always did. */

/** The fingerprint a check is remembered by: its files' contents and the table count. */
function zas_schema_fp(array $files): string
{
    static $cache = [];
    $parts = [];
    foreach ($files as $f) {
        if (!isset($cache[$f])) {
            $h = @hash_file('xxh3', $f);
            $cache[$f] = $h !== false ? $h : (string)@filemtime($f) . '-' . (string)@filesize($f);
        }
        $parts[] = $cache[$f];
    }
    return substr(implode('.', $parts), 0, 100) . ':' . zas_schema_state()['tables'];
}

/** What is remembered, read once per page: every mark, and how many tables there are. */
function zas_schema_state(): array
{
    static $state = null;
    if ($state !== null) return $state;
    $state = ['marks' => [], 'tables' => '?'];
    try {
        $state['tables'] = (string)db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME <> 'zas_schema_marks'")->fetchColumn();
        $state['marks'] = db()->query("SELECT name, fp FROM zas_schema_marks")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {
        /* the marks table is not there yet: every check runs, then makes it */
    }
    return $state;
}

/** True when this check has already run for this version of its files. */
function zas_schema_fresh(string $name, array $files): bool
{
    $s = zas_schema_state();
    return isset($s['marks'][$name]) && hash_equals((string)$s['marks'][$name], zas_schema_fp($files));
}

/** Remember that this check has run. */
function zas_schema_mark(string $name, array $files): void
{
    $fp = zas_schema_fp($files);
    $save = function () use ($name, $fp): void {
        db()->prepare("INSERT INTO zas_schema_marks (name, fp, done_at) VALUES (?, ?, NOW())
                       ON DUPLICATE KEY UPDATE fp = VALUES(fp), done_at = VALUES(done_at)")->execute([$name, $fp]);
    };
    try {
        $save();
    } catch (Throwable $e) {
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS zas_schema_marks (
                name VARCHAR(64) NOT NULL PRIMARY KEY,
                fp VARCHAR(120) NOT NULL,
                done_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $save();
        } catch (Throwable $e2) {
            /* not remembered: it simply runs again next time, as before */
        }
    }
}

/* CHANGE A COLUMN ONLY WHEN IT IS DIFFERENT.
   "ALTER TABLE … MODIFY" locks its table even when nothing changes, and
   waits for anyone reading it — while everyone after waits behind it.
   Reading the column first takes no such lock. */
function zas_modify_if_needed(string $table, string $column, string $type, bool $nullable, string $ddl): void
{
    try {
        $c = db()->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE " . db()->quote($column))->fetch();
        if (!$c) return;                               // no such column: nothing to change
        if (strtolower((string)$c['Type']) === strtolower($type) && (((string)$c['Null'] === 'YES') === $nullable)) return;
        db()->exec($ddl);
    } catch (Throwable $e) {}
}
