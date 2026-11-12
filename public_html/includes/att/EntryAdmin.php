<?php
declare(strict_types=1);

namespace Att;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The administrator's own changes to attendance entries: ADD one for someone
 * who was never scanned, EDIT the IN or OUT time, DELETE one that should not
 * be there (owner's decision, 12 Nov 2026). Administrator only — the API
 * route checks that; nothing here trusts a role sent by a phone.
 *
 * What is kept, whatever is changed:
 *   - att_events, the gate's own record, is never edited. An edited entry's
 *     scans keep the time they were scanned at, and the report shows both.
 *   - every change is a row in att_corrections — what it was, what it became,
 *     the reason and the administrator — and a line in the audit log.
 *   - a DELETED entry is removed from attendance and from every report. A
 *     full copy (the entry, its scans' ids, its HOD confirmation) is kept in
 *     att_corrections, so a mistake can be put back by hand. Its HOD
 *     confirmation is set aside ("dismissed") with the reason.
 *
 * Rules every change keeps: IN before OUT; nothing in the future; never two
 * entries for one person at the same time (and never two open).
 */
final class EntryAdmin
{
    private const TZ = 'Asia/Karachi';

    /** Entries for one factory day, optionally one employee. @return array<string,mixed> */
    public static function list(string $date, string $employee = ''): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return self::no('VALIDATION', 'Choose a day.');
        }
        $tz = new DateTimeZone(self::TZ);
        $from = (new DateTimeImmutable($date . ' 00:00:00', $tz))->setTimezone(new DateTimeZone('UTC'));
        $to = $from->modify('+1 day');
        $where = 'c.opened_at >= ? AND c.opened_at < ?';
        $p = [Time::sql($from), Time::sql($to)];
        $employee = trim($employee);
        if ($employee !== '') {
            $where .= ' AND (e.code = ? OR e.full_name LIKE ?)';
            $p[] = $employee;
            $p[] = '%' . addcslashes($employee, '\\%_') . '%';
        }
        $rows = Db::all(
            "SELECT c.id AS entry_cycle_id, c.entry_sequence, c.opened_at, c.closed_at, c.present_minutes, c.in_source, c.close_source,
                    c.edited_at, ei.occurred_at AS scanned_in_at, eo.occurred_at AS scanned_out_at,
                    e.code AS employee_code, e.full_name, d.name AS department_name, s.name AS shift_name, si.work_date
               FROM att_entry_cycles c
               JOIN att_employees e ON e.id = c.employee_id
               JOIN att_departments d ON d.id = e.department_id
               JOIN att_shift_instances si ON si.id = c.shift_instance_id
               JOIN att_shifts s ON s.id = si.shift_id
               LEFT JOIN att_events ei ON ei.id = c.gate_in_event_id
               LEFT JOIN att_events eo ON eo.id = c.gate_out_event_id
              WHERE $where
              ORDER BY e.full_name, c.opened_at LIMIT 1000", $p);
        $utc = new DateTimeZone('UTC');
        foreach ($rows as &$r) {
            foreach (['opened_at', 'closed_at', 'edited_at', 'scanned_in_at', 'scanned_out_at'] as $k) {
                $r[$k] = $r[$k] !== null ? Time::iso(new DateTimeImmutable((string) $r[$k], $utc)) : null;
            }
        }
        unset($r);
        return ['ok' => true, 'date' => $date, 'entries' => $rows];
    }

    /** @return array<string,mixed> */
    public static function add(string $code, ?DateTimeImmutable $in, ?DateTimeImmutable $out, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return self::no('REASON_REQUIRED', 'Say why — the reason is kept with the change.');
        }
        $emp = Employees::findByCodeOrQr(trim($code));
        if ($emp === null) {
            return self::no('EMPLOYEE_NOT_FOUND', 'No employee with that code.');
        }
        if ($in === null) {
            return self::no('VALIDATION', 'Give the IN time.');
        }
        if (($bad = self::checkTimes($in, $out)) !== null) {
            return $bad;
        }
        $empId = (int) $emp['id'];
        return Db::tx(static function () use ($empId, $emp, $in, $out, $reason, $actorId) {
            Db::run('SELECT id FROM att_employees WHERE id = ? FOR UPDATE', [$empId]);   // one change per person at a time
            if (($clash = self::overlap($empId, $in, $out, 0)) !== null) {
                return $clash;
            }
            $instance = self::instanceNear($empId, $in, $actorId);
            if ($instance === null) {
                return self::no('NO_SHIFT_INSTANCE', $emp['full_name'] . ' has no shift that day and no default shift is set (Setup › Settings).');
            }
            $iid = (int) $instance['id'];
            Db::run('SELECT id FROM att_shift_instances WHERE id = ? FOR UPDATE', [$iid]);
            $seq = (int) Db::value('SELECT COALESCE(MAX(entry_sequence), 0) + 1 FROM att_entry_cycles WHERE shift_instance_id = ?', [$iid]);
            $min = $out !== null ? intdiv($out->getTimestamp() - $in->getTimestamp(), 60) : null;
            Db::run(
                "INSERT INTO att_entry_cycles (employee_id, shift_instance_id, entry_sequence, gate_in_event_id, opened_at, closed_at, present_minutes,
                                               in_source, close_source, edited_at, edited_by)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, 'admin', ?, ?, ?)",
                [$empId, $iid, $seq, Time::sql($in), $out ? Time::sql($out) : null, $min, $out ? 'admin' : 'gate', Time::sql(Time::nowUtc()), $actorId]
            );
            $id = Db::lastId();
            $new = ['employee_code' => $emp['code'], 'opened_at' => Time::sql($in), 'closed_at' => $out ? Time::sql($out) : null, 'present_minutes' => $min];
            self::record($id, 'admin.entry_add', null, $new, $reason, $actorId);
            return ['ok' => true, 'entry_cycle_id' => $id, 'present_minutes' => $min];
        });
    }

    /** Change the IN and/or OUT time. A null time leaves that side as it is. @return array<string,mixed> */
    public static function edit(int $id, ?DateTimeImmutable $in, ?DateTimeImmutable $out, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return self::no('REASON_REQUIRED', 'Say why — the reason is kept with the change.');
        }
        if ($in === null && $out === null) {
            return self::no('VALIDATION', 'Give a new IN time, a new OUT time, or both.');
        }
        return Db::tx(static function () use ($id, $in, $out, $reason, $actorId) {
            $c = Db::one('SELECT * FROM att_entry_cycles WHERE id = ? FOR UPDATE', [$id]);
            if ($c === null) {
                return self::no('NOT_FOUND', 'No such entry. It may have been deleted.');
            }
            $utc = new DateTimeZone('UTC');
            $newIn = $in ?? new DateTimeImmutable((string) $c['opened_at'], $utc);
            $newOut = $out ?? ($c['closed_at'] !== null ? new DateTimeImmutable((string) $c['closed_at'], $utc) : null);
            if (($bad = self::checkTimes($newIn, $newOut)) !== null) {
                return $bad;
            }
            if (($clash = self::overlap((int) $c['employee_id'], $newIn, $newOut, $id)) !== null) {
                return $clash;
            }
            $min = $newOut !== null ? intdiv($newOut->getTimestamp() - $newIn->getTimestamp(), 60) : null;
            $closeSource = $out !== null ? 'admin' : $c['close_source'];
            Db::run(
                'UPDATE att_entry_cycles SET opened_at = ?, closed_at = ?, present_minutes = ?, close_source = ?, edited_at = ?, edited_by = ? WHERE id = ?',
                [Time::sql($newIn), $newOut ? Time::sql($newOut) : null, $min, $closeSource, Time::sql(Time::nowUtc()), $actorId, $id]
            );
            self::record($id, 'admin.entry_edit',
                ['opened_at' => $c['opened_at'], 'closed_at' => $c['closed_at'], 'present_minutes' => $c['present_minutes'], 'close_source' => $c['close_source']],
                ['opened_at' => Time::sql($newIn), 'closed_at' => $newOut ? Time::sql($newOut) : null, 'present_minutes' => $min, 'close_source' => $closeSource],
                $reason, $actorId);
            return ['ok' => true, 'entry_cycle_id' => $id, 'present_minutes' => $min];
        });
    }

    /** Remove an entry from attendance and every report; a full copy is kept. @return array<string,mixed> */
    public static function delete(int $id, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return self::no('REASON_REQUIRED', 'Say why — the reason is kept with the change.');
        }
        return Db::tx(static function () use ($id, $reason, $actorId) {
            $c = Db::one('SELECT * FROM att_entry_cycles WHERE id = ? FOR UPDATE', [$id]);
            if ($c === null) {
                return self::no('NOT_FOUND', 'No such entry. It may have been deleted already.');
            }
            $hod = Db::all('SELECT * FROM att_hod_arrivals WHERE entry_cycle_id = ?', [$id]);
            $flags = Db::tableExists('att_flags') ? Db::all('SELECT id FROM att_flags WHERE entry_cycle_id = ?', [$id]) : [];
            $code = Db::value('SELECT code FROM att_employees WHERE id = ?', [(int) $c['employee_id']]);
            Db::run(
                "UPDATE att_hod_arrivals SET entry_cycle_id = NULL, status = 'dismissed', resolved_by = ?, resolved_at = ?, resolution_note = ?
                  WHERE entry_cycle_id = ?",
                [$actorId, Time::sql(Time::nowUtc()), 'Entry deleted by the administrator: ' . $reason, $id]
            );
            if ($flags !== []) {
                Db::run('UPDATE att_flags SET entry_cycle_id = NULL WHERE entry_cycle_id = ?', [$id]);
            }
            Db::run('DELETE FROM att_entry_cycles WHERE id = ?', [$id]);
            self::record($id, 'admin.entry_delete', ['entry' => $c, 'employee_code' => $code, 'hod_arrivals' => $hod, 'flag_ids' => array_column($flags, 'id')],
                ['deleted' => true], $reason, $actorId);
            return ['ok' => true, 'entry_cycle_id' => $id, 'deleted' => true];
        });
    }

    // ------------------------------------------------------------- helpers

    private static function checkTimes(DateTimeImmutable $in, ?DateTimeImmutable $out): ?array
    {
        $soon = Time::nowUtc()->modify('+1 minute');
        if ($in > $soon || ($out !== null && $out > $soon)) {
            return self::no('VALIDATION', 'A time cannot be in the future.');
        }
        if ($out !== null && $out <= $in) {
            return self::no('VALIDATION', 'OUT must be after IN.');
        }
        if ($out !== null && $out->getTimestamp() - $in->getTimestamp() > 36 * 3600) {
            return self::no('VALIDATION', 'One entry cannot be longer than 36 hours. Check the dates.');
        }
        return null;
    }

    /** Another entry of the same person at the same time? */
    private static function overlap(int $empId, DateTimeImmutable $in, ?DateTimeImmutable $out, int $exceptId): ?array
    {
        $hit = Db::one(
            'SELECT opened_at, closed_at FROM att_entry_cycles
              WHERE employee_id = ? AND id <> ? AND opened_at < ? AND (closed_at IS NULL OR closed_at > ?)
              ORDER BY opened_at LIMIT 1',
            [$empId, $exceptId, $out ? Time::sql($out) : '9999-12-31 00:00:00', Time::sql($in)]
        );
        if ($hit === null) {
            return null;
        }
        $f = fn($v) => $v === null ? 'still inside' : (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::TZ))->format('d M H:i');
        return self::no('OVERLAP', 'That overlaps another entry of the same person (IN ' . $f($hit['opened_at']) . ', OUT ' . $f($hit['closed_at']) . '). Change or delete that one first.');
    }

    /** The shift day an entry belongs to: the person's own shift nearest the IN time, else the default shift. */
    private static function instanceNear(int $empId, DateTimeImmutable $in, int $actorId): ?array
    {
        $at = Time::sql($in);
        $row = Db::one(
            'SELECT * FROM att_shift_instances
              WHERE employee_id = ? AND ? BETWEEN DATE_SUB(starts_at, INTERVAL 12 HOUR) AND DATE_ADD(ends_at, INTERVAL 12 HOUR)
              ORDER BY ABS(TIMESTAMPDIFF(MINUTE, starts_at, ?)) LIMIT 1',
            [$empId, $at, $at]
        );
        if ($row !== null) {
            return $row;
        }
        [$inst, ] = Shifts::instanceForOrDefault($empId, $in, $actorId);
        return $inst;
    }

    private static function record(int $id, string $action, ?array $old, ?array $new, string $reason, int $actorId): void
    {
        Db::run(
            'INSERT INTO att_corrections (subject_type, subject_id, action, old_data, new_data, reason, actor_id) VALUES (?,?,?,?,?,?,?)',
            ['att_entry_cycle', $id, $action, $old === null ? null : json_encode($old), $new === null ? null : json_encode($new), $reason, $actorId]
        );
        Audit::record($action, 'att_entry_cycle', $id, $old, $new, $actorId);
    }

    private static function no(string $code, string $message): array
    {
        return ['ok' => false, 'reason_code' => $code, 'error' => $message];
    }
}
