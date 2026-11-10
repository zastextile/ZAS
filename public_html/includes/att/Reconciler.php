<?php
declare(strict_types=1);

namespace Att;

use Throwable;

/**
 * Matches HOD confirmations that had to wait with the gate entries they
 * belong to, once those entries reach the server.
 *
 * Invariant 5: "HOD events arriving before gate events remain pending. Never
 * auto-match ambiguous events." Blueprint §3: reconcile "by explicit gate
 * event UUID if known, otherwise employee/shift/time sequence, and send
 * ambiguous cases to HR."
 *
 * WHEN IT RUNS MATTERS AS MUCH AS WHAT IT DOES. A gate phone that was offline
 * all morning may hold two entries for the same person. If this ran after the
 * first of them arrived, a waiting confirmation would see exactly one
 * candidate and be linked to it — and thirty seconds later the second entry
 * would turn up, the one the HOD actually meant. That is a silent wrong guess
 * produced by nothing more than arrival order. So it never runs in the middle
 * of a device's upload: SyncService calls it once a batch says it is the last
 * one (more=false).
 *
 * WHAT THIS DOES NOT CLOSE. Two different gate phones, both offline, each
 * holding an entry for the same employee, syncing at different times. The
 * server cannot know the second phone exists. The match window
 * (hod_match_window_minutes) is the control for that case: the narrower it
 * is, the less likely an early-arriving entry is mistaken for the right one.
 */
final class Reconciler
{
    /**
     * @param list<int>|null $employeeIds null = everyone with a waiting confirmation
     * @return array{linked:int,ambiguous:int,disputed:int,waiting:int}
     */
    public static function run(?array $employeeIds = null): array
    {
        $out = ['linked' => 0, 'ambiguous' => 0, 'disputed' => 0, 'waiting' => 0];

        $sql = "SELECT id, event_uuid, employee_id, occurred_at, gate_event_uuid, hod_user_id
                  FROM att_hod_arrivals
                 WHERE status = 'pending_match'
                   AND received_at > (UTC_TIMESTAMP(6) - INTERVAL 30 DAY)";
        $params = [];
        if ($employeeIds !== null) {
            if ($employeeIds === []) {
                return $out;
            }
            $sql .= ' AND employee_id IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')';
            foreach ($employeeIds as $e) { $params[] = (int) $e; }
        }
        $sql .= ' ORDER BY employee_id, occurred_at, id';

        $byEmployee = [];
        foreach (Db::all($sql, $params) as $row) {
            $byEmployee[(int) $row['employee_id']][] = $row;
        }

        foreach ($byEmployee as $employeeId => $arrivals) {
            $free = [];

            // ---- 1. confirmations that know exactly which gate event they mean
            foreach ($arrivals as $a) {
                if ($a['gate_event_uuid'] === null || $a['gate_event_uuid'] === '') {
                    $free[] = $a;
                    continue;
                }
                $cycle = Db::one(
                    'SELECT c.id, c.employee_id FROM att_entry_cycles c
                       JOIN att_events ev ON ev.id = c.gate_in_event_id
                      WHERE ev.event_uuid = ?',
                    [$a['gate_event_uuid']]
                );
                if ($cycle === null) {
                    $out['waiting']++;           // that gate event still has not arrived
                    continue;
                }
                if ((int) $cycle['employee_id'] !== $employeeId) {
                    self::mark($a, 'ambiguous', 'the named gate event belongs to a different employee');
                    $out['ambiguous']++;
                    continue;
                }
                $out[self::link($a, (int) $cycle['id'], 'gate_event_uuid')]++;
            }

            // ---- 2. confirmations that know only the employee and the time
            $candidates = [];
            $claims = [];
            foreach ($free as $a) {
                $t = Time::fromDb((string) $a['occurred_at']);
                $ids = $t === null ? [] : array_map('intval', array_column(HodService::candidateCycles($employeeId, $t), 'id'));
                $candidates[(int) $a['id']] = $ids;
                foreach ($ids as $cid) {
                    $claims[$cid] = ($claims[$cid] ?? 0) + 1;
                }
            }
            foreach ($free as $a) {
                $ids = $candidates[(int) $a['id']];
                if ($ids === []) {
                    $out['waiting']++;
                    continue;
                }
                if (count($ids) > 1) {
                    // AT05: more than one entry it could be. A person decides.
                    self::mark($a, 'ambiguous', 'candidate cycles: ' . implode(',', $ids));
                    $out['ambiguous']++;
                    continue;
                }
                if (($claims[$ids[0]] ?? 0) > 1) {
                    // One entry, two waiting confirmations. Also not ours to pick.
                    self::mark($a, 'ambiguous', 'more than one waiting confirmation fits cycle ' . $ids[0]);
                    $out['ambiguous']++;
                    continue;
                }
                $out[self::link($a, $ids[0], 'sequence')]++;
            }
        }

        return $out;
    }

    /** @return 'linked'|'disputed' */
    private static function link(array $arrival, int $cycleId, string $method): string
    {
        try {
            $st = Db::run(
                "UPDATE att_hod_arrivals
                    SET entry_cycle_id = ?, status = 'confirmed', match_method = ?, matched_at = ?
                  WHERE id = ? AND status = 'pending_match'",
                [$cycleId, $method, Time::sql(), (int) $arrival['id']]
            );
        } catch (Throwable $e) {
            if (!Db::isDuplicate($e)) {
                throw $e;
            }
            /* The entry was confirmed by somebody else while this one waited.
               Two confirmations, one entry: kept, marked, shown to HR. Neither
               is deleted — the second HOD did see something. */
            self::mark($arrival, 'disputed', "cycle $cycleId was already confirmed by another confirmation");
            return 'disputed';
        }
        if ($st->rowCount() > 0) {
            Audit::record('hod.reconciled', 'att_entry_cycle', $cycleId, null, [
                'hod_arrival_id' => (int) $arrival['id'],
                'event_uuid'     => $arrival['event_uuid'],
                'match_method'   => $method,
            ], (int) $arrival['hod_user_id'], 'matched after the gate event arrived');
        }
        return 'linked';
    }

    private static function mark(array $arrival, string $status, string $why): void
    {
        Db::run(
            /* matched_at doubles as "status last changed": SyncService::changes()
               reads it so a phone showing "waiting" learns it went to HR. */
            "UPDATE att_hod_arrivals SET status = ?, ambiguity_reason = ?, matched_at = ? WHERE id = ? AND status = 'pending_match'",
            [$status, substr($why, 0, 255), Time::sql(), (int) $arrival['id']]
        );
        Audit::record('hod.' . $status, 'att_hod_arrival', (int) $arrival['id'], null,
            ['event_uuid' => $arrival['event_uuid'], 'why' => $why], (int) $arrival['hod_user_id']);
    }
}
