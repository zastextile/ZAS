<?php
declare(strict_types=1);

/**
 * ZAS Attendance M1 — the only migration that touches a table the attendance
 * module does not own. Everything it does is additive and nullable.
 *
 * WHY THIS IS PHP AND NOT .sql. It has to behave differently depending on what
 * is already there: `users` and `zp_workers` exist in the production database
 * and do not exist in a fresh test schema, and plain DDL cannot ask. Running
 * `ALTER TABLE users ...` against a schema with no users table is a hard
 * failure half way through a migration run, which is the one state worse than
 * not running at all.
 *
 * WHAT IT ADDS, AND WHY EACH IS SAFE:
 *   users.att_enabled        NULL  — marks a login as an attendance user.
 *                                    NULL means "not an attendance user",
 *                                    which is every existing row, so no
 *                                    current behaviour changes.
 *   zp_workers.att_employee_id NULL — the bridge from a production worker to
 *                                    an attendance employee. NULL for every
 *                                    existing worker; the HR-token sync that
 *                                    maintains zp_workers never writes it and
 *                                    is unaffected.
 *
 * No column is dropped, no type is changed, no default alters an existing
 * read. If the module is removed, 0005_down.php takes both columns away and
 * the two tables are byte-for-byte what they were.
 */

use Att\Db;

return static function (PDO $pdo): void {
    $add = static function (string $table, string $column, string $ddl) use ($pdo): void {
        if (!Db::tableExists($table)) {
            // Not this database's concern — a test schema, or an install where
            // the ERP module that owns the table is not present.
            return;
        }
        if (Db::columnExists($table, $column)) {
            return;
        }
        $pdo->exec($ddl);
    };

    $add('users', 'att_enabled',
        'ALTER TABLE users ADD COLUMN att_enabled TINYINT(1) NULL DEFAULT NULL');

    $add('zp_workers', 'att_employee_id',
        'ALTER TABLE zp_workers ADD COLUMN att_employee_id BIGINT UNSIGNED NULL DEFAULT NULL');

    // Indexed because the reconciliation screens look a worker up by it.
    if (Db::tableExists('zp_workers') && Db::columnExists('zp_workers', 'att_employee_id')) {
        try {
            $pdo->exec('ALTER TABLE zp_workers ADD INDEX idx_zpw_att_emp (att_employee_id)');
        } catch (PDOException $e) {
            // Duplicate key name: the index is already there. Anything else is real.
            if (!str_contains($e->getMessage(), 'Duplicate key name')) {
                throw $e;
            }
        }
    }
};
