<?php
declare(strict_types=1);
/**
 * Reverses 0005 by removing the two added columns, leaving `users` and
 * `zp_workers` exactly as they were. Guarded both ways: a column that is not
 * there is not an error, and a table that is not there is not this
 * migration's business.
 */
use Att\Db;

return static function (PDO $pdo): void {
    if (Db::tableExists('zp_workers')) {
        try { $pdo->exec('ALTER TABLE zp_workers DROP INDEX idx_zpw_att_emp'); }
        catch (PDOException $e) { /* index already absent */ }
        if (Db::columnExists('zp_workers', 'att_employee_id')) {
            $pdo->exec('ALTER TABLE zp_workers DROP COLUMN att_employee_id');
        }
    }
    if (Db::tableExists('users') && Db::columnExists('users', 'att_enabled')) {
        $pdo->exec('ALTER TABLE users DROP COLUMN att_enabled');
    }
};
