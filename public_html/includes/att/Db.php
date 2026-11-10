<?php
declare(strict_types=1);

namespace Att;

use PDO;
use RuntimeException;
use Throwable;

/**
 * The attendance module's door to the database.
 *
 * WHY THIS EXISTS AT ALL, when the app already has db().
 *
 * db() reads a global $config and hands back one connection to the live
 * database. That is exactly right for the 242 pages that already use it, and
 * useless for a test: there is no way to point it somewhere safe. Every
 * attendance test in tests/ needs a real MySQL 8 — the one-open-cycle rule and
 * the overtime approval race are enforced by the engine, not by PHP, so
 * testing them against anything else tests nothing.
 *
 * So: bind() lets a test supply its own connection, and conn() falls back to
 * the app's db() everywhere else. Production behaviour is unchanged.
 */
final class Db
{
    private static ?PDO $pdo = null;

    /** Point the module at a specific connection. Tests use this. */
    public static function bind(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$pdo = $pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        if (function_exists('db')) {
            self::$pdo = \db();
            return self::$pdo;
        }
        throw new RuntimeException('Attendance module has no database connection.');
    }

    /** @param array<int|string,mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function lastId(): int
    {
        return (int) self::conn()->lastInsertId();
    }

    /**
     * Run $fn inside a transaction.
     *
     * Nested calls join the outer transaction rather than opening a second one
     * (MySQL has no nested transactions; a naive begin() inside a begin()
     * silently commits the outer one). Only the outermost call commits.
     */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::conn();
        if ($pdo->inTransaction()) {
            return $fn($pdo);
        }
        $pdo->beginTransaction();
        try {
            $out = $fn($pdo);
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** True when the error is a unique/duplicate-key violation. */
    public static function isDuplicate(Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        // 23000 is the SQLSTATE; 1062 is MySQL's duplicate-entry code.
        return ($e->getCode() === '23000') && str_contains($e->getMessage(), '1062');
    }

    public static function tableExists(string $table): bool
    {
        $n = self::value(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        return (int) $n > 0;
    }

    public static function columnExists(string $table, string $column): bool
    {
        $n = self::value(
            'SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
        return (int) $n > 0;
    }
}
