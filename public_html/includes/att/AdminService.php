<?php
declare(strict_types=1);

namespace Att;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Everything the Attendance Setup screen does.
 *
 * Setup is where a typing mistake becomes a payroll dispute three weeks
 * later, so the rules here are about what must NOT be allowed to change
 * quietly underneath records that already exist:
 *
 *   - An employee code that has scans against it cannot be changed. Phones
 *     hold scans by code while offline; a code changed in the office turns
 *     the scans still on a phone into scans for nobody.
 *   - An employee who is inside the factory cannot be made inactive. Their
 *     exit scan would be refused and the entry would stay open for ever.
 *   - A department with active employees cannot be switched off, and the
 *     default shift cannot be switched off while it is the default.
 *   - A shift day that already has entries recorded against it is never
 *     re-pointed at another shift, by a single edit or by a bulk assignment.
 *
 * Every method answers ['ok' => true, ...] or ['ok' => false, 'reason_code',
 * 'error'] — the same shape as the rest of the API — and every change is
 * written to the audit log with what it was before.
 */
final class AdminService
{
    public const IMPORT_MAX      = 2000;
    public const ASSIGN_MAX_DAYS = 92;
    public const ASSIGN_MAX_ROWS = 30000;
    public const PAGE            = 50;

    /**
     * The jobs the People tab hands out, and the User Access permission each
     * one needs to be any use. Kept in step from this one place: a job
     * without its permission cannot open the screen, and a permission left
     * behind after the job is taken away (HR above all) would leave the
     * access in place with nothing on this screen showing it.
     *
     * 'admin' is deliberately absent. It bypasses department scope entirely,
     * and the ERP's own admin accounts already have it.
     */
    public const JOBS = [
        Roles::GATEKEEPER => ['label' => 'Gatekeeper', 'module' => 'attgate', 'actions' => 'vc',  'needs' => 'vc', 'needs_department' => false,
                              // A gatekeeper can also Capture & Flag an entry.
                              'also' => ['attflag' => 'vc']],
        Roles::HOD        => ['label' => 'HOD',        'module' => 'atthod',  'actions' => 'vc',  'needs' => 'vc', 'needs_department' => true,
                              // A HOD asks for overtime for their department.
                              'also' => ['attot' => 'vc', 'attrep' => 'v']],
        Roles::HR         => ['label' => 'HR',         'module' => 'atthr',   'actions' => 'vud', 'needs' => 'vu', 'needs_department' => false,
                              // Blueprint §4: one of HR / Admin / Accounts approves, and verifies the next day.
                              'also' => ['attotap' => 'vu', 'attotvf' => 'vu', 'attrep' => 'v']],
        Roles::ACCOUNTS   => ['label' => 'Accounts',   'module' => 'attpay',  'actions' => 'vu',  'needs' => 'v',  'needs_department' => false,
                              // Accounts approves and verifies too, and enters the rate and finalizes payroll.
                              'also' => ['attotap' => 'vu', 'attotvf' => 'vu', 'attrep' => 'v']],
    ];
    /* 'actions' is what granting the job ticks; 'needs' is the least the job
       must hold to count as working on the People tab. */

    /**
     * Settings an admin may change, with the limits each is held to.
     * duplicate_window_seconds is not here on purpose: the 60-second rule is
     * part of the approved requirements, not a preference.
     */
    public const SETTINGS = [
        'default_shift_id'          => ['type' => 'shift', 'label' => 'Default shift',
            'help' => 'Used when an employee reaches the gate on a day nobody gave them a shift. They are let in and this shift is attached, marked as filled in by the system.'],
        'auto_shift_on_gate_in'     => ['type' => 'bool',  'label' => 'Let in an employee who has no shift for the day',
            'help' => 'On: they are admitted on the default shift. Off: the scan is held for HR instead.'],
        'hod_match_window_minutes'  => ['type' => 'int', 'min' => 15, 'max' => 1440, 'label' => 'HOD matching window (minutes)',
            'help' => 'How far apart a gate entry and a HOD confirmation may be and still be matched automatically. Outside it, HR decides.'],
        'stale_open_cycle_hours'    => ['type' => 'int', 'min' => 4, 'max' => 72, 'label' => 'Show an entry to HR as "left open" after (hours)',
            'help' => 'An entry with no exit scan after this long is listed for HR as a likely missed exit.'],
        'auto_out_enabled'          => ['type' => 'bool', 'label' => 'Automatic OUT for a forgotten exit scan',
            'help' => 'On: someone still not scanned OUT well after their shift ended is closed by the system AT THE SHIFT END (or shift end plus approved overtime), and marked "not scanned out" on reports and for HR.'],
        'auto_out_after_minutes'    => ['type' => 'int', 'min' => 30, 'max' => 720, 'label' => 'Automatic OUT: wait after shift end for a real OUT (minutes)',
            'help' => 'For example 240: with a 6 pm shift end, anyone not scanned out by 10 pm is closed at 6 pm. Approved overtime moves both times later.'],
        'sync_backlog_warn_minutes' => ['type' => 'int', 'min' => 10, 'max' => 1440, 'label' => 'Warn when a phone has unsent scans older than (minutes)',
            'help' => 'The phone app shows a warning once its oldest unsent scan is older than this.'],
        'clock_skew_warn_ms'        => ['type' => 'int', 'min' => 30000, 'max' => 3600000, 'unit_ms' => true, 'label' => 'Report a phone whose clock is wrong by more than (seconds)',
            'help' => 'Phones over this are listed for HR. The system never corrects a time by itself.'],
        'photo_retention_days'      => ['type' => 'int', 'min' => 7, 'max' => 3650, 'label' => 'Keep the photo taken with a flagged entry for (days)',
            'help' => 'After this the picture is deleted from storage. The flag, its reason and its review are kept.'],
        'ot_request_days_ahead'     => ['type' => 'int', 'min' => 0, 'max' => 60, 'label' => 'Overtime: how many days ahead a HOD may request',
            'help' => 'A request for a date further ahead than this is refused.'],
        'ot_request_days_back'      => ['type' => 'int', 'min' => 0, 'max' => 7, 'label' => 'Overtime: how many days back a HOD may still request',
            'help' => '0 means today or later only. 1 allows yesterday, for overtime that was agreed by word of mouth.'],
        'ot_max_minutes'            => ['type' => 'int', 'min' => 30, 'max' => 1440, 'label' => 'Overtime: most minutes for one employee in one day',
            'help' => 'The limit for estimated, approved and actual minutes.'],
        'ot_decision_window_days'   => ['type' => 'int', 'min' => 0, 'max' => 60, 'label' => 'Overtime: days after the work date that a request can still be approved or rejected',
            'help' => '0 means no limit. After the limit, only an amendment can change it.'],
        'ot_verify_from_next_day'   => ['type' => 'bool', 'label' => 'Overtime: actual minutes only from the next day',
            'help' => 'Blueprint §4 says the actual minutes are entered on the next day, once the evening\'s gate record is complete.'],
        'gatekeeper_may_verify_ot'  => ['type' => 'bool', 'label' => 'Overtime: a gatekeeper may enter actual minutes',
            'help' => 'Off: only HR, Accounts and Admin. On: a gatekeeper who is also given "Overtime — Verify Actual Hours" in User Access may too.'],
        'device_self_register'      => ['type' => 'bool', 'label' => 'A gatekeeper or HOD may register their own phone',
            'help' => 'Off: no new phone can be added until this is switched on again. Phones already registered keep working.'],
    ];

