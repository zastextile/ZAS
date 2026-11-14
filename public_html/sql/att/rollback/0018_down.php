<?php
declare(strict_types=1);
/**
 * Reverses 0018 by narrowing the two lists again — but only when no entry
 * sheet exists. If one does, the lists are left wide: a wider list is
 * harmless to the earlier code, a lost entry sheet is not.
 */
use Att\Db;

return static function (PDO $pdo): void {
    if (!Db::tableExists('att_fin_sheets')) {
        return;
    }
    $n = (int) $pdo->query("SELECT COUNT(*) FROM att_fin_sheets WHERE kind = 'entry' OR status = 'posted'")->fetchColumn();
    if ($n > 0) {
        return;
    }
    $pdo->exec("ALTER TABLE att_fin_sheets
        MODIFY COLUMN kind ENUM('salary','daily','contract') NOT NULL,
        MODIFY COLUMN status ENUM('waiting','approved','paid','returned','cancelled') NOT NULL DEFAULT 'waiting'");
};
