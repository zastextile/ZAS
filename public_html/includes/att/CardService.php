<?php
declare(strict_types=1);

namespace Att;

/**
 * EMPLOYEE CARDS (owner, 23 Nov 2026) — People Finance › Cards.
 *
 * A card is linked by TAPPING it on a keyboard-type 125 kHz reader. The page
 * turns the digits into a fingerprint (lowercase hex SHA-256) in the browser
 * before anything is sent, so the card number never reaches the server, a
 * table, a screen, an export or a log. Everything here works on fingerprints.
 *
 * One card per person, one person per card (UNIQUE). Every change — linked,
 * replaced, unlinked, canteen on/off — is a row in att_card_log with who did
 * it, and replacing or unlinking needs a reason.
 *
 * The canteen app asks check() on every tap through a private, key-protected
 * address: is this card linked, to whom, and may that person buy (on the roll
 * and the Canteen switch on)?
 *
 * Until `php att_migrate.php up` has made the tables, every call answers
 * NOT_READY and nothing else in the app is touched.
 */
final class CardService
{
    public static function ready(): bool
    {
        static $ready = null;
        return $ready ??= Db::tableExists('att_emp_cards') && Db::tableExists('att_card_log');
    }

    private static function notReady(): array
    {
        return self::no('NOT_READY', 'Cards are not set up on the server yet. Run the database step once: php att_migrate.php up');
    }