    // ---------------------------------------------------------------- overview

    /** The first-day checklist at the top of the screen. */
    public static function overview(): array
    {
        $defaultId = (int) Policy::get('default_shift_id', '');
        $default   = $defaultId > 0 ? Shifts::find($defaultId) : null;
        $users     = self::users()['users'];

        $gateReady = 0;
        $hodDepts  = [];
        foreach ($users as $u) {
            foreach ($u['jobs'] as $j) {
                if (!$j['working']) {
                    continue;
                }
                if ($j['role'] === Roles::GATEKEEPER) { $gateReady++; }
                if ($j['role'] === Roles::HOD && $j['department_id'] !== null) { $hodDepts[(int) $j['department_id']] = true; }
            }
        }

        $noHod = [];
        foreach (Db::all(
            'SELECT d.id, d.name FROM att_departments d
              WHERE d.active = 1
                AND EXISTS (SELECT 1 FROM att_employees e WHERE e.department_id = d.id AND e.active = 1)
              ORDER BY d.name'
        ) as $d) {
            if (!isset($hodDepts[(int) $d['id']])) {
                $noHod[] = (string) $d['name'];
            }
        }

        return [
            'ok'                       => true,
            'departments'              => (int) Db::value('SELECT COUNT(*) FROM att_departments WHERE active = 1'),
            'shifts'                   => (int) Db::value('SELECT COUNT(*) FROM att_shifts WHERE active = 1'),
            'default_shift'            => ($default !== null && (int) $default['active'] === 1) ? (string) $default['name'] : null,
            'employees'                => (int) Db::value('SELECT COUNT(*) FROM att_employees WHERE active = 1'),
            'employees_with_photo'     => Db::columnExists('att_employees', 'reference_photo_key')
                ? (int) Db::value('SELECT COUNT(*) FROM att_employees WHERE active = 1 AND reference_photo_key IS NOT NULL') : 0,
            'gatekeepers_ready'        => $gateReady,
            'departments_without_hod'  => $noHod,
        ];
    }

    // -------------------------------------------------------------- departments

    public static function departments(): array
    {
        return ['ok' => true, 'departments' => Db::all(
            'SELECT d.id, d.name, d.active,
                    (SELECT COUNT(*) FROM att_employees e WHERE e.department_id = d.id AND e.active = 1) AS employees
               FROM att_departments d
              ORDER BY d.active DESC, d.name'
        )];
    }

    public static function saveDepartment(?int $id, string $name, ?bool $active, int $actorId): array
    {
        $name = self::clean($name);
        if ($name === '') {
            return self::no('VALIDATION', 'Give the department a name.');
        }
        if (mb_strlen($name) > 100) {
            return self::no('VALIDATION', 'A department name can be at most 100 characters.');
        }

        $clash = Db::one('SELECT id FROM att_departments WHERE name = ?', [$name]);
        if ($clash !== null && (int) $clash['id'] !== (int) $id) {
            return self::no('NAME_TAKEN', "There is already a department called \"$name\".");
        }

        if ($id === null) {
            $newId = Employees::createDepartment($name, $actorId);
            return ['ok' => true, 'department_id' => $newId];
        }

        $before = Db::one('SELECT id, name, active FROM att_departments WHERE id = ?', [$id]);
        if ($before === null) {
            return self::no('NOT_FOUND', 'That department no longer exists.');
        }
        $newActive = $active === null ? (int) $before['active'] : (int) $active;

        if ($newActive === 0 && (int) $before['active'] === 1) {
            $n = (int) Db::value('SELECT COUNT(*) FROM att_employees WHERE department_id = ? AND active = 1', [$id]);
            if ($n > 0) {
                return self::no('DEPARTMENT_IN_USE',
                    "$n active employee" . ($n === 1 ? ' is' : 's are') . ' in this department. Move them to another department first.');
            }
        }

        Db::run('UPDATE att_departments SET name = ?, active = ? WHERE id = ?', [$name, $newActive, $id]);
        Audit::record('department.update', 'att_department', $id,
            ['name' => $before['name'], 'active' => (int) $before['active']],
            ['name' => $name, 'active' => $newActive], $actorId);

        return ['ok' => true, 'department_id' => $id];
    }

    // ---------------------------------------------------------------- employees

