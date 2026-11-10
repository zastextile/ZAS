<?php
declare(strict_types=1);

namespace Att;

/**
 * The module's audit trail.
 *
 * Invariant 8: "Every approval, amendment, payroll change and attendance
 * correction is auditable." Blueprint §5 adds that corrections "append audit
 * entries, not destructive edits".
 *
 * Two deliberate properties:
 *
 * 1. Append only. There is no update() or delete() here, and nothing else in
 *    the module writes to att_audit_logs. A trail that can be edited answers
 *    no question that anyone would ask it.
 *
 * 2. Writing the trail never breaks the thing being recorded. If the audit
 *    insert itself fails — table missing on a half-finished install, disk
 *    full — the attendance event still stands. An audit failure is logged to
 *    the server error log, because the alternative is refusing a worker entry
 *    at the gate over a logging problem.
 */
final class Audit
{
    public static function record(
        string $action,
        string $subjectType,
        int $subjectId,
        ?array $oldData = null,
        ?array $newData = null,
        ?int $actorId = null,
        ?string $reason = null
    ): void {
        try {
            Db::run(
                'INSERT INTO att_audit_logs
                    (actor_id, action, subject_type, subject_id, old_data, new_data, reason, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $actorId ?? self::currentActorId(),
                    $action,
                    $subjectType,
                    $subjectId,
                    self::encode($oldData),
                    self::encode($newData),
                    $reason,
                    self::ip(),
                ]
            );
        } catch (\Throwable $e) {
            error_log('[att-audit] could not record ' . $action . ': ' . $e->getMessage());
        }
    }

    private static function encode(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json === false ? null : $json;
    }

    private static function currentActorId(): ?int
    {
        if (function_exists('current_user')) {
            $u = \current_user();
            if (is_array($u) && isset($u['id'])) {
                return (int) $u['id'];
            }
        }
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    private static function ip(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return $ip === null ? null : substr((string) $ip, 0, 80);
    }

    /** @return list<array<string,mixed>> */
    public static function forSubject(string $subjectType, int $subjectId, int $limit = 100): array
    {
        return Db::all(
            'SELECT id, actor_id, action, old_data, new_data, reason, ip_address, created_at
               FROM att_audit_logs
              WHERE subject_type = ? AND subject_id = ?
              ORDER BY id DESC
              LIMIT ' . max(1, min(1000, $limit)),
            [$subjectType, $subjectId]
        );
    }
}