    public static function isHash(string $h): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $h);
    }

    /**
     * Everybody this user may see, with their card state. Never a fingerprint.
     * @param ?array<int,int> $departments null = everyone
     */
    public static function people(?array $departments): array
    {
        if (!self::ready()) {
            return self::notReady();
        }
        [$w, $p] = self::deptWhere($departments);
        $rows = Db::all(
            "SELECT e.id AS employee_id, e.code, e.full_name, e.active, d.name AS department_name, f.pay_type,
                    (c.card_hash IS NOT NULL) AS has_card, c.linked_at, COALESCE(c.canteen_ok, 0) AS canteen_ok
               FROM att_employees e
               JOIN att_departments d ON d.id = e.department_id
               LEFT JOIN att_fin_profiles f ON f.employee_id = e.id
               LEFT JOIN att_emp_cards c ON c.employee_id = e.id
              WHERE 1 = 1 $w
              ORDER BY e.active DESC, d.name, e.full_name LIMIT 5000", $p);
        foreach ($rows as &$r) {
            $r['employee_id'] = (int) $r['employee_id']; $r['active'] = (int) $r['active'];
            $r['has_card'] = (int) $r['has_card']; $r['canteen_ok'] = (int) $r['canteen_ok'];
            $r['linked_at'] = self::day($r['linked_at']);
        }
        unset($r);
        return ['ok' => true, 'people' => $rows];
    }

    /** Link a tapped card to a person. A person who already has one needs replace + a reason. */
    public static function link(int $employeeId, string $hash, bool $replace, string $reason, ?array $departments, int $actorId): array
    {
        if (!self::ready()) {
            return self::notReady();
        }
        if (!self::isHash($hash)) {
            return self::no('VALIDATION', 'That was not read from a card. Tap the card on the reader.');
        }
        $reason = mb_substr(trim($reason), 0, 255);
        return Db::tx(static function () use ($employeeId, $hash, $replace, $reason, $departments, $actorId) {
            $e = Db::one('SELECT id, full_name, active, department_id FROM att_employees WHERE id = ? FOR UPDATE', [$employeeId]);
            if ($e === null || !self::inScope($departments, (int) $e['department_id'])) {
                return self::no('NOT_FOUND', 'No such employee.');
            }
            if ((int) $e['active'] !== 1) {
                return self::no('VALIDATION', $e['full_name'] . ' has left. A card is not linked to someone who has left.');
            }
            $owner = Db::one('SELECT c.employee_id, e.full_name, e.code FROM att_emp_cards c JOIN att_employees e ON e.id = c.employee_id WHERE c.card_hash = ? FOR UPDATE', [$hash]);
            if ($owner !== null) {
                return (int) $owner['employee_id'] === $employeeId
                    ? self::no('SAME_CARD', 'That card is already linked to ' . $e['full_name'] . '.')
                    : self::no('CARD_TAKEN', 'That card is already linked to ' . $owner['full_name'] . ' (' . $owner['code'] . '). Unlink it there first.');
            }
            $cur = Db::one('SELECT card_hash FROM att_emp_cards WHERE employee_id = ? FOR UPDATE', [$employeeId]);
            $old = $cur['card_hash'] ?? null;
            if ($old !== null && !$replace) {
                return self::no('HAS_CARD', $e['full_name'] . ' already has a card. Use Replace to give a new one.');
            }
            if ($old !== null && $reason === '') {
                return self::no('REASON_REQUIRED', 'Say why the card is replaced (lost, broken, …). The reason is kept.');
            }
            Db::run(
                'INSERT INTO att_emp_cards (employee_id, card_hash, linked_at, updated_by) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE card_hash = VALUES(card_hash), linked_at = VALUES(linked_at), updated_by = VALUES(updated_by)',
                [$employeeId, $hash, Time::sql(Time::nowUtc()), $actorId]
            );
            self::log($employeeId, $old !== null ? 'replace' : 'link', $old, $hash, $reason ?: null, $actorId);
            return ['ok' => true, 'employee_id' => $employeeId, 'replaced' => $old !== null];
        });
    }

    public static function unlink(int $employeeId, string $reason, ?array $departments, int $actorId): array
    {
        if (!self::ready()) {
            return self::notReady();
        }
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            return self::no('REASON_REQUIRED', 'Say why the card is unlinked (left, lost, …). The reason is kept.');
        }
        return Db::tx(static function () use ($employeeId, $reason, $departments, $actorId) {
            $e = Db::one('SELECT id, department_id FROM att_employees WHERE id = ?', [$employeeId]);
            if ($e === null || !self::inScope($departments, (int) $e['department_id'])) {
                return self::no('NOT_FOUND', 'No such employee.');
            }
            $cur = Db::one('SELECT card_hash FROM att_emp_cards WHERE employee_id = ? FOR UPDATE', [$employeeId]);
            if ($cur === null || $cur['card_hash'] === null) {
                return self::no('NO_CARD', 'There is no card to unlink.');
            }
            Db::run('UPDATE att_emp_cards SET card_hash = NULL, linked_at = NULL, updated_by = ? WHERE employee_id = ?', [$actorId, $employeeId]);
            self::log($employeeId, 'unlink', (string) $cur['card_hash'], null, $reason, $actorId);
            return ['ok' => true, 'employee_id' => $employeeId];
        });
    }

    /** Canteen on / off for one person or many. @param array<int,int> $ids */
    public static function canteen(array $ids, bool $on, ?array $departments, int $actorId): array
    {
        if (!self::ready()) {
            return self::notReady();
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids || count($ids) > 1000) {
            return self::no('VALIDATION', $ids ? 'At most 1,000 people at a time.' : 'Choose at least one person.');
        }
        $done = 0;
        Db::tx(static function () use ($ids, $on, $departments, $actorId, &$done) {
            foreach ($ids as $id) {
                $e = Db::one('SELECT id, department_id FROM att_employees WHERE id = ?', [$id]);
                if ($e === null || !self::inScope($departments, (int) $e['department_id'])) {
                    continue;
                }
                $was = (int) (Db::value('SELECT canteen_ok FROM att_emp_cards WHERE employee_id = ?', [$id]) ?? 0);
                if ($was === ($on ? 1 : 0)) {
                    continue;
                }
                Db::run('INSERT INTO att_emp_cards (employee_id, canteen_ok, updated_by) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE canteen_ok = VALUES(canteen_ok), updated_by = VALUES(updated_by)', [$id, $on ? 1 : 0, $actorId]);
                self::log($id, $on ? 'canteen_on' : 'canteen_off', null, null, null, $actorId);
                $done++;
            }
        });
        return ['ok' => true, 'changed' => $done];
    }

    /** Whose card is this? For the page's "Who has this card?". */
    public static function who(string $hash, ?array $departments): array
    {
        if (!self::ready()) {
            return self::notReady();
        }
        if (!self::isHash($hash)) {
            return self::no('VALIDATION', 'That was not read from a card. Tap the card on the reader.');
        }
        $r = Db::one('SELECT e.id AS employee_id, e.code, e.full_name, e.active, e.department_id, d.name AS department_name, c.linked_at, c.canteen_ok
                         FROM att_emp_cards c JOIN att_employees e ON e.id = c.employee_id JOIN att_departments d ON d.id = e.department_id
                        WHERE c.card_hash = ?', [$hash]);
        if ($r === null) {
            return ['ok' => true, 'found' => false];
        }
        if (!self::inScope($departments, (int) $r['department_id'])) {
            return ['ok' => true, 'found' => true, 'hidden' => true];
        }
        return ['ok' => true, 'found' => true, 'employee_id' => (int) $r['employee_id'], 'code' => $r['code'], 'full_name' => $r['full_name'],
                'department_name' => $r['department_name'], 'active' => (int) $r['active'], 'canteen_ok' => (int) $r['canteen_ok'],
                'linked_at' => self::day($r['linked_at'])];
    }

    /**
     * THE CANTEEN'S QUESTION, on every tap: one indexed lookup. Answers who it
     * is and whether they may buy; nothing about pay, nothing else.
     */
    public static function check(string $hash): array
    {
        if (!self::ready()) {
            return self::notReady();
        }
        if (!self::isHash($hash)) {
            return self::no('VALIDATION', 'Send the card fingerprint: 64 lowercase hex characters.');
        }
        $r = Db::one('SELECT e.id AS employee_id, e.code, e.full_name, e.active, d.name AS department_name, c.canteen_ok
                         FROM att_emp_cards c JOIN att_employees e ON e.id = c.employee_id JOIN att_departments d ON d.id = e.department_id
                        WHERE c.card_hash = ?', [$hash]);
        if ($r === null) {
            return ['ok' => true, 'allowed' => false, 'reason' => 'unknown_card', 'message' => 'This card is not linked to anyone.'];
        }
        $out = ['ok' => true, 'employee_id' => (int) $r['employee_id'], 'code' => $r['code'], 'name' => $r['full_name'], 'department' => $r['department_name']];
        if ((int) $r['active'] !== 1) {
            return $out + ['allowed' => false, 'reason' => 'left', 'message' => $r['full_name'] . ' has left.'];
        }
        if ((int) $r['canteen_ok'] !== 1) {
            return $out + ['allowed' => false, 'reason' => 'canteen_off', 'message' => 'Canteen is not switched on for ' . $r['full_name'] . '.'];
        }
        return $out + ['allowed' => true, 'reason' => null, 'message' => null];
    }

    /** A stored UTC time as the factory's (Pakistan) date. */
    private static function day(mixed $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        return (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Karachi'))->format('Y-m-d');
    }

    private static function log(int $employeeId, string $action, ?string $old, ?string $new, ?string $reason, int $actorId): void
    {
        Db::run('INSERT INTO att_card_log (employee_id, action, old_hash, new_hash, reason, actor_id) VALUES (?,?,?,?,?,?)',
            [$employeeId, $action, $old, $new, $reason, $actorId]);
        Audit::record('card.' . $action, 'att_employee', $employeeId, null, null, $actorId, $reason);
    }

    private static function inScope(?array $departments, int $departmentId): bool
    {
        return $departments === null || in_array($departmentId, array_map('intval', $departments), true);
    }

    private static function deptWhere(?array $departments): array
    {
        if ($departments === null) {
            return ['', []];
        }
        if ($departments === []) {
            return [' AND 1 = 0', []];
        }
        return [' AND e.department_id IN (' . implode(',', array_fill(0, count($departments), '?')) . ')', array_map('intval', $departments)];
    }

    private static function no(string $code, string $message): array
    {
        return ['ok' => false, 'reason_code' => $code, 'error' => $message];
    }
}