    /** @param array<string,mixed> $f */
    public static function employees(array $f): array
    {
        $where  = [];
        $params = [];

        $state = (string) ($f['state'] ?? 'active');
        if ($state === 'active')   { $where[] = 'e.active = 1'; }
        if ($state === 'inactive') { $where[] = 'e.active = 0'; }

        $dept = (int) ($f['department_id'] ?? 0);
        if ($dept > 0) { $where[] = 'e.department_id = ?'; $params[] = $dept; }

        $q = self::str($f['query'] ?? '');
        if ($q !== '') {
            $where[]  = '(e.code LIKE ? OR e.full_name LIKE ?)';
            $like     = self::like($q);
            $params[] = $like . '%';
            $params[] = '%' . $like . '%';
        }

        $sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total    = (int) Db::value('SELECT COUNT(*) FROM att_employees e' . $sqlWhere, $params);
        $page     = max(1, (int) ($f['page'] ?? 1));
        $offset   = ($page - 1) * self::PAGE;

        $rows = Db::all(
            'SELECT e.id, e.code, e.full_name, e.department_id, d.name AS department_name,
                    e.worker_code, e.qr_payload, e.active,
                    (e.reference_photo_key IS NOT NULL) AS has_photo, (e.notice_ack_at IS NOT NULL) AS notice_given,
                    (c.id IS NOT NULL) AS inside
               FROM att_employees e
               JOIN att_departments d ON d.id = e.department_id
               LEFT JOIN att_entry_cycles c ON c.open_employee_id = e.id'
            . $sqlWhere
            . ' ORDER BY e.active DESC, e.full_name, e.id LIMIT ' . self::PAGE . ' OFFSET ' . $offset,
            $params
        );

        return ['ok' => true, 'employees' => $rows, 'total' => $total, 'page' => $page, 'per_page' => self::PAGE];
    }

    /** @param array<string,mixed> $data */
    public static function saveEmployee(?int $id, array $data, int $actorId): array
    {
        $code   = self::str($data['code'] ?? '');
        $name   = self::str($data['full_name'] ?? '');
        $deptId = (int) ($data['department_id'] ?? 0);
        $worker = self::str($data['worker_code'] ?? '');
        $qr     = self::str($data['qr_payload'] ?? '');

        if ($bad = self::codeProblem($code)) {
            return self::no('VALIDATION', $bad);
        }
        if ($name === '' || mb_strlen($name) > 160) {
            return self::no('VALIDATION', $name === '' ? 'Give the employee\'s name.' : 'A name can be at most 160 characters.');
        }
        if (mb_strlen($worker) > 40) {
            return self::no('VALIDATION', 'A production worker code can be at most 40 characters.');
        }
        if (mb_strlen($qr) > 190) {
            return self::no('VALIDATION', 'The card text can be at most 190 characters.');
        }

        $dept = Db::one('SELECT id, active FROM att_departments WHERE id = ?', [$deptId]);
        if ($dept === null) {
            return self::no('VALIDATION', 'Choose a department.');
        }

        $before = null;
        if ($id !== null) {
            $before = Db::one('SELECT * FROM att_employees WHERE id = ?', [$id]);
            if ($before === null) {
                return self::no('NOT_FOUND', 'That employee no longer exists.');
            }
        }
        // An inactive department may keep the people already in it; nobody new
        // is put there.
        if ((int) $dept['active'] !== 1 && ($before === null || (int) $before['department_id'] !== $deptId)) {
            return self::no('VALIDATION', 'That department is switched off. Choose an active one.');
        }

        $self = (int) ($id ?? 0);

        if (Db::one('SELECT id FROM att_employees WHERE code = ? AND id <> ?', [$code, $self]) !== null) {
            return self::no('CODE_TAKEN', "Code \"$code\" already belongs to another employee.");
        }
        /* The gate looks a scan up as "code OR card text". If one person's
           card text were another person's code, the same scan would mean two
           people and the database would simply pick one. */
        if (Db::one('SELECT id FROM att_employees WHERE qr_payload = ? AND id <> ?', [$code, $self]) !== null) {
            return self::no('CODE_TAKEN', "\"$code\" is already another employee's card text.");
        }
        if ($qr !== '') {
            if (Db::one('SELECT id FROM att_employees WHERE (qr_payload = ? OR code = ?) AND id <> ?', [$qr, $qr, $self]) !== null) {
                return self::no('QR_TAKEN', 'That card text already identifies another employee.');
            }
        }
        if ($worker !== '') {
            if (Db::one('SELECT id FROM att_employees WHERE worker_code = ? AND id <> ?', [$worker, $self]) !== null) {
                return self::no('WORKER_ALREADY_LINKED', "Production worker \"$worker\" is already linked to another employee.");
            }
        }

        if ($before === null) {
            $newId = Employees::create([
                'code' => $code, 'full_name' => $name, 'department_id' => $deptId,
                'worker_code' => $worker, 'qr_payload' => $qr,
            ], $actorId);
            return ['ok' => true, 'employee_id' => $newId];
        }

        if (strcasecmp((string) $before['code'], $code) !== 0 && self::hasHistory($self)) {
            return self::no('CODE_LOCKED',
                'This employee already has attendance recorded under code "' . $before['code'] . '", so the code cannot be changed. '
                . 'If the person has a new number, make the old record inactive and add them again.');
        }

        Db::run(
            'UPDATE att_employees SET code = ?, full_name = ?, department_id = ?, worker_code = ?, qr_payload = ? WHERE id = ?',
            [$code, $name, $deptId, $worker !== '' ? $worker : null, $qr !== '' ? $qr : null, $self]
        );
        if (Db::tableExists('zp_workers') && Db::columnExists('zp_workers', 'att_employee_id')) {
            Db::run('UPDATE zp_workers SET att_employee_id = NULL WHERE att_employee_id = ?', [$self]);
            if ($worker !== '') {
                Db::run('UPDATE zp_workers SET att_employee_id = ? WHERE worker_code = ?', [$self, $worker]);
            }
        }

        Audit::record('employee.update', 'att_employee', $self,
            self::only($before, ['code', 'full_name', 'department_id', 'worker_code', 'qr_payload']),
            ['code' => $code, 'full_name' => $name, 'department_id' => $deptId,
             'worker_code' => $worker !== '' ? $worker : null, 'qr_payload' => $qr !== '' ? $qr : null],
            $actorId);

        return ['ok' => true, 'employee_id' => $self];
    }

