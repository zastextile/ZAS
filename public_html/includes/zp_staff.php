<?php
/*
  PRODUCTION STAFF LOGINS — who typed it, who they are, which floor
  =================================================================
  Owner, 28 Nov 2026 ("1 + 2 + 3"):

  1. ENTERED BY. Every entry already records the login that typed it
     (zp_entries.created_by). Reports now show it, filter by it and group by it.

  2. A LOGIN BELONGS TO A PERSON. users.att_employee_id links a login to the
     person in People Finance — the one list of people. Reports show that
     person's real name, and when People Finance marks the person as left the
     login stops working (includes/auth.php, login.php). Marked back as
     working, it works again: nothing is deleted.

  3. A FLOOR PER PRODUCTION STAFF LOGIN. users.prod_depts holds the production
     departments a Production Staff login may work on ("STITCHING|PACKING").
     Empty = every floor. Daily Entry offers only those workers and the server
     refuses a booking for anybody else; Dashboard and Reports show only those
     floors. Admin and colleagues are never limited.

  The two columns are added here, once, by themselves — no SQL step to run.
  A page that reads them before they exist falls back to "no link, no limit".
*/

function zps_ensure(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('zas_schema_fresh') && zas_schema_fresh('zps', [__FILE__])) return;
    try { db()->exec("ALTER TABLE users ADD COLUMN att_employee_id BIGINT UNSIGNED NULL DEFAULT NULL"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE users ADD COLUMN prod_depts VARCHAR(500) NULL DEFAULT NULL"); } catch (Throwable $e) {}
    if (function_exists('zas_schema_mark')) zas_schema_mark('zps', [__FILE__]);
}

function zps_norm(string $d): string {
    $d = trim($d);
    return $d === '' ? '(NO DEPARTMENT)' : mb_strtoupper($d);
}

/* The floors this login is limited to, normalised. [] means every floor. */
function zps_depts(?array $u = null): array {
    $u = $u ?? current_user();
    if (!$u || ($u['role'] ?? '') !== 'production_staff') return [];
    $raw = trim((string)($u['prod_depts'] ?? ''));
    if ($raw === '') return [];
    $o = [];
    foreach (explode('|', $raw) as $d) if (trim($d) !== '') $o[zps_norm($d)] = true;
    return array_keys($o);
}

/* May this login book / see this worker's department? */
function zps_dept_ok(array $depts, ?string $workerDept): bool {
    return !$depts || in_array(zps_norm((string)$workerDept), $depts, true);
}

/* The production floors there are, for the Users page. */
function zps_floor_list(): array {
    $o = [];
    try {
        foreach (db()->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(department),''),'') d FROM zp_workers WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN) as $d)
            $o[zps_norm((string)$d)] = $d === '' ? '(no department)' : (string)$d;
    } catch (Throwable $e) {}
    ksort($o);
    return $o;
}

/* People Finance, for the Users page: [id => [code, name, dept, active]] */
function zps_people(): array {
    $o = [];
    try {
        foreach (db()->query("SELECT e.id, e.code, e.full_name, e.active, d.name dept
                              FROM att_employees e LEFT JOIN att_departments d ON d.id = e.department_id
                              ORDER BY e.active DESC, e.full_name")->fetchAll() as $r)
            $o[(int)$r['id']] = [(string)$r['code'], (string)$r['full_name'], (string)($r['dept'] ?? ''), (int)$r['active'] === 1];
    } catch (Throwable $e) {}
    return $o;
}

/* Save the link and the floors for one login (Users page). Only known people
   and known floors are stored; floors only for a Production Staff login. */
function zps_save_user(int $userId, string $role, int $empId, array $floors): string {
    zps_ensure();
    if ($userId <= 0) return '';
    $people = zps_people();
    if ($empId > 0 && !isset($people[$empId])) return 'That person is not in People Finance.';
    $known = zps_floor_list(); $keep = [];
    if ($role === 'production_staff')
        foreach ($floors as $f) { $k = zps_norm((string)$f); if (isset($known[$k])) $keep[$k] = $known[$k]; }
    try {
        db()->prepare("UPDATE users SET att_employee_id=?, prod_depts=? WHERE id=?")
            ->execute([$empId > 0 ? $empId : null, $keep ? implode('|', array_values($keep)) : null, $userId]);
    } catch (Throwable $e) { return 'Could not save the People Finance link: ' . $e->getMessage(); }
    if (function_exists('cache_bump')) cache_bump('users');
    return '';
}

/* [worker id => department], every worker ever (history included). */
function zps_worker_dept_map(): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    try { foreach (db()->query("SELECT id, department FROM zp_workers")->fetchAll() as $r) $m[(int)$r['id']] = (string)($r['department'] ?? ''); } catch (Throwable $e) {}
    return $m;
}

/* THE SERVER'S CHECK — the page's list is a convenience, this is the rule.
   '' when every worker named is on this login's floors; else the refusal. */
function zps_refuse_workers(array $workerIds, array $floors): string {
    if (!$floors) return '';
    $map = zps_worker_dept_map(); $bad = [];
    foreach ($workerIds as $w) {
        $w = (int)$w; if ($w <= 0) continue;
        if (!zps_dept_ok($floors, $map[$w] ?? '')) $bad[$w] = $w;
    }
    if (!$bad) return '';
    $names = [];
    try {
        $in = implode(',', array_fill(0, count($bad), '?'));
        $st = db()->prepare("SELECT worker_name FROM zp_workers WHERE id IN ($in)"); $st->execute(array_values($bad));
        $names = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}
    return 'Your login works on ' . implode(', ', $floors) . ' only. Not on your floor: ' . implode(', ', array_slice($names ?: array_values($bad), 0, 6)) . '. Nothing was saved.';
}

/* the workers of some booked entries (for cancel / edit) */
function zps_entry_workers(array $entryIds): array {
    $ids = array_values(array_filter(array_map('intval', $entryIds), fn($x) => $x > 0));
    if (!$ids) return [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT DISTINCT worker_id FROM zp_entries WHERE id IN ($in)"); $st->execute($ids);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}
