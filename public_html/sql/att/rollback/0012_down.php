<?php
declare(strict_types=1);
/**
 * Reverses 0012 by narrowing the two columns again — but only when nothing
 * stored would be cut by doing so. If a code longer than the old width exists
 * the columns are left wide: a wider column is harmless to the earlier
 * schema, a truncated employee code is not.
 */
use Att\Db;

return static function (PDO $pdo): void {
    if (!Db::tableExists('att_employees')) {
        return;
    }
    $long = (int) $pdo->query(
        'SELECT COUNT(*) FROM att_employees WHERE CHAR_LENGTH(code) > 32 OR CHAR_LENGTH(COALESCE(worker_code, \'\')) > 20'
    )->fetchColumn();
    if ($long > 0) {
        return;
    }
    $pdo->exec('ALTER TABLE att_employees MODIFY code VARCHAR(32) NOT NULL, MODIFY worker_code VARCHAR(20) NULL');
};