    public static function setEmployeeActive(int $id, bool $active, string $reason, int $actorId): array
    {
        $e = Db::one('SELECT id, code, full_name, active, department_id FROM att_employees WHERE id = ?', [$id]);
        if ($e === null) {
            return self::no('NOT_FOUND', 'That employee no longer exists.');
        }
        $reason = self::clean($reason);

        if (!$active) {
            if ($reason === '') {
                return self::no('REASON_REQUIRED', 'Say why — left the company, entered twice, and so on. It is kept on the record.');
            }
            if (Db::one('SELECT id FROM att_entry_cycles WHERE open_employee_id = ?', [$id]) !== null) {
                return self::no('EMPLOYEE_INSIDE',
                    $e['full_name'] . ' is recorded as inside the factory now. Scan them out, or close the entry on the HR screen, first.');
            }
        } else {
            $d = Db::one('SELECT active FROM att_departments WHERE id = ?', [(int) $e['department_id']]);
            if ($d === null || (int) $d['active'] !== 1) {
                return self::no('VALIDATION', 'Their department is switched off. Switch it on, or edit the employee into another one, first.');
            }
        }

        Db::run('UPDATE att_employees SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
        Audit::record($active ? 'employee.reactivate' : 'employee.deactivate', 'att_employee', $id,
            ['active' => (int) $e['active']], ['active' => $active ? 1 : 0], $actorId, $reason !== '' ? $reason : null);

        return ['ok' => true, 'employee_id' => $id];
    }

    /**
     * Add many employees at once, from rows pasted out of a spreadsheet.
     *
     * Always checked in full first. With $commit false nothing is written and
     * the answer says, line by line, what WOULD happen — that is what the
     * screen shows before the user presses the second button. A line whose
     * code is already on the roll is skipped, never overwritten: an import is
     * for adding people, and silently renaming an existing employee because a
     * spreadsheet said so is how the wrong person ends up with the scans.
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function importEmployees(array $rows, bool $commit, bool $createDepartments, int $actorId): array
    {
        if ($rows === []) {
            return self::no('VALIDATION', 'There is nothing to import.');
        }
        if (count($rows) > self::IMPORT_MAX) {
            return self::no('VALIDATION', 'At most ' . self::IMPORT_MAX . ' lines at a time. Split the list and import it in parts.');
        }

        $depts = [];
        foreach (Db::all('SELECT id, name, active FROM att_departments') as $d) {
            $depts[self::key((string) $d['name'])] = $d;
        }
        $onRoll = [];
        $cards  = [];
        $linked = [];
        foreach (Db::all('SELECT code, full_name, worker_code, qr_payload FROM att_employees') as $e) {
            $onRoll[self::key((string) $e['code'])] = (string) $e['full_name'];
            if ($e['qr_payload'] !== null) { $cards[self::key((string) $e['qr_payload'])] = true; }
            if ($e['worker_code'] !== null) { $linked[self::key((string) $e['worker_code'])] = true; }
        }

        $seen     = [];
        $newDepts = [];
        $out      = [];
        $counts   = ['add' => 0, 'exists' => 0, 'error' => 0];

        foreach (array_values($rows) as $i => $row) {
            $code   = self::str($row['code'] ?? '');
            $name   = self::str($row['full_name'] ?? '');
            $dName  = self::str($row['department'] ?? '');
            $worker = self::str($row['worker_code'] ?? '');
            $line   = ['line' => $i + 1, 'code' => $code, 'full_name' => $name, 'department' => $dName,
                       'worker_code' => $worker, 'status' => 'add', 'message' => ''];

            $problem = self::codeProblem($code);
            if ($problem === null && ($name === '' || mb_strlen($name) > 160)) {
                $problem = $name === '' ? 'No name.' : 'Name is longer than 160 characters.';
            }
            if ($problem === null && $dName === '') { $problem = 'No department.'; }
            if ($problem === null && mb_strlen($dName) > 100) { $problem = 'Department name is longer than 100 characters.'; }
            if ($problem === null && mb_strlen($worker) > 40) { $problem = 'Production worker code is longer than 40 characters.'; }

            $k = self::key($code);
            if ($problem === null && isset($seen[$k])) {
                $problem = 'The same code is on line ' . $seen[$k] . ' of this list.';
            }
            if ($problem === null && isset($onRoll[$k])) {
                $line['status']  = 'exists';
                $line['message'] = 'Already on the roll as ' . $onRoll[$k] . ' — left as it is.';
                $counts['exists']++;
                $seen[$k] = $i + 1;
                $out[] = $line;
                continue;
            }
            if ($problem === null && isset($cards[$k])) {
                $problem = 'This code is already another employee\'s card text.';
            }
            if ($problem === null && $worker !== '' && isset($linked[self::key($worker)])) {
                $problem = "Production worker \"$worker\" is already linked to an employee.";
            }
            if ($problem === null) {
                $dk = self::key($dName);
                if (isset($depts[$dk])) {
                    if ((int) $depts[$dk]['active'] !== 1) {
                        $problem = "Department \"$dName\" is switched off.";
                    }
                } elseif ($createDepartments) {
                    $newDepts[$dk] = $newDepts[$dk] ?? $dName;
                    $line['message'] = "New department \"{$newDepts[$dk]}\" will be created.";
                } else {
                    $problem = "There is no department called \"$dName\".";
                }
            }

            if ($problem !== null) {
                $line['status']  = 'error';
                $line['message'] = $problem;
                $counts['error']++;
            } else {
                $counts['add']++;
                $seen[$k] = $i + 1;
                if ($worker !== '') { $linked[self::key($worker)] = true; }
            }
            $out[] = $line;
        }

        $result = ['ok' => true, 'committed' => false, 'counts' => $counts, 'lines' => $out,
                   'new_departments' => array_values($newDepts)];
        if (!$commit || $counts['add'] === 0) {
            return $result;
        }

        Db::tx(static function () use (&$depts, $newDepts, $out, $actorId): void {
            foreach ($newDepts as $dk => $dName) {
                $depts[$dk] = ['id' => Employees::createDepartment($dName, $actorId), 'name' => $dName, 'active' => 1];
            }
            foreach ($out as $line) {
                if ($line['status'] !== 'add') {
                    continue;
                }
                Employees::create([
                    'code'          => $line['code'],
                    'full_name'     => $line['full_name'],
                    'department_id' => (int) $depts[self::key($line['department'])]['id'],
                    'worker_code'   => $line['worker_code'],
                ], $actorId);
            }
        });
        Audit::record('employee.import', 'att_employee', 0, null, $counts, $actorId);

        $result['committed'] = true;
        return $result;
    }

    /**
     * Production workers who are not on the attendance roll yet.
     *
     * Compared in PHP, not with a SQL join: zp_workers belongs to the ERP and
     * was created with whatever collation the server had that day, so joining
     * its text column to ours can fail outright with "illegal mix of
     * collations" on one server and work on another.
     */
    public static function unlinkedWorkers(): array
    {
        if (!Db::tableExists('zp_workers')) {
            return ['ok' => true, 'available' => false, 'workers' => []];
        }
        $have = [];
        foreach (Db::all('SELECT code, worker_code FROM att_employees') as $e) {
            $have[self::key((string) $e['code'])] = true;
            if ($e['worker_code'] !== null) { $have[self::key((string) $e['worker_code'])] = true; }
        }
        $workers = [];
        foreach (Db::all('SELECT worker_code, worker_name, department FROM zp_workers WHERE is_active = 1 ORDER BY worker_name') as $w) {
            $code = self::clean((string) $w['worker_code']);
            if ($code === '' || isset($have[self::key($code)])) {
                continue;
            }
            $workers[] = ['code' => $code, 'full_name' => self::clean((string) $w['worker_name']),
                          'department' => self::str($w['department'] ?? ''), 'worker_code' => $code];
            if (count($workers) >= self::IMPORT_MAX) {
                break;
            }
        }
        return ['ok' => true, 'available' => true, 'workers' => $workers];
    }

    // ------------------------------------------------------------------- shifts

    public static function shifts(): array
    {
        $defaultId = (int) Policy::get('default_shift_id', '');
        $today     = self::factoryToday();
        $rows      = Shifts::all(false);
        foreach ($rows as &$s) {
            $s['is_default']  = (int) $s['id'] === $defaultId;
            $s['days_ahead']  = (int) Db::value(
                'SELECT COUNT(*) FROM att_shift_instances WHERE shift_id = ? AND work_date >= ?', [(int) $s['id'], $today]);
        }
        unset($s);
        usort($rows, static fn($a, $b) => [(int) $b['active'], (string) $a['start_time']] <=> [(int) $a['active'], (string) $b['start_time']]);
        return ['ok' => true, 'shifts' => $rows, 'default_shift_id' => $defaultId ?: null];
    }

    /** @param array<string,mixed> $data */
    public static function saveShift(?int $id, array $data, int $actorId): array
    {
        $name  = self::str($data['name'] ?? '');
        $start = self::hhmm(self::str($data['start_time'] ?? ''));
        $end   = self::hhmm(self::str($data['end_time'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            return self::no('VALIDATION', $name === '' ? 'Give the shift a name.' : 'A shift name can be at most 100 characters.');
        }
        if ($start === null || $end === null) {
            return self::no('VALIDATION', 'Give the start and end as times, for example 08:00 and 17:00.');
        }
        if ($start === $end) {
            return self::no('VALIDATION', 'The start and end are the same time. A shift needs a length.');
        }
        $ints = [];
        foreach (['break_minutes' => 600, 'grace_in_minutes' => 240, 'grace_out_minutes' => 240] as $k => $max) {
            $v = $data[$k] ?? 0;
            if (!self::isWholeNumber($v) || (int) $v < 0 || (int) $v > $max) {
                return self::no('VALIDATION', ucfirst(str_replace('_', ' ', $k)) . " must be a whole number from 0 to $max.");
            }
            $ints[$k] = (int) $v;
        }

        $clash = Db::one('SELECT id FROM att_shifts WHERE name = ?', [$name]);
        if ($clash !== null && (int) $clash['id'] !== (int) $id) {
            return self::no('NAME_TAKEN', "There is already a shift called \"$name\".");
        }

        if ($id === null) {
            $newId = Shifts::create(['name' => $name, 'start_time' => $start, 'end_time' => $end] + $ints, $actorId);
            return ['ok' => true, 'shift_id' => $newId, 'days_recalculated' => 0];
        }

        $before = Shifts::find($id);
        if ($before === null) {
            return self::no('NOT_FOUND', 'That shift no longer exists.');
        }
        $active = array_key_exists('active', $data) ? (int) (bool) $data['active'] : (int) $before['active'];

        if ($active === 0 && (int) Policy::get('default_shift_id', '') === $id) {
            return self::no('DEFAULT_SHIFT', 'This is the default shift. Choose another default in Settings before switching it off.');
        }

        $timesChanged = substr((string) $before['start_time'], 0, 5) !== substr($start, 0, 5)
                     || substr((string) $before['end_time'], 0, 5) !== substr($end, 0, 5);

        return Db::tx(static function () use ($id, $name, $start, $end, $ints, $active, $before, $timesChanged, $actorId): array {
            Db::run(
                'UPDATE att_shifts SET name = ?, start_time = ?, end_time = ?, break_minutes = ?,
                        grace_in_minutes = ?, grace_out_minutes = ?, active = ? WHERE id = ?',
                [$name, $start, $end, $ints['break_minutes'], $ints['grace_in_minutes'], $ints['grace_out_minutes'], $active, $id]
            );

            /* A shift day is stored as two fixed instants, worked out when it
               was assigned. Days that have not started and have nothing
               recorded against them are moved to the new times; today, the
               past, and any day with an entry on it keep the times that were
               true when the work was done. */
            $moved = 0;
            if ($timesChanged) {
                $shift = Shifts::find($id);
                $days = Db::all(
                    'SELECT si.id, si.work_date FROM att_shift_instances si
                      WHERE si.shift_id = ? AND si.work_date > ?
                        AND NOT EXISTS (SELECT 1 FROM att_entry_cycles c WHERE c.shift_instance_id = si.id)',
                    [$id, self::factoryToday()]
                );
                foreach ($days as $d) {
                    $w = Shifts::resolveWindow($shift, (string) $d['work_date']);
                    Db::run('UPDATE att_shift_instances SET starts_at = ?, ends_at = ? WHERE id = ?',
                        [Time::sql($w['starts_at']), Time::sql($w['ends_at']), (int) $d['id']]);
                    $moved++;
                }
            }

            Audit::record('shift.update', 'att_shift', $id,
                self::only($before, ['name', 'start_time', 'end_time', 'break_minutes', 'grace_in_minutes', 'grace_out_minutes', 'active']),
                ['name' => $name, 'start_time' => $start, 'end_time' => $end, 'active' => $active] + $ints
                    + ['days_recalculated' => $moved],
                $actorId);

            return ['ok' => true, 'shift_id' => $id, 'days_recalculated' => $moved];
        });
    }

    /**
     * Give a shift to a department, or to named employees, for a run of days.
     *
     * @param list<int> $weekdays  ISO weekdays to include, 1 = Monday … 7 = Sunday. Empty = every day.
     * @param list<int> $employeeIds
     */
    public static function assignRange(
        int $shiftId, string $from, string $to, array $weekdays,
        int $departmentId, array $employeeIds, int $actorId
    ): array {
        $shift = Shifts::find($shiftId);
        if ($shift === null || (int) $shift['active'] !== 1) {
            return self::no('VALIDATION', 'Choose an active shift.');
        }
        $d1 = self::date($from);
        $d2 = self::date($to);
        if ($d1 === null || $d2 === null) {
            return self::no('VALIDATION', 'Give the first and last day.');
        }
        if ($d2 < $d1) {
            return self::no('VALIDATION', 'The last day is before the first day.');
        }
        $span = (int) $d1->diff($d2)->days + 1;
        if ($span > self::ASSIGN_MAX_DAYS) {
            return self::no('VALIDATION', 'At most ' . self::ASSIGN_MAX_DAYS . ' days at a time.');
        }
        $today = new DateTimeImmutable(self::factoryToday(), new DateTimeZone('UTC'));
        if ($d1 < $today->modify('-31 days')) {
            return self::no('VALIDATION', 'The first day is more than 31 days in the past.');
        }

        $weekdays = array_values(array_unique(array_filter(array_map('intval', $weekdays), static fn($n) => $n >= 1 && $n <= 7)));

        if ($departmentId > 0) {
            $ids = array_map('intval', array_column(
                Db::all('SELECT id FROM att_employees WHERE department_id = ? AND active = 1', [$departmentId]), 'id'));
        } else {
            $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds), static fn($n) => $n > 0)));
            if ($ids !== []) {
                $ids = array_map('intval', array_column(Db::all(
                    'SELECT id FROM att_employees WHERE active = 1 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids), 'id'));
            }
        }
        if ($ids === []) {
            return self::no('VALIDATION', 'There are no active employees to give the shift to.');
        }

        $dates = [];
        for ($d = $d1; $d <= $d2; $d = $d->modify('+1 day')) {
            if ($weekdays === [] || in_array((int) $d->format('N'), $weekdays, true)) {
                $dates[] = $d->format('Y-m-d');
            }
        }
        if ($dates === []) {
            return self::no('VALIDATION', 'None of the chosen weekdays fall between those two days.');
        }
        if (count($dates) * count($ids) > self::ASSIGN_MAX_ROWS) {
            return self::no('VALIDATION', 'That is ' . (count($dates) * count($ids)) . ' shift days in one go. Choose fewer days and repeat.');
        }

        return Db::tx(static function () use ($shift, $shiftId, $dates, $ids, $departmentId, $actorId, $from, $to, $weekdays): array {
            $set = 0;
            $kept = 0;
            foreach ($dates as $date) {
                $w = Shifts::resolveWindow($shift, $date);
                foreach (array_chunk($ids, 500) as $chunk) {
                    $in = implode(',', array_fill(0, count($chunk), '?'));

                    /* A day with entries already recorded keeps the shift
                       those entries were filed under. */
                    $locked = array_map('intval', array_column(Db::all(
                        "SELECT DISTINCT si.employee_id FROM att_shift_instances si
                           JOIN att_entry_cycles c ON c.shift_instance_id = si.id
                          WHERE si.work_date = ? AND si.employee_id IN ($in)",
                        array_merge([$date], $chunk)), 'employee_id'));
                    $kept += count($locked);
                    $free  = array_values(array_diff($chunk, $locked));
                    if ($free === []) {
                        continue;
                    }

                    $values = [];
                    $params = [];
                    foreach ($free as $empId) {
                        $values[] = '(?, ?, ?, ?, ?, \'assigned\', ?)';
                        array_push($params, $empId, $shiftId, $date, Time::sql($w['starts_at']), Time::sql($w['ends_at']), $actorId);
                    }
                    Db::run(
                        'INSERT INTO att_shift_instances (employee_id, shift_id, work_date, starts_at, ends_at, source, created_by)
                         VALUES ' . implode(',', $values) . '
                         ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id), starts_at = VALUES(starts_at),
                                                 ends_at = VALUES(ends_at), source = VALUES(source)',
                        $params
                    );
                    $set += count($free);
                }
            }

            // One audit line for the whole instruction, not one per person per day.
            Audit::record('shift.assign_range', 'att_shift', $shiftId, null, [
                'from' => $from, 'to' => $to, 'weekdays' => $weekdays, 'department_id' => $departmentId ?: null,
                'employees' => count($ids), 'days' => count($dates), 'shift_days_set' => $set, 'kept_with_entries' => $kept,
            ], $actorId);

            return ['ok' => true, 'employees' => count($ids), 'days' => count($dates),
                    'shift_days_set' => $set, 'kept_with_entries' => $kept];
        });
    }

    // ------------------------------------------------------------------- people

    /**
     * Every active login, the attendance jobs each holds, and whether each
     * job actually works — a job is only any use with its User Access
     * permission, and this is the one place both are shown side by side.
     */
    public static function users(): array
    {
        $users = Db::all('SELECT id, name, email, role FROM users WHERE is_active = 1 ORDER BY name, id');

        $roles = [];
        foreach (Db::all(
            'SELECT r.user_id, r.role, r.department_id, d.name AS department_name
               FROM att_user_roles r LEFT JOIN att_departments d ON d.id = r.department_id
              ORDER BY r.role, d.name'
        ) as $r) {
            $roles[(int) $r['user_id']][] = $r;
        }

        $perms = [];
        if (Db::tableExists('zu_perm')) {
            foreach (Db::all("SELECT user_id, module, actions FROM zu_perm WHERE module LIKE 'att%'") as $p) {
                $perms[(int) $p['user_id']][(string) $p['module']] = (string) $p['actions'];
            }
        }

        $out = [];
        foreach ($users as $u) {
            $uid     = (int) $u['id'];
            $isAdmin = ($u['role'] ?? '') === 'admin';
            $jobs    = [];
            foreach ($roles[$uid] ?? [] as $r) {
                $job = self::JOBS[(string) $r['role']] ?? null;
                if ($job === null) {
                    continue; // the bypass 'admin' role is not managed on this screen
                }
                $have    = $perms[$uid][$job['module']] ?? '';
                $working = $isAdmin || self::hasAll($have, $job['needs']);
                if ($job['needs_department'] && $r['department_id'] === null) {
                    $working = false; // a HOD of no department confirms nobody
                }
                $jobs[] = [
                    'role'            => (string) $r['role'],
                    'label'           => $job['label'],
                    'department_id'   => $r['department_id'] === null ? null : (int) $r['department_id'],
                    'department_name' => $r['department_name'],
                    'working'         => $working,
                    'missing'         => $working ? null : self::moduleName($job['module']),
                ];
            }
            $out[] = ['id' => $uid, 'name' => (string) $u['name'], 'email' => (string) $u['email'],
                      'is_admin' => $isAdmin, 'jobs' => $jobs];
        }

        $jobList = [];
        foreach (self::JOBS as $role => $j) {
            $jobList[] = ['role' => $role, 'label' => $j['label'], 'needs_department' => $j['needs_department']];
        }
        return ['ok' => true, 'users' => $out, 'jobs' => $jobList];
    }

    public static function grantJob(int $userId, string $role, int $departmentId, int $actorId): array
    {
        $job = self::JOBS[$role] ?? null;
        if ($job === null) {
            return self::no('VALIDATION', 'Choose a job.');
        }
        $user = Db::one('SELECT id, role FROM users WHERE id = ? AND is_active = 1', [$userId]);
        if ($user === null) {
            return self::no('NOT_FOUND', 'That login does not exist or is switched off.');
        }
        $dept = null;
        if ($job['needs_department']) {
            $d = Db::one('SELECT id, active FROM att_departments WHERE id = ?', [$departmentId]);
            if ($d === null || (int) $d['active'] !== 1) {
                return self::no('VALIDATION', 'A HOD is the HOD of a department. Choose which one.');
            }
            $dept = (int) $d['id'];
        }

        Roles::grant($userId, $role, $dept, $actorId);
        $synced = self::syncPermission($userId, (string) $user['role'], $role, true, $actorId);

        return ['ok' => true, 'permission_synced' => $synced,
                'note' => $synced ? null
                    : 'The job is saved, but its permission could not be ticked automatically. Tick "'
                      . self::moduleName($job['module']) . '" for this person in User Access.'];
    }

    public static function revokeJob(int $userId, string $role, int $departmentId, int $actorId): array
    {
        $job = self::JOBS[$role] ?? null;
        if ($job === null) {
            return self::no('VALIDATION', 'Choose a job.');
        }
        $user = Db::one('SELECT id, role FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return self::no('NOT_FOUND', 'That login does not exist.');
        }
        Roles::revoke($userId, $role, $departmentId > 0 ? $departmentId : null, $actorId);

        /* Only when the LAST row for the job has gone: a HOD of two
           departments who loses one is still a HOD. */
        $synced = true;
        if (!Roles::has($userId, $role)) {
            $synced = self::syncPermission($userId, (string) $user['role'], $role, false, $actorId);
        }
        return ['ok' => true, 'permission_synced' => $synced,
                'note' => $synced ? null
                    : 'The job is removed, but its permission could not be un-ticked automatically. Un-tick "'
                      . self::moduleName($job['module']) . '" for this person in User Access — until then the access is still there.'];
    }

    /**
     * Tick or un-tick the User Access permission that goes with a job,
     * through the ERP's own save routine so its legacy columns stay in step.
     * Returns false when that could not be done, so the screen can say so
     * instead of implying the access changed.
     */
    private static function syncPermission(int $userId, string $erpRole, string $role, bool $grant, int $actorId): bool
    {
        if ($erpRole === 'admin') {
            return true; // admins hold every permission; there is nothing to tick
        }
        if (!function_exists('zu_perm_map') || !function_exists('zu_save_perms')) {
            return false;
        }
        $job = self::JOBS[$role];
        try {
            $map     = \zu_perm_map($userId, true);
            $changed = false;
            foreach ([$job['module'] => $job['actions']] + ($job['also'] ?? []) as $module => $actions) {
                $have = (string) ($map[$module] ?? '');
                $want = '';
                if ($grant) {
                    $want = $have;
                    foreach (str_split($actions) as $a) {
                        if (strpos($want, $a) === false) { $want .= $a; }
                    }
                }
                if ($want !== $have) {
                    $map[$module] = $want;
                    $changed = true;
                }
            }
            if (!$changed) {
                return true;
            }
            $res = \zu_save_perms($userId, $map, $actorId);
            return (bool) ($res['ok'] ?? false);
        } catch (\Throwable $e) {
            error_log('[att-admin] permission sync failed: ' . $e->getMessage());
            return false;
        }
    }

    // ------------------------------------------------------------------ phones

    /** Give a phone a name people recognise — "Main gate", "Unit 2 back gate". */
    public static function renameDevice(int $id, string $label, int $actorId): array
    {
        $label = self::clean($label);
        if ($label === '' || mb_strlen($label) > 100) {
            return self::no('VALIDATION', $label === '' ? 'Give the phone a name.' : 'A phone name can be at most 100 characters.');
        }
        $d = Db::one('SELECT id, label FROM att_devices WHERE id = ?', [$id]);
        if ($d === null) {
            return self::no('NOT_FOUND', 'That phone is not registered.');
        }
        Db::run('UPDATE att_devices SET label = ? WHERE id = ?', [$label, $id]);
        Audit::record('device.rename', 'att_device', $id, ['label' => $d['label']], ['label' => $label], $actorId);
        return ['ok' => true, 'device_id' => $id, 'label' => $label];
    }

    // ----------------------------------------------------------------- settings

    public static function settings(): array
    {
        $all = Policy::all();
        $out = [];
        foreach (self::SETTINGS as $key => $meta) {
            $raw = (string) ($all[$key] ?? '');
            $value = match ($meta['type']) {
                'bool'  => in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true),
                'int'   => !empty($meta['unit_ms']) ? (int) round(((int) $raw) / 1000) : (int) $raw,
                'shift' => ((int) $raw) ?: null,
            };
            $row = ['key' => $key, 'type' => $meta['type'], 'label' => $meta['label'], 'help' => $meta['help'], 'value' => $value];
            if ($meta['type'] === 'int') {
                $div = !empty($meta['unit_ms']) ? 1000 : 1;
                $row['min'] = intdiv($meta['min'], $div);
                $row['max'] = intdiv($meta['max'], $div);
            }
            $out[] = $row;
        }
        return [
            'ok' => true, 'settings' => $out,
            'fixed' => [['label' => 'Same employee, same action, refused within',
                         'value' => Policy::int('duplicate_window_seconds') . ' seconds',
                         'help'  => 'Part of the approved attendance rules. Not changed from this screen.']],
            'shifts' => Db::all('SELECT id, name FROM att_shifts WHERE active = 1 ORDER BY start_time, name'),
        ];
    }

