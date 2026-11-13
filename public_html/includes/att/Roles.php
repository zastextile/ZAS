<?php
declare(strict_types=1);

namespace Att;

/**
 * Who may do what, and over which department.
 *
 * Blueprint §6: "server-side role and department authorization on every
 * endpoint". Two separate questions live here:
 *
 *   MAY THIS PERSON DO THIS AT ALL   — answered by zu_perm, the module/action
 *                                      system the ERP already has. Admin
 *                                      bypasses it, exactly as everywhere else
 *                                      in this app.
 *   OVER WHOSE PEOPLE                — answered by att_user_roles. A HOD is
 *                                      scoped to a department and may confirm
 *                                      arrivals only for that department
 *                                      (AT06). HR, Accounts and Admin are
 *                                      normally unscoped.
 *
 * Both are enforced server-side on every request. Department scope is pushed
 * into the SQL of each query rather than filtered afterwards in PHP, so a
 * forgotten UI check cannot widen it.
 */
final class Roles
{
    public const GATEKEEPER = 'gatekeeper';
    public const HOD        = 'hod';
    public const HR         = 'hr';
    public const ACCOUNTS   = 'accounts';
    public const ADMIN      = 'admin';
    public const AUDIT      = 'audit';      // People Finance: approves pay sheets (migration 0017)

    public const ALL = [self::GATEKEEPER, self::HOD, self::HR, self::ACCOUNTS, self::ADMIN, self::AUDIT];

    /** zu_perm modules this module registers. Mirrors §5 of the plan. */
    public const MODULES = [
        ['g' => 'Attendance', 'k' => 'attgate',  'n' => 'Attendance — Gate IN/OUT',              'a' => 'vc'],
        ['g' => 'Attendance', 'k' => 'atthod',   'n' => 'Attendance — HOD Confirmation',         'a' => 'vc'],
        ['g' => 'Attendance', 'k' => 'attflag',  'n' => 'Attendance — Flag & Photo',             'a' => 'vc'],
        ['g' => 'Attendance', 'k' => 'attot',    'n' => 'Overtime — Request',                    'a' => 'vcu'],
        ['g' => 'Attendance', 'k' => 'attotap',  'n' => 'Overtime — Approve / Reject',           'a' => 'vu'],
        ['g' => 'Attendance', 'k' => 'attotvf',  'n' => 'Overtime — Verify Actual Hours',        'a' => 'vu'],
        ['g' => 'Attendance', 'k' => 'attpay',   'n' => 'Overtime — Rate & Payroll',             'a' => 'vu'],
        ['g' => 'Attendance', 'k' => 'atthr',    'n' => 'Attendance — Reconciliation',           'a' => 'vud'],
        ['g' => 'Attendance', 'k' => 'attrep',   'n' => 'Attendance — Reports',                  'a' => 'v'],
        ['g' => 'Attendance', 'k' => 'attadm',   'n' => 'Attendance — Employees, Shifts, Devices','a' => 'vcud'],
    ];

    /** @return list<array{role:string,department_id:?int}> */
    public static function forUser(int $userId): array
    {
        $rows = Db::all(
            'SELECT role, department_id FROM att_user_roles WHERE user_id = ? ORDER BY role',
            [$userId]
        );
        return array_map(
            static fn(array $r) => [
                'role' => (string) $r['role'],
                'department_id' => $r['department_id'] === null ? null : (int) $r['department_id'],
            ],
            $rows
        );
    }

    public static function has(int $userId, string $role): bool
    {
        foreach (self::forUser($userId) as $r) {
            if ($r['role'] === $role) {
                return true;
            }
        }
        return false;
    }

    public static function grant(int $userId, string $role, ?int $departmentId = null, ?int $actorId = null): void
    {
        if (!in_array($role, self::ALL, true)) {
            throw new \InvalidArgumentException("Unknown attendance role: $role");
        }
        try {
            Db::run(
                'INSERT INTO att_user_roles (user_id, role, department_id) VALUES (?, ?, ?)',
                [$userId, $role, $departmentId]
            );
        } catch (\Throwable $e) {
            if (Db::isDuplicate($e)) {
                return; // already granted; granting twice is not an error
            }
            throw $e;
        }
        Audit::record('role.grant', 'att_user_role', $userId, null,
            ['role' => $role, 'department_id' => $departmentId], $actorId);
    }

    public static function revoke(int $userId, string $role, ?int $departmentId = null, ?int $actorId = null): void
    {
        Db::run(
            'DELETE FROM att_user_roles WHERE user_id = ? AND role = ? AND COALESCE(department_id,0) = ?',
            [$userId, $role, $departmentId ?? 0]
        );
        Audit::record('role.revoke', 'att_user_role', $userId,
            ['role' => $role, 'department_id' => $departmentId], null, $actorId);
    }

    /**
     * Which departments this user may act over for a given role.
     *
     * Returns null for "all departments" (HR/Accounts/Admin), or a list of ids.
     * An empty list means the user holds the role with no department — which
     * grants nothing, and is deliberately not treated as "everything".
     *
     * @return list<int>|null
     */
    public static function departmentScope(int $userId, string $role): ?array
    {
        if (self::isAdmin($userId)) {
            return null;
        }
        $ids = [];
        $holdsUnscoped = false;
        foreach (self::forUser($userId) as $r) {
            if ($r['role'] !== $role) {
                continue;
            }
            if ($r['department_id'] === null) {
                $holdsUnscoped = true;
            } else {
                $ids[] = $r['department_id'];
            }
        }
        // HR/Accounts/Admin are unscoped by nature; a HOD holding an unscoped
        // row is a configuration mistake, so it is NOT read as "all".
        if ($holdsUnscoped && in_array($role, [self::HR, self::ACCOUNTS, self::ADMIN], true)) {
            return null;
        }
        return $ids;
    }

    public static function isAdmin(int $userId): bool
    {
        if (function_exists('current_user')) {
            $u = \current_user();
            if (is_array($u) && (int) ($u['id'] ?? 0) === $userId && ($u['role'] ?? '') === 'admin') {
                return true;
            }
        }
        return self::has($userId, self::ADMIN);
    }

    /**
     * May this user touch this department's people, in this role?
     * Used as the guard in front of every HOD query (AT06).
     */
    public static function mayActOnDepartment(int $userId, string $role, int $departmentId): bool
    {
        $scope = self::departmentScope($userId, $role);
        if ($scope === null) {
            return true;
        }
        return in_array($departmentId, $scope, true);
    }

    /**
     * Permission check through the ERP's own system.
     *
     * CALL IT FOR THE CURRENT USER, WITH NO ID. zu_can() has two different
     * behaviours depending on that argument, and the difference is easy to
     * miss by reading it quickly:
     *
     *     zu_can($m, $a)        → admin bypasses; the access window is
     *                             enforced; then the permission letter.
     *     zu_can($m, $a, $id)   → RAW letter lookup only. No admin bypass,
     *                             no window check.
     *
     * An earlier draft of this module passed the session user's id
     * explicitly, which looked tidier and silently denied every admin — the
     * API test caught it. The explicit-id form is kept here only for asking
     * about somebody OTHER than the person making the request.
     *
     * FAILS CLOSED when zu_can() is absent: on a half-uploaded includes/
     * directory the right answer to "may this person approve overtime" is no.
     * CLI is exempt so the module stays testable outside the app.
     */
    public static function can(string $module, string $action, ?int $userId = null): bool
    {
        if (function_exists('zu_can')) {
            return $userId === null
                ? \zu_can($module, $action)
                : \zu_can($module, $action, $userId);
        }
        return PHP_SAPI === 'cli';
    }
}
