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

    /**
     * BULK ADD — the one-time opening: many people, many days, in one go.
     *
     * The owner picks the days (from–to, which weekdays), the people (a
     * department, all departments, or a list of codes) and the times: each
     * person's OWN shift (a night guard gets 19:00–08:30 by himself) or one
     * set of times for everybody. A check runs first ($apply = false) and
     * says how many entries would be added and how many skipped, and why;
     * nothing is written until the owner presses Add.
     *
     * Every rule of a single Add still holds, entry by entry: the reason is
     * kept on each one (att_corrections + audit), nothing in the future,
     * never two entries for one person at the same time. A day that already
     * has an entry for that person is SKIPPED, never changed — so running
     * it twice adds nothing the second time. A shift somebody gave a person
     * for a day is used as it is; a day with none gets the department's
     * regular shift, else the default shift, filed the same way the gate does.
     * An OUT that would be later than now is left open (still inside).
     *
     * @param array{from:string,to:string,days?:string,department_id?:int,codes?:string,mode?:string,in_time?:string,out_time?:string,reason?:string} $o
     * @return array<string,mixed>
     */
    public static function bulk(array $o, bool $apply, int $actorId): array
    {
        $from = (string) ($o['from'] ?? ''); $to = (string) ($o['to'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return self::no('VALIDATION', 'Choose the first and the last day.');
        }
        if ($to < $from) {
            return self::no('VALIDATION', 'The last day is before the first day.');
        }
        $tz = new DateTimeZone(self::TZ); $utc = new DateTimeZone('UTC');
        $d1 = new DateTimeImmutable($from, $tz); $d2 = new DateTimeImmutable($to, $tz);
        $span = (int) $d1->diff($d2)->days + 1;
        if ($span > 62) {
            return self::no('VALIDATION', 'At most 62 days at a time — do it in two parts.');
        }
        $days = preg_replace('/[^1-7]/', '', (string) ($o['days'] ?? '1234567'));
        if ($days === '') {
            return self::no('VALIDATION', 'Tick at least one day of the week.');
        }
        $mode = ($o['mode'] ?? 'shift') === 'fixed' ? 'fixed' : 'shift';
        $inT = (string) ($o['in_time'] ?? ''); $outT = (string) ($o['out_time'] ?? '');
        if ($mode === 'fixed') {
            if (!preg_match('/^\d{2}:\d{2}$/', $inT)) {
                return self::no('VALIDATION', 'Give the IN time.');
            }
            if ($outT !== '' && !preg_match('/^\d{2}:\d{2}$/', $outT)) {
                return self::no('VALIDATION', 'The OUT time is not a time.');
            }
            if ($outT === '' && $span > 1) {
                return self::no('VALIDATION', 'Give the OUT time — an empty OUT ("still inside") is only for a single day.');
            }
        }
        $reason = trim((string) ($o['reason'] ?? ''));
        if ($apply && $reason === '') {
            return self::no('REASON_REQUIRED', 'Say why — the reason is kept with every entry.');
        }

        // the people
        $unknown = [];
        $codes = array_values(array_unique(array_filter(preg_split('/[\s,;]+/', (string) ($o['codes'] ?? '')) ?: [], fn($c) => $c !== '')));
        if ($codes) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            $emps = Db::all("SELECT e.id, e.code, e.full_name, e.department_id FROM att_employees e WHERE e.active = 1 AND e.code IN ($in) ORDER BY e.code", $codes);
            $found = array_flip(array_map('strval', array_column($emps, 'code')));
            foreach ($codes as $c) { if (!isset($found[(string) $c])) $unknown[] = $c; }
        } else {
            $dep = (int) ($o['department_id'] ?? 0);
            $emps = $dep > 0
                ? Db::all('SELECT id, code, full_name, department_id FROM att_employees WHERE active = 1 AND department_id = ? ORDER BY code', [$dep])
                : Db::all('SELECT id, code, full_name, department_id FROM att_employees WHERE active = 1 ORDER BY code');
        }
        if (!$emps) {
            return self::no('VALIDATION', $unknown ? 'None of those codes is an active employee.' : 'Nobody to add — that department has no active employees.');
        }

        // the dates
        $dates = [];
        for ($d = $d1; $d <= $d2; $d = $d->modify('+1 day')) {
            if (strpos($days, $d->format('N')) !== false) $dates[] = $d->format('Y-m-d');
        }
        if (!$dates) {
            return self::no('VALIDATION', 'None of those days falls on the weekdays you ticked.');
        }
        if (count($dates) * count($emps) > 5000) {
            return self::no('VALIDATION', count($emps) . ' people × ' . count($dates) . ' days is more than 5,000 entries at once — do one department, or fewer days, at a time.');
        }

        // what is already there, read once
        $ids = array_map('intval', array_column($emps, 'id'));
        $inIds = implode(',', $ids);
        $insts = [];
        foreach (Db::all("SELECT id, employee_id, work_date, starts_at, ends_at FROM att_shift_instances
                           WHERE employee_id IN ($inIds) AND work_date BETWEEN ? AND ?", [$dates[0], end($dates)]) as $r) {
            $insts[(int) $r['employee_id']][(string) $r['work_date']] = $r;
        }
        $lo = Time::sql($d1->modify('-2 days')->setTimezone($utc)); $hi = Time::sql($d2->modify('+3 days')->setTimezone($utc));
        $have = [];
        foreach (Db::all("SELECT employee_id, opened_at, closed_at FROM att_entry_cycles
                           WHERE employee_id IN ($inIds) AND opened_at < ? AND (closed_at IS NULL OR closed_at > ?)", [$hi, $lo]) as $r) {
            $have[(int) $r['employee_id']][] = [new DateTimeImmutable((string) $r['opened_at'], $utc),
                $r['closed_at'] !== null ? new DateTimeImmutable((string) $r['closed_at'], $utc) : null];
        }
        $regular = [];
        try {
            foreach (Db::all('SELECT id, regular_shift_id FROM att_departments') as $r) $regular[(int) $r['id']] = (int) ($r['regular_shift_id'] ?? 0);
        } catch (\Throwable) {}
        $defaultId = (int) Policy::get('default_shift_id', '');
        $shiftRows = [];
        foreach (Db::all('SELECT * FROM att_shifts') as $r) $shiftRows[(int) $r['id']] = $r;

        $now = Time::nowUtc();
        $plan = []; $skip = ['already' => 0, 'future' => 0, 'noshift' => 0];
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            foreach ($dates as $wd) {
                $inst = $insts[$eid][$wd] ?? null;
                $sid = 0; $source = '';
                if ($inst === null) {
                    $sid = $regular[(int) $e['department_id']] ?? 0; $source = 'assigned';
                    if ($sid <= 0 || !isset($shiftRows[$sid])) { $sid = $defaultId; $source = 'default_auto'; }
                    if ($sid <= 0 || !isset($shiftRows[$sid])) { $skip['noshift']++; continue; }
                    $w = Shifts::resolveWindow($shiftRows[$sid], $wd);
                    $sStart = $w['starts_at']; $sEnd = $w['ends_at'];
                } else {
                    $sStart = new DateTimeImmutable((string) $inst['starts_at'], $utc); $sEnd = new DateTimeImmutable((string) $inst['ends_at'], $utc);
                }
                if ($mode === 'shift') {
                    $tin = $sStart; $tout = $sEnd;
                } else {
                    $tin = (new DateTimeImmutable($wd . ' ' . $inT, $tz))->setTimezone($utc);
                    $tout = null;
                    if ($outT !== '') {
                        $tout = (new DateTimeImmutable($wd . ' ' . $outT, $tz))->setTimezone($utc);
                        if ($tout <= $tin) $tout = $tout->modify('+1 day');
                    }
                }
                if ($tin > $now) { $skip['future']++; continue; }
                if ($tout !== null && $tout > $now) $tout = null;           // not over yet: still inside
                $clash = false;
                foreach ($have[$eid] ?? [] as [$a, $b]) {
                    if ($a < ($tout ?? new DateTimeImmutable('9999-12-31', $utc)) && ($b === null || $b > $tin)) { $clash = true; break; }
                }
                if ($clash) { $skip['already']++; continue; }
                $have[$eid][] = [$tin, $tout];                              // two days of the same run never overlap either
                $plan[] = [$e, $wd, $inst, $sid, $source, $tin, $tout];
            }
        }

        $summary = ['people' => count($emps), 'days' => count($dates), 'to_add' => count($plan),
                    'skipped_already' => $skip['already'], 'skipped_future' => $skip['future'], 'skipped_no_shift' => $skip['noshift'],
                    'unknown_codes' => $unknown,
                    'sample' => array_map(fn($p) => ['code' => $p[0]['code'], 'name' => $p[0]['full_name'], 'date' => $p[1],
                        'in' => $p[5]->setTimezone($tz)->format('H:i'), 'out' => $p[6] ? $p[6]->setTimezone($tz)->format('H:i') : null], array_slice($plan, 0, 8))];
        if (!$apply) {
            return ['ok' => true, 'applied' => false] + $summary;
        }

        $added = 0; $failed = [];
        foreach ($plan as [$e, $wd, $inst, $sid, $source, $tin, $tout]) {
            try {
                Db::tx(static function () use ($e, $wd, $inst, $sid, $source, $tin, $tout, $reason, $actorId, &$added) {
                    $eid = (int) $e['id'];
                    Db::run('SELECT id FROM att_employees WHERE id = ? FOR UPDATE', [$eid]);
                    if (self::overlap($eid, $tin, $tout, 0) !== null) return;   // something arrived meanwhile: leave it
                    $iid = $inst !== null ? (int) $inst['id']
                        : (int) (Db::value('SELECT id FROM att_shift_instances WHERE employee_id = ? AND work_date = ?', [$eid, $wd])
                                 ?? Shifts::assign($eid, $sid, $wd, $source, $actorId));
                    $seq = (int) Db::value('SELECT COALESCE(MAX(entry_sequence), 0) + 1 FROM att_entry_cycles WHERE shift_instance_id = ?', [$iid]);
                    $min = $tout !== null ? intdiv($tout->getTimestamp() - $tin->getTimestamp(), 60) : null;
                    Db::run(
                        "INSERT INTO att_entry_cycles (employee_id, shift_instance_id, entry_sequence, gate_in_event_id, opened_at, closed_at, present_minutes,
                                                       in_source, close_source, edited_at, edited_by)
                         VALUES (?, ?, ?, NULL, ?, ?, ?, 'admin', ?, ?, ?)",
                        [$eid, $iid, $seq, Time::sql($tin), $tout ? Time::sql($tout) : null, $min, $tout ? 'admin' : 'gate', Time::sql(Time::nowUtc()), $actorId]
                    );
                    $id = Db::lastId();
                    self::record($id, 'admin.entry_add', null, ['employee_code' => $e['code'], 'opened_at' => Time::sql($tin),
                        'closed_at' => $tout ? Time::sql($tout) : null, 'present_minutes' => $min, 'bulk' => true], $reason, $actorId);
                    $added++;
                });
            } catch (\Throwable $ex) {
                if (count($failed) < 10) $failed[] = $e['code'] . ' ' . $wd . ': ' . $ex->getMessage();
            }
        }
        return ['ok' => true, 'applied' => true, 'added' => $added, 'failed' => $failed] + $summary;
    }

    /**
     * ENTRIES FOR A DATE RANGE (owner, 23 Nov 2026: People Finance › Entries —
     * one employee or many, a department, one day or several). Up to 31 shift
     * days; filed by the SHIFT DAY, so a night guard's 19:00–08:30 belongs to
     * the day it started. Also returns the people in scope, so the grid can
     * show a day with no entry as an empty cell. Two queries, both on indexed
     * columns, whatever the range.
     *
     * @return array<string,mixed>
     */
    public static function listRange(string $from, string $to, int $deptId = 0, string $q = ''): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return self::no('VALIDATION', 'Choose the first and the last day.');
        }
        if ($to < $from) { [$from, $to] = [$to, $from]; }
        $span = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
        if ($span > 31) {
            return self::no('VALIDATION', 'At most 31 days at a time.');
        }
        $w = ['e.active = 1']; $p = [];
        if ($deptId > 0) { $w[] = 'e.department_id = ?'; $p[] = $deptId; }
        $q = trim($q);
        if ($q !== '') {
            $codes = array_values(array_filter(preg_split('/[\s,;]+/', $q) ?: [], fn($c) => $c !== ''));
            $or = ['e.full_name LIKE ?']; $p2 = ['%' . addcslashes($q, '\\%_') . '%'];
            if ($codes) { $or[] = 'e.code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')'; $p2 = array_merge($p2, $codes); }
            $w[] = '(' . implode(' OR ', $or) . ')'; $p = array_merge($p, $p2);
        }
        $people = Db::all(
            'SELECT e.id AS employee_id, e.code AS employee_code, e.full_name, d.name AS department_name
               FROM att_employees e JOIN att_departments d ON d.id = e.department_id
              WHERE ' . implode(' AND ', $w) . ' ORDER BY d.name, e.full_name LIMIT 1000', $p);
        $rows = [];
        if ($people) {
            $ids = implode(',', array_map(static fn($r) => (int) $r['employee_id'], $people));
            $rows = Db::all(
                "SELECT c.id AS entry_cycle_id, c.employee_id, c.entry_sequence, c.opened_at, c.closed_at, c.present_minutes, c.in_source, c.close_source,
                        c.edited_at, ei.occurred_at AS scanned_in_at, eo.occurred_at AS scanned_out_at,
                        e.code AS employee_code, e.full_name, d.name AS department_name, s.name AS shift_name, si.work_date, si.ends_at AS shift_ends_at
                   FROM att_entry_cycles c
                   JOIN att_shift_instances si ON si.id = c.shift_instance_id
                   JOIN att_employees e ON e.id = c.employee_id
                   JOIN att_departments d ON d.id = e.department_id
                   JOIN att_shifts s ON s.id = si.shift_id
                   LEFT JOIN att_events ei ON ei.id = c.gate_in_event_id
                   LEFT JOIN att_events eo ON eo.id = c.gate_out_event_id
                  WHERE c.employee_id IN ($ids) AND si.work_date BETWEEN ? AND ?
                  ORDER BY si.work_date, d.name, e.full_name, c.opened_at LIMIT 5000", [$from, $to]);
            $utc = new DateTimeZone('UTC');
            foreach ($rows as &$r) {
                foreach (['opened_at', 'closed_at', 'edited_at', 'scanned_in_at', 'scanned_out_at', 'shift_ends_at'] as $k) {
                    $r[$k] = $r[$k] !== null ? Time::iso(new DateTimeImmutable((string) $r[$k], $utc)) : null;
                }
                $r['entry_cycle_id'] = (int) $r['entry_cycle_id']; $r['employee_id'] = (int) $r['employee_id'];
            }
            unset($r);
        }
        foreach ($people as &$e) { $e['employee_id'] = (int) $e['employee_id']; }
        unset($e);
        return ['ok' => true, 'from' => $from, 'to' => $to, 'people' => $people, 'entries' => $rows, 'today' => Time::nowUtc()->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d')];
    }

    /**
     * ONE CHANGE TO MANY TICKED ENTRIES (owner, 23 Nov 2026).
     *   set     the same IN and/or OUT clock time, each on its own day
     *   move    IN and/or OUT earlier or later by N minutes
     *   fill    give an entry with no OUT its OUT: a time, or its shift end
     *   delete  remove them (a full copy of each is kept)
     *
     * $apply = false only WORKS IT OUT: every entry before → after, and what
     * is wrong with any of them. Nothing is written.
     * $apply = true writes ALL OF THEM OR NONE: one transaction, every entry
     * through the same edit()/delete() a single change uses — the reason on
     * each, the record in att_corrections and the audit log, the overlap and
     * future checks. One refusal and the whole batch is rolled back.
     *
     * @param array<int,int> $ids
     * @param array{action?:string,in_time?:string,out_time?:string,in_minutes?:int,out_minutes?:int,fill?:string,reason?:string} $o
     * @return array<string,mixed>
     */
    public static function bulkEdit(array $ids, array $o, bool $apply, int $actorId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids) {
            return self::no('VALIDATION', 'Tick at least one entry.');
        }
        if (count($ids) > 500) {
            return self::no('VALIDATION', 'At most 500 entries at a time.');
        }
        $act = (string) ($o['action'] ?? '');
        if (!in_array($act, ['set', 'move', 'fill', 'delete'], true)) {
            return self::no('VALIDATION', 'Choose what to change.');
        }
        $hm = static fn($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : '';
        $inT = $hm($o['in_time'] ?? ''); $outT = $hm($o['out_time'] ?? '');
        $mIn = max(-720, min(720, (int) ($o['in_minutes'] ?? 0))); $mOut = max(-720, min(720, (int) ($o['out_minutes'] ?? 0)));
        $fill = ($o['fill'] ?? 'time') === 'shift' ? 'shift' : 'time';
        if ($act === 'set' && $inT === '' && $outT === '') return self::no('VALIDATION', 'Give a new IN time, a new OUT time, or both.');
        if ($act === 'move' && $mIn === 0 && $mOut === 0) return self::no('VALIDATION', 'Give the minutes to move IN, OUT or both.');
        if ($act === 'fill' && $fill === 'time' && $outT === '') return self::no('VALIDATION', 'Give the OUT time.');
        $reason = trim((string) ($o['reason'] ?? ''));
        if ($apply && $reason === '') {
            return self::no('REASON_REQUIRED', 'Say why — the reason is kept with every entry.');
        }

        $tz = new DateTimeZone(self::TZ); $utc = new DateTimeZone('UTC');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::all(
            "SELECT c.id, c.employee_id, c.opened_at, c.closed_at, e.code, e.full_name, si.work_date, si.ends_at
               FROM att_entry_cycles c JOIN att_employees e ON e.id = c.employee_id JOIN att_shift_instances si ON si.id = c.shift_instance_id
              WHERE c.id IN ($in) ORDER BY si.work_date, e.full_name, c.opened_at", $ids);
        $at = static function (DateTimeImmutable $day, string $hm) use ($tz, $utc): DateTimeImmutable {
            return (new DateTimeImmutable($day->setTimezone($tz)->format('Y-m-d') . ' ' . $hm, $tz))->setTimezone($utc);
        };
        $loc = static fn(?DateTimeImmutable $d) => $d ? $d->setTimezone($tz)->format('Y-m-d H:i') : null;

        $plan = []; $bad = 0; $skip = 0;
        foreach ($rows as $r) {
            $oIn = new DateTimeImmutable((string) $r['opened_at'], $utc);
            $oOut = $r['closed_at'] !== null ? new DateTimeImmutable((string) $r['closed_at'], $utc) : null;
            $nIn = null; $nOut = null; $why = null; $same = false;
            if ($act === 'set') {
                $nIn = $inT !== '' ? $at($oIn, $inT) : null;
                if ($outT !== '') { $nOut = $at($nIn ?? $oIn, $outT); if ($nOut <= ($nIn ?? $oIn)) $nOut = $nOut->modify('+1 day'); }
            } elseif ($act === 'move') {
                $nIn = $mIn !== 0 ? $oIn->modify(($mIn > 0 ? '+' : '') . $mIn . ' minutes') : null;
                $nOut = $mOut !== 0 && $oOut !== null ? $oOut->modify(($mOut > 0 ? '+' : '') . $mOut . ' minutes') : null;
                if ($nIn === null && $nOut === null) { $same = true; $why = 'no OUT to move'; }
            } elseif ($act === 'fill') {
                if ($oOut !== null) { $same = true; $why = 'already has an OUT'; }
                elseif ($fill === 'shift') $nOut = new DateTimeImmutable((string) $r['ends_at'], $utc);
                else { $nOut = $at($oIn, $outT); if ($nOut <= $oIn) $nOut = $nOut->modify('+1 day'); }
            }
            if ($act !== 'delete' && !$same) {
                if (($nIn === null || $nIn == $oIn) && ($nOut === null || $nOut == $oOut)) { $same = true; $why = 'already like that'; }
                else {
                    $chk = self::checkTimes($nIn ?? $oIn, $nOut ?? $oOut) ?? self::overlap((int) $r['employee_id'], $nIn ?? $oIn, $nOut ?? $oOut, (int) $r['id']);
                    if ($chk !== null) { $why = $chk['error']; $bad++; }
                }
            }
            if ($same) $skip++;
            $plan[] = ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['full_name'], 'day' => $r['work_date'],
                       'in' => $loc($oIn), 'out' => $loc($oOut), 'new_in' => $act === 'delete' ? null : $loc($nIn ?? $oIn),
                       'new_out' => $act === 'delete' ? null : $loc($nOut ?? $oOut), 'skip' => $same, 'problem' => $same ? null : $why,
                       '_in' => $nIn, '_out' => $nOut];
        }
        $gone = count($ids) - count($rows);
        $out = ['ok' => true, 'applied' => false, 'action' => $act, 'count' => count($plan) - $skip, 'skipped' => $skip, 'problems' => $bad, 'missing' => $gone,
                'rows' => array_map(static function ($x) { unset($x['_in'], $x['_out']); return $x; }, $plan)];
        if (!$apply) {
            return $out;
        }
        if ($bad > 0) {
            return self::no('PROBLEMS', $bad . ' of the ticked entries cannot be changed like that (see the list). Untick them or change the times — nothing was saved.');
        }
        if ($out['count'] === 0) {
            return self::no('VALIDATION', 'Nothing to change — every ticked entry is already like that.');
        }
        try {
            Db::tx(static function () use ($plan, $act, $reason, $actorId) {
                foreach ($plan as $x) {
                    if ($x['skip']) continue;
                    $r = $act === 'delete' ? self::delete($x['id'], $reason, $actorId) : self::edit($x['id'], $x['_in'], $x['_out'], $reason, $actorId);
                    if (empty($r['ok'])) {
                        throw new \RuntimeException($x['code'] . ' ' . $x['name'] . ', ' . $x['day'] . ': ' . ($r['error'] ?? 'refused'));
                    }
                }
            });
        } catch (\RuntimeException $e) {
            return self::no('REFUSED', $e->getMessage() . ' — nothing was saved.');
        } catch (\Throwable $e) {
            return self::no('FAILED', 'The change could not be saved, so nothing was saved. Try again.');
        }
        return ['applied' => true] + $out;
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