    /** @param array<string,mixed> $values */
    public static function saveSettings(array $values, int $actorId): array
    {
        $clean = [];
        foreach ($values as $key => $v) {
            $meta = self::SETTINGS[(string) $key] ?? null;
            if ($meta === null) {
                return self::no('VALIDATION', "\"$key\" is not a setting that can be changed here.");
            }
            switch ($meta['type']) {
                case 'bool':
                    $clean[$key] = [in_array($v, [true, 1, '1', 'true', 'on'], true) ? '1' : '0', 'bool'];
                    break;
                case 'int':
                    if (!self::isWholeNumber($v)) {
                        return self::no('VALIDATION', $meta['label'] . ': give a whole number.');
                    }
                    $n = (int) $v * (!empty($meta['unit_ms']) ? 1000 : 1);
                    if ($n < $meta['min'] || $n > $meta['max']) {
                        $div = !empty($meta['unit_ms']) ? 1000 : 1;
                        return self::no('VALIDATION', $meta['label'] . ': must be from ' . intdiv($meta['min'], $div) . ' to ' . intdiv($meta['max'], $div) . '.');
                    }
                    $clean[$key] = [(string) $n, 'int'];
                    break;
                case 'shift':
                    if ($v === null || $v === '' || (int) $v === 0) {
                        $clean[$key] = ['', 'string'];
                        break;
                    }
                    $s = Shifts::find((int) $v);
                    if ($s === null || (int) $s['active'] !== 1) {
                        return self::no('VALIDATION', 'Choose an active shift as the default.');
                    }
                    $clean[$key] = [(string) (int) $v, 'string'];
                    break;
            }
        }
        if ($clean === []) {
            return self::no('VALIDATION', 'Nothing to save.');
        }

        $changed = 0;
        Db::tx(static function () use ($clean, $actorId, &$changed): void {
            foreach ($clean as $key => [$value, $type]) {
                if (Policy::get($key) !== $value) {
                    Policy::set($key, $value, $actorId, $type);
                    $changed++;
                }
            }
        });
        return ['ok' => true, 'changed' => $changed];
    }

