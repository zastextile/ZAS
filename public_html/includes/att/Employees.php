<?php
declare(strict_types=1);

namespace Att;

/**
 * The attendance roll: departments and employees.
 *
 * D2: this is a wider list than zp_workers, which holds only the people on the
 * machines. worker_code is the optional bridge between the two, so production
 * and attendance can agree on one person without either table owning the
 * other, and the external HR-token sync that maintains zp_workers keeps
 * working untouched.
 */
final class Employees
{
    // ------------------------------------------------------------ departments

    public static function createDepartment(string $name, ?int $actorId = null): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Department name is required.');
        }
        Db::run('INSERT INTO att_departments (name) VALUES (?)', [$name]);
        $id = Db::lastId();
        Audit::record('department.create', 'att_department', $id, null, ['name' => $name], $actorId);
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public static function departments(bool $activeOnly = true): array
    {
        return Db::all(
            'SELECT id, name, active FROM att_departments'
            . ($activeOnly ? ' WHERE active = 1' : '')
            . ' ORDER BY name'
        );
    }

    public static function findDepartmentByName(string $name): ?array
    {
        return Db::one('SELECT id, name, active FROM att_departments WHERE name = ?', [trim($name)]);
    }

    /**
     * Seed attendance departments from the inventory master, once.
     *
     * Deliberately a copy rather than a foreign key into inv_departments: an
     * edit made for an inventory reason must not silently change which
     * employees a HOD can confirm. Existing rows are never overwritten.
     */
    public static function seedDepartmentsFromInventory(?int $actorId = null): int
    {
        if (!Db::tableExists('inv_departments')) {
            return 0;
        }
        $added = 0;
        foreach (Db::all('SELECT name FROM inv_departments WHERE is_active = 1 ORDER BY name') as $row) {
            $name = trim((string) $row['name']);
            if ($name === '' || self::findDepartmentByName($name) !== null) {
                continue;
            }
            self::createDepartment($name, $actorId);
            $added++;
        }
        return $added;
    }

    // -------------------------------------------------------------- employees

    /** @param array<string,mixed> $data */
    public static function create(array $data, ?int $actorId = null): int
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['full_name'] ?? ''));
        $deptId = (int) ($data['department_id'] ?? 0);

        if ($code === '')  { throw new \InvalidArgumentException('Employee code is required.'); }
        if ($name === '')  { throw new \InvalidArgumentException('Employee name is required.'); }
        if ($deptId <= 0)  { throw new \InvalidArgumentException('Department is required.'); }

        $workerCode = trim((string) ($data['worker_code'] ?? ''));
        $qr         = trim((string) ($data['qr_payload'] ?? ''));

        try {
            Db::run(
                'INSERT INTO att_employees (code, full_name, department_id, worker_code, qr_payload, active)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $code,
                    $name,
                    $deptId,
                    $workerCode !== '' ? $workerCode : null,
                    $qr !== '' ? $qr : null,
                    isset($data['active']) ? (int) (bool) $data['active'] : 1,
                ]
            );
        } catch (\Throwable $e) {
            if (Db::isDuplicate($e)) {
                throw new \RuntimeException("Employee code '$code' is already in use.", 409, $e);
            }
            throw $e;
        }

        $id = Db::lastId();
        Audit::record('employee.create', 'att_employee', $id, null,
            ['code' => $code, 'full_name' => $name, 'department_id' => $deptId], $actorId);

        self::linkWorker($id, $workerCode);
        return $id;
    }

    /** Write the bridge back onto zp_workers, when that table and code exist. */
    private static function linkWorker(int $employeeId, string $workerCode): void
    {
        if ($workerCode === '' || !Db::tableExists('zp_workers') || !Db::columnExists('zp_workers', 'att_employee_id')) {
            return;
        }
        Db::run('UPDATE zp_workers SET att_employee_id = ? WHERE worker_code = ?', [$employeeId, $workerCode]);
    }

    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT e.*, d.name AS department_name
               FROM att_employees e JOIN att_departments d ON d.id = e.department_id
              WHERE e.id = ?',
            [$id]
        );
    }

    public static function findByCode(string $code): ?array
    {
        return Db::one(
            'SELECT e.*, d.name AS department_name
               FROM att_employees e JOIN att_departments d ON d.id = e.department_id
              WHERE e.code = ?',
            [trim($code)]
        );
    }

    /**
     * Look up by scanned QR payload or typed code.
     * Both entry methods the blueprint requires land here.
     */
    public static function findByCodeOrQr(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return Db::one(
            'SELECT e.*, d.name AS department_name
               FROM att_employees e JOIN att_departments d ON d.id = e.department_id
              WHERE e.code = ? OR e.qr_payload = ?
              LIMIT 1',
            [$value, $value]
        );
    }

    /**
     * Search for the gate/HOD picker.
     *
     * $departmentIds is the caller's department scope (null = all). It is
     * applied in SQL, not after the fact, so a HOD cannot see another
     * department's people even if the screen asks for them.
     *
     * @param list<int>|null $departmentIds
     * @return list<array<string,mixed>>
     */
    public static function search(string $query, ?array $departmentIds = null, int $limit = 25): array
    {
        $limit  = max(1, min(100, $limit));
        $params = [];
        $sql = 'SELECT e.id, e.code, e.full_name, e.department_id, d.name AS department_name, e.active
                  FROM att_employees e
                  JOIN att_departments d ON d.id = e.department_id
                 WHERE e.active = 1';

        $query = trim($query);
        if ($query !== '') {
            $sql .= ' AND (e.code = ? OR e.full_name LIKE ? OR e.qr_payload = ?)';
            $params[] = $query;
            $params[] = '%' . $query . '%';
            $params[] = $query;
        }

        if ($departmentIds !== null) {
            if ($departmentIds === []) {
                return []; // scoped to nothing: see nothing
            }
            $sql .= ' AND e.department_id IN (' . implode(',', array_fill(0, count($departmentIds), '?')) . ')';
            foreach ($departmentIds as $d) {
                $params[] = (int) $d;
            }
        }

        $sql .= ' ORDER BY e.full_name LIMIT ' . $limit;
        return Db::all($sql, $params);
    }

    /**
     * The offline lookup cache for a gate/HOD device.
     *
     * Blueprint §3: "Cache application shell and permitted employee lookup
     * data". Only the fields the device needs to show a name against a scanned
     * code — no photo key, nothing private. Blueprint §6 asks for a minimal
     * local cache and this is what that means in practice.
     *
     * @param list<int>|null $departmentIds
     */
    public static function syncSnapshot(?array $departmentIds = null): array
    {
        $params = [];
        /* has_photo is a yes/no for the phone to know whether asking for the
           picture is worth a request. The picture's whereabouts never leave
           the server. */
        $sql = 'SELECT e.id, e.code, e.full_name, e.department_id, e.qr_payload,
                       (e.reference_photo_key IS NOT NULL) AS has_photo
                  FROM att_employees e
                 WHERE e.active = 1';
        if ($departmentIds !== null) {
            if ($departmentIds === []) {
                return ['employees' => [], 'generated_at' => Time::iso(Time::nowUtc())];
            }
            $sql .= ' AND e.department_id IN (' . implode(',', array_fill(0, count($departmentIds), '?')) . ')';
            foreach ($departmentIds as $d) {
                $params[] = (int) $d;
            }
        }
        $sql .= ' ORDER BY e.code';

        return [
            'employees'    => Db::all($sql, $params),
            'generated_at' => Time::iso(Time::nowUtc()),
        ];
    }

    public static function deactivate(int $id, ?int $actorId = null, ?string $reason = null): void
    {
        $before = self::find($id);
        if ($before === null) {
            throw new \RuntimeException('Employee not found.', 404);
        }
        Db::run('UPDATE att_employees SET active = 0 WHERE id = ?', [$id]);
        Audit::record('employee.deactivate', 'att_employee', $id,
            ['active' => (int) $before['active']], ['active' => 0], $actorId, $reason);
    }
}