    // ------------------------------------------------------------------ helpers

    /** Request text, cleaned. Anything that is not text or a number reads as empty. */
    private static function str(mixed $v): string
    {
        return is_scalar($v) ? self::clean((string) $v) : '';
    }

    /** Collapse runs of white space and drop control characters. */
    private static function clean(string $s): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    private static function key(string $s): string
    {
        return mb_strtoupper(self::clean($s));
    }

    private static function like(string $s): string
    {
        return addcslashes($s, '\\%_');
    }

    private static function codeProblem(string $code): ?string
    {
        if ($code === '') {
            return 'No employee code.';
        }
        if (mb_strlen($code) > 40) {
            return 'Employee code is longer than 40 characters.';
        }
        // A code is typed on a phone keypad and printed on a card. A space
        // inside one cannot be seen on either.
        if (preg_match('/\s/u', $code)) {
            return 'An employee code cannot contain a space.';
        }
        return null;
    }

    private static function hhmm(string $t): ?string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($t), $m)) {
            return null;
        }
        if ((int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }
        return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }

    private static function date(string $d): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return null;
        }
        $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d, new DateTimeZone('UTC'));
        return ($x !== false && $x->format('Y-m-d') === $d) ? $x : null;
    }

    private static function isWholeNumber(mixed $v): bool
    {
        return is_int($v) || (is_string($v) && preg_match('/^\d{1,9}$/', $v) === 1);
    }

    /** Today's date where the factory is, which is the date a shift is filed under. */
    private static function factoryToday(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
    }

    private static function hasHistory(int $employeeId): bool
    {
        foreach (['att_events', 'att_entry_cycles', 'att_hod_arrivals'] as $t) {
            if (Db::tableExists($t) && Db::one("SELECT 1 AS x FROM $t WHERE employee_id = ? LIMIT 1", [$employeeId]) !== null) {
                return true;
            }
        }
        return false;
    }

    private static function hasAll(string $have, string $need): bool
    {
        foreach (str_split($need) as $a) {
            if (strpos($have, $a) === false) {
                return false;
            }
        }
        return true;
    }

    private static function moduleName(string $key): string
    {
        foreach (Roles::MODULES as $m) {
            if ($m['k'] === $key) {
                return $m['n'];
            }
        }
        return $key;
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private static function only(array $row, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $row[$k] ?? null;
        }
        return $out;
    }

    private static function no(string $code, string $message): array
    {
        return ['ok' => false, 'reason_code' => $code, 'error' => $message];
    }
}
