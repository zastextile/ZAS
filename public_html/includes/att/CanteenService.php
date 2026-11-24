<?php
declare(strict_types=1);

namespace Att;

use DateTimeImmutable;

/**
 * CANTEEN CHARGES ON PAY (owner, 24 Nov 2026).
 *
 * The canteen is a separate Cloudways application with its own database. It
 * offers four private addresses (POST, HTTPS, JSON, header X-Link-Key):
 *
 *   charges  {up_to}                        unsettled orders + refunds, read only
 *   reserve  {sheet_ref, order_ids, refund_ids}   hold them for one pay sheet
 *   settle   {sheet_ref}                    the sheet is final
 *   release  {sheet_ref}                    the sheet went back: let them go
 *
 * HOW A CHARGE TRAVELS
 *   1. "Get canteen charges" (Prepare pay) copies what the canteen says is owed
 *      into att_canteen_rows. Nothing is held at the canteen yet.
 *   2. Working out a person's pay adds ONE line "Canteen 13–19 Nov · 1 Biryani,
 *      6 Tea (7 orders)", whole orders oldest first, never more than the pay
 *      there is; what does not fit simply waits for the next sheet.
 *   3. Sending the sheet for approval takes those rows onto it and asks the
 *      canteen to RESERVE them on the sheet's ref. Anything the canteen refuses
 *      comes off the line at once, so the sheet never charges what is not held.
 *   4. Approve → settle. Send back, cancel, or everyone taken off → release.
 *      Those two messages wait in att_canteen_calls until the canteen answers.
 *
 * The canteen is never trusted beyond what it is asked: amounts are whole
 * paisa, every employee id must be ours, an order's items must add up to its
 * total. If the canteen cannot be reached, nothing is charged and nothing is
 * lost: the rows come again next time.
 *
 * Until `php att_migrate.php up` has made the tables, ready() is false and the
 * pay sheet works exactly as before.
 */
final class CanteenService
{
    private const LINE_KEY = 'canteen';

    /** For the tests: a stand-in for the HTTPS call. fn(string $action, array $body): array{status:int, body:string} */
    private static $transport = null;

    public static function setTransport(?callable $fn): void
    {
        self::$transport = $fn;
    }

    public static function ready(): bool
    {
        static $ready = null;
        return $ready ??= Db::tableExists('att_canteen_rows') && Db::tableExists('att_canteen_calls');
    }

    /** Is the link to the canteen filled in on this server? */
    public static function configured(): bool
    {
        return self::url() !== null && strlen(self::key()) >= 32;
    }

    // ------------------------------------------------------ 1. get charges

    /**
     * Ask the canteen what is owed up to the Thursday on or before $to, and
     * keep it. Returns a short summary for the page.
     */
    public static function fetch(string $to, int $actorId): array
    {
        if (!self::ready()) {
            return self::no('NOT_READY', 'The canteen link is not set up on the server yet. Run the database step once: php att_migrate.php up');
        }
        if (!self::configured()) {
            return self::no('NOT_SET_UP', 'The canteen link is not switched on. Put canteen_url and canteen_charges_key in the server config.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return self::no('VALIDATION', 'Give the last day of the pay sheet.');
        }
        $upTo = self::thursdayOnOrBefore(min($to, self::today()));
        self::flush();                                     // settle / release first, so the list is current
        $r = self::post('charges', ['up_to' => $upTo]);
        if (!$r['ok']) {
            return $r;
        }
        $a = $r['data'];
        if (!isset($a['orders'], $a['refunds']) || !is_array($a['orders']) || !is_array($a['refunds'])) {
            return self::no('BAD_ANSWER', 'The canteen answered, but not with orders and refunds.');
        }
        $known = [];
        foreach (Db::all('SELECT id FROM att_employees') as $e) { $known[(int) $e['id']] = true; }
        $orders = []; $refunds = []; $skipped = [];
        foreach ($a['orders'] as $o) {
            $row = self::cleanOrder($o, $known);
            if (is_string($row)) { $skipped[] = $row; continue; }
            $orders[$row['ext_id']] = $row;
        }
        foreach ($a['refunds'] as $f) {
            $row = self::cleanRefund($f, $known);
            if (is_string($row)) { $skipped[] = $row; continue; }
            $refunds[$row['ext_id']] = $row;
        }
        Db::tx(static function () use ($orders, $refunds, $upTo) {
            foreach (['order' => $orders, 'refund' => $refunds] as $kind => $rows) {
                foreach ($rows as $x) {
                    $cur = Db::one('SELECT id, zas_state FROM att_canteen_rows WHERE kind = ? AND ext_id = ? FOR UPDATE', [$kind, $x['ext_id']]);
                    if ($cur === null) {
                        Db::run('INSERT INTO att_canteen_rows (kind, ext_id, employee_id, at, week_from, amount_paisa, items, order_ext_id, note) VALUES (?,?,?,?,?,?,?,?,?)',
                            [$kind, $x['ext_id'], $x['employee_id'], $x['at'], $x['week_from'], $x['amount'], $x['items'], $x['order_ext_id'], $x['note']]);
                    } elseif (in_array($cur['zas_state'], ['free', 'gone'], true)) {
                        // The canteen says it is unsettled and nobody here has it: take the canteen's word.
                        Db::run("UPDATE att_canteen_rows SET employee_id = ?, at = ?, week_from = ?, amount_paisa = ?, items = ?, order_ext_id = ?, note = ?,
                                        zas_state = 'free', sheet_id = NULL, canteen_state = 'unsettled', canteen_ref = NULL, fetched_at = CURRENT_TIMESTAMP(6) WHERE id = ?",
                            [$x['employee_id'], $x['at'], $x['week_from'], $x['amount'], $x['items'], $x['order_ext_id'], $x['note'], (int) $cur['id']]);
                    }
                    // on a sheet here: left alone; reserve will tell.
                }
                // Unsettled and waiting here, but no longer listed (cancelled at the counter): set aside.
                $ids = array_keys($rows);
                $sql = "UPDATE att_canteen_rows SET zas_state = 'gone' WHERE kind = ? AND zas_state = 'free' AND canteen_state = 'unsettled' AND DATE(at) <= ?";
                $p = [$kind, $upTo];
                if ($ids) { $sql .= ' AND ext_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $p = array_merge($p, $ids); }
                Db::run($sql, $p);
            }
        });
        $people = []; $total = 0;
        foreach ($orders as $o) { $people[$o['employee_id']] = true; $total += $o['amount']; }
        foreach ($refunds as $f) { $people[$f['employee_id']] = true; $total -= $f['amount']; }
        Audit::record('canteen.fetch', 'att_canteen', 0, null, ['up_to' => $upTo, 'orders' => count($orders), 'refunds' => count($refunds), 'total_paisa' => $total, 'skipped' => count($skipped)], $actorId);
        return ['ok' => true, 'up_to' => $upTo, 'orders' => count($orders), 'refunds' => count($refunds), 'people' => count($people),
                'total_paisa' => $total, 'skipped' => array_slice($skipped, 0, 20), 'skipped_count' => count($skipped), 'pending_calls' => self::pendingCount()];
    }

    /** @return array<string,mixed>|string a clean row, or why it was left out */
    private static function cleanOrder(mixed $o, array $known): array|string
    {
        if (!is_array($o)) { return 'An order that is not a record.'; }
        $id = $o['order_id'] ?? null; $emp = $o['employee_id'] ?? null; $total = $o['total'] ?? null;
        if (!self::isId($id)) { return 'An order without a proper order_id.'; }
        if (!self::isId($emp) || !isset($known[(int) $emp])) { return "Order $id: employee " . (is_scalar($emp) ? (string) $emp : '?') . ' is not in ZAS.'; }
        if (!self::isPaisa($total) || (int) $total <= 0) { return "Order $id: the total is not whole paisa."; }
        $at = self::dt($o['ordered_at'] ?? null);
        if ($at === null) { return "Order $id: no proper ordered_at."; }
        $items = []; $sum = 0;
        foreach ((array) ($o['items'] ?? []) as $it) {
            if (!is_array($it) || !self::isPaisa($it['amount'] ?? null) || !is_int($it['qty'] ?? null) || (int) $it['qty'] <= 0) {
                return "Order $id: an item without a whole quantity and amount.";
            }
            $name = trim(preg_replace('/\s+/u', ' ', (string) ($it['name'] ?? '')) ?? '');
            $items[] = ['name' => mb_substr($name === '' ? 'item' : $name, 0, 60), 'qty' => (int) $it['qty'], 'amount' => (int) $it['amount']];
            $sum += (int) $it['amount'];
        }
        if ($items === [] || $sum !== (int) $total) {
            return "Order $id: the items do not add up to the total.";
        }
        $week = self::isDay($o['week_from'] ?? null) ? (string) $o['week_from'] : self::fridayOnOrBefore(substr($at, 0, 10));
        return ['ext_id' => (int) $id, 'employee_id' => (int) $emp, 'at' => $at, 'week_from' => $week, 'amount' => (int) $total,
                'items' => json_encode($items, JSON_UNESCAPED_UNICODE), 'order_ext_id' => null, 'note' => null];
    }

    private static function cleanRefund(mixed $f, array $known): array|string
    {
        if (!is_array($f)) { return 'A refund that is not a record.'; }
        $id = $f['refund_id'] ?? null; $emp = $f['employee_id'] ?? null; $amt = $f['amount'] ?? null;
        if (!self::isId($id)) { return 'A refund without a proper refund_id.'; }
        if (!self::isId($emp) || !isset($known[(int) $emp])) { return "Refund $id: employee " . (is_scalar($emp) ? (string) $emp : '?') . ' is not in ZAS.'; }
        if (!self::isPaisa($amt) || (int) $amt <= 0) { return "Refund $id: the amount is not whole paisa."; }
        $at = self::dt($f['created_at'] ?? null);
        if ($at === null) { return "Refund $id: no proper created_at."; }
        return ['ext_id' => (int) $id, 'employee_id' => (int) $emp, 'at' => $at, 'week_from' => self::fridayOnOrBefore(substr($at, 0, 10)), 'amount' => (int) $amt,
                'items' => null, 'order_ext_id' => self::isId($f['order_id'] ?? null) ? (int) $f['order_id'] : null,
                'note' => mb_substr(trim((string) ($f['reason'] ?? '')), 0, 255) ?: null];
    }

    // --------------------------------------------- 2. the line on the sheet

    /**
     * The canteen line for one person, up to $to, never more than $room.
     * Whole orders, oldest first; refunds net them. Null = no line.
     *
     * @return ?array{amount:int,label:string,detail:string,ids:list<int>}
     */
    public static function lineFor(int $employeeId, string $to, int $room): ?array
    {
        if (!self::ready() || $room <= 0) {
            return null;
        }
        $rows = Db::all("SELECT id, kind, at, week_from, amount_paisa, items FROM att_canteen_rows
                          WHERE employee_id = ? AND zas_state = 'free' AND canteen_state IN ('unsettled','settled') AND DATE(at) <= ?
                          ORDER BY at, id", [$employeeId, $to]);
        $orders = array_values(array_filter($rows, static fn($r) => $r['kind'] === 'order'));
        $refunds = array_values(array_filter($rows, static fn($r) => $r['kind'] === 'refund'));
        if ($orders === []) {
            return null;                                   // a refund alone waits for something to net against
        }
        $allRefunds = array_sum(array_map(static fn($r) => (int) $r['amount_paisa'], $refunds));
        $take = []; $o = 0;
        foreach ($orders as $r) {
            if ($o + (int) $r['amount_paisa'] - $allRefunds > $room) { break; }
            $take[] = $r; $o += (int) $r['amount_paisa'];
        }
        $refTake = []; $f = 0;
        foreach ($refunds as $r) {
            if ($f + (int) $r['amount_paisa'] > $o) { break; }
            $refTake[] = $r; $f += (int) $r['amount_paisa'];
        }
        while ($take !== [] && $o - $f > $room) {               // refunds that could not be used: give back the newest order
            $o -= (int) array_pop($take)['amount_paisa'];
            while ($refTake !== [] && $f > $o) { $f -= (int) array_pop($refTake)['amount_paisa']; }
        }
        if ($take === [] || $o - $f <= 0) {
            return null;
        }
        return self::describe($take, $refTake) + ['ids' => array_map(static fn($r) => (int) $r['id'], array_merge($take, $refTake))];
    }

    /** "Canteen 13–19 Nov · 1 Biryani, 6 Tea (7 orders)" and its detail. */
    private static function describe(array $orders, array $refunds): array
    {
        $dish = [];
        $weeks = [];
        $o = 0; $f = 0;
        foreach ($orders as $r) {
            $o += (int) $r['amount_paisa'];
            $weeks[] = (string) ($r['week_from'] ?: self::fridayOnOrBefore(substr((string) $r['at'], 0, 10)));
            foreach ((array) json_decode((string) $r['items'], true) as $it) {
                $n = (string) ($it['name'] ?? 'item');
                $dish[$n] = ($dish[$n] ?? 0) + (int) ($it['qty'] ?? 0);
            }
        }
        foreach ($refunds as $r) { $f += (int) $r['amount_paisa']; }
        $a = new DateTimeImmutable(min($weeks));
        $b = (new DateTimeImmutable(max($weeks)))->modify('+6 days');
        $span = $a->format('n') === $b->format('n') ? $a->format('j') . '–' . $b->format('j M') : $a->format('j M') . '–' . $b->format('j M');
        $n = count($orders);
        $tail = ' (' . $n . ($n === 1 ? ' order)' : ' orders)');
        $head = 'Canteen ' . $span . ' · ';
        $parts = [];
        foreach ($dish as $name => $q) {
            // An Urdu name is wrapped in Unicode isolate marks, so "1 بریانی, 6 چائے" keeps
            // its order on a left-to-right page instead of being flipped around the numbers.
            $parts[] = $q . ' ' . (preg_match('/\p{Arabic}/u', (string) $name) ? "\u{2068}" . $name . "\u{2069}" : $name);
        }
        $list = implode(', ', $parts);
        $room = 160 - mb_strlen($head) - mb_strlen($tail);
        if (mb_strlen($list) > $room) { $list = rtrim(mb_substr($list, 0, $room - 1), ' ,') . '…'; }
        $detail = 'from the canteen · ' . $n . ($n === 1 ? ' order ' : ' orders ') . self::rupees($o);
        if ($refunds) { $detail .= ' − ' . count($refunds) . (count($refunds) === 1 ? ' refund ' : ' refunds ') . self::rupees($f); }
        return ['amount' => $o - $f, 'label' => $head . $list . $tail, 'detail' => $detail];
    }

    /** Inside createSheet's transaction: are these rows all still free? Locks them. */
    public static function stillFree(array $ids): bool
    {
        if ($ids === []) {
            return true;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $got = Db::all("SELECT id FROM att_canteen_rows WHERE id IN ($in) AND zas_state = 'free' AND canteen_state IN ('unsettled','settled') FOR UPDATE", $ids);
        return count($got) === count(array_unique($ids));
    }

    /** Inside createSheet's transaction: the rows now belong to this sheet. */
    public static function putOnSheet(int $sheetId, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        Db::run("UPDATE att_canteen_rows SET zas_state = 'on_sheet', sheet_id = ? WHERE id IN ($in) AND zas_state = 'free'", array_merge([$sheetId], $ids));
    }

    // ------------------------------------------- 3. reserve, right after the sheet is made

    /**
     * Ask the canteen to hold this sheet's rows. What it refuses, or everything
     * if it cannot be reached, comes off the sheet's canteen lines straight
     * away. Returns a sentence for the preparer when something came off.
     */
    public static function reserveSheet(int $sheetId): ?string
    {
        if (!self::ready()) {
            return null;
        }
        $ref = (string) Db::value('SELECT ref FROM att_fin_sheets WHERE id = ?', [$sheetId]);
        $rows = Db::all("SELECT id, kind, ext_id, employee_id FROM att_canteen_rows WHERE sheet_id = ? AND zas_state = 'on_sheet' AND canteen_state = 'unsettled'", [$sheetId]);
        if ($ref === '' || $rows === []) {
            return null;
        }
        $orders = []; $refunds = [];
        foreach ($rows as $r) { if ($r['kind'] === 'order') { $orders[] = (int) $r['ext_id']; } else { $refunds[] = (int) $r['ext_id']; } }
        $r = self::configured() ? self::post('reserve', ['sheet_ref' => $ref, 'order_ids' => $orders, 'refund_ids' => $refunds])
                                : self::no('NOT_SET_UP', 'The canteen link is not switched on.');
        $refused = null;
        if ($r['ok']) {
            $a = $r['data'];
            $ro = self::intList($a['refused']['order_ids'] ?? []); $rf = self::intList($a['refused']['refund_ids'] ?? []);
            $sum = static fn(string $k): int => (int) ($a['reserved'][$k] ?? -1) + (int) ($a['already'][$k] ?? -1);
            $fits = $ro !== null && $rf !== null && ($a['sheet_ref'] ?? $ref) === $ref
                && !array_diff($ro, $orders) && !array_diff($rf, $refunds)
                && $sum('orders') + count($ro) === count($orders) && $sum('refunds') + count($rf) === count($refunds);
            if ($fits) {
                $refused = ['order' => $ro, 'refund' => $rf];
            }
        }
        return Db::tx(static function () use ($sheetId, $ref, $rows, $refused) {
            $off = [];
            foreach ($rows as $row) {
                $kind = (string) $row['kind'];
                if ($refused !== null && !in_array((int) $row['ext_id'], $refused[$kind], true)) {
                    Db::run("UPDATE att_canteen_rows SET canteen_state = 'reserved', canteen_ref = ? WHERE id = ?", [$ref, (int) $row['id']]);
                    continue;
                }
                if ($refused !== null) {
                    // Cancelled, already settled, or on another sheet: not ours to charge. A later fetch tells the truth.
                    Db::run("UPDATE att_canteen_rows SET zas_state = 'gone', sheet_id = NULL WHERE id = ?", [(int) $row['id']]);
                } else {
                    // No proper answer: we do not know what the canteen did. Hold the row back until a release is confirmed.
                    Db::run("UPDATE att_canteen_rows SET zas_state = 'free', sheet_id = NULL, canteen_state = 'reserved', canteen_ref = ? WHERE id = ?", [$ref, (int) $row['id']]);
                }
                $off[(int) $row['employee_id']] = true;
            }
            if ($refused === null) {
                self::queue('release', $ref, $sheetId);
            }
            foreach (array_keys($off) as $emp) {
                self::redoLine($sheetId, $emp);
            }
            if ($off === []) {
                return null;
            }
            return $refused === null
                ? 'The canteen could not be reached, so its charges were taken off this sheet (' . count($off) . (count($off) === 1 ? ' person' : ' people') . '). They will come on a later sheet.'
                : 'The canteen no longer holds some orders (cancelled or already charged), so they were taken off for ' . count($off) . (count($off) === 1 ? ' person.' : ' people.');
        });
    }

    /** Rebuild one person's canteen line on a waiting sheet from the rows still on it. */
    private static function redoLine(int $sheetId, int $employeeId): void
    {
        $item = Db::one("SELECT i.id FROM att_fin_sheet_items i JOIN att_fin_sheets s ON s.id = i.sheet_id
                          WHERE i.sheet_id = ? AND i.employee_id = ? AND s.status = 'waiting' FOR UPDATE", [$sheetId, $employeeId]);
        if ($item === null) {
            return;
        }
        $itemId = (int) $item['id'];
        $rows = Db::all("SELECT id, kind, at, week_from, amount_paisa, items FROM att_canteen_rows WHERE sheet_id = ? AND employee_id = ? AND zas_state = 'on_sheet' ORDER BY at, id", [$sheetId, $employeeId]);
        $orders = array_values(array_filter($rows, static fn($r) => $r['kind'] === 'order'));
        $refunds = array_values(array_filter($rows, static fn($r) => $r['kind'] === 'refund'));
        $d = $orders ? self::describe($orders, $refunds) : null;
        if ($d === null || $d['amount'] <= 0) {
            // Nothing left to charge: what remains (a refund) waits for the next sheet.
            Db::run("UPDATE att_canteen_rows SET zas_state = 'free', sheet_id = NULL WHERE sheet_id = ? AND employee_id = ? AND zas_state = 'on_sheet'", [$sheetId, $employeeId]);
            Db::run('DELETE FROM att_fin_item_lines WHERE item_id = ? AND line_key = ?', [$itemId, self::LINE_KEY]);
        } else {
            Db::run('UPDATE att_fin_item_lines SET label = ?, detail = ?, calc_paisa = ?, amount_paisa = ? WHERE item_id = ? AND line_key = ?',
                [$d['label'], $d['detail'], $d['amount'], $d['amount'], $itemId, self::LINE_KEY]);
        }
        $net = 0; $calc = 0;
        foreach (Db::all('SELECT side, calc_paisa, amount_paisa FROM att_fin_item_lines WHERE item_id = ?', [$itemId]) as $l) {
            $s = $l['side'] === 'deduct' ? -1 : 1;
            $net += $s * (int) $l['amount_paisa']; $calc += $s * (int) $l['calc_paisa'];
        }
        Db::run('UPDATE att_fin_sheet_items SET amount_paisa = ?, calc_paisa = ? WHERE id = ?', [$net, $calc, $itemId]);
    }

    // ------------------------------------------ 4. what happens to the sheet

    /** Inside approve's transaction. */
    public static function onApprove(int $sheetId, string $ref): void
    {
        if (!self::ready()) {
            return;
        }
        Db::run("UPDATE att_canteen_rows SET zas_state = 'charged' WHERE sheet_id = ? AND zas_state = 'on_sheet'", [$sheetId]);
        if (self::holds($ref)) {
            self::queue('settle', $ref, $sheetId);
        }
    }

    /** Inside sendBack's / cancelSheet's transaction: the rows wait for another sheet; the canteen lets them go. */
    public static function onClose(int $sheetId, string $ref): void
    {
        if (!self::ready()) {
            return;
        }
        Db::run("UPDATE att_canteen_rows SET zas_state = 'free', sheet_id = NULL WHERE sheet_id = ? AND zas_state IN ('on_sheet','charged')", [$sheetId]);
        if (self::holds($ref)) {
            self::queue('release', $ref, $sheetId);
        }
    }

    /**
     * Inside removeFromSheet's transaction: this person's rows wait for another
     * sheet. The canteen keeps them on this sheet's ref until the sheet is
     * settled (then they are owed again here) or released (then they come back
     * in the next charges).
     */
    public static function onRemove(int $sheetId, string $ref, int $employeeId, bool $sheetClosed): void
    {
        if (!self::ready()) {
            return;
        }
        Db::run("UPDATE att_canteen_rows SET zas_state = 'free', sheet_id = NULL WHERE sheet_id = ? AND employee_id = ? AND zas_state IN ('on_sheet','charged')", [$sheetId, $employeeId]);
        if ($sheetClosed) {
            self::onClose($sheetId, $ref);
        }
    }

    private static function holds(string $ref): bool
    {
        return (bool) Db::value("SELECT 1 FROM att_canteen_rows WHERE canteen_ref = ? AND canteen_state = 'reserved' LIMIT 1", [$ref]);
    }

    private static function queue(string $action, string $ref, ?int $sheetId): void
    {
        $dup = Db::value("SELECT 1 FROM att_canteen_calls WHERE action = ? AND sheet_ref = ? AND status = 'pending' LIMIT 1", [$action, $ref]);
        if (!$dup) {
            Db::run('INSERT INTO att_canteen_calls (action, sheet_ref, sheet_id) VALUES (?,?,?)', [$action, $ref, $sheetId]);
        }
    }

    public static function pendingCount(): int
    {
        return self::ready() ? (int) Db::value("SELECT COUNT(*) FROM att_canteen_calls WHERE status = 'pending'") : 0;
    }

    /**
     * Send the waiting settle / release messages, oldest first. Stops at the
     * first one the canteen does not answer, so a down canteen costs one try.
     */
    public static function flush(int $max = 20): array
    {
        $sent = 0;
        if (!self::ready() || !self::configured()) {
            return ['sent' => 0, 'pending' => self::pendingCount()];
        }
        foreach (Db::all("SELECT id, action, sheet_ref FROM att_canteen_calls WHERE status = 'pending' ORDER BY id LIMIT " . max(1, min(100, $max))) as $c) {
            $r = self::post((string) $c['action'], ['sheet_ref' => (string) $c['sheet_ref']]);
            $key = $c['action'] === 'settle' ? 'settled' : 'released';
            if (!$r['ok'] || !isset($r['data'][$key]) || !is_array($r['data'][$key])) {
                Db::run('UPDATE att_canteen_calls SET attempts = attempts + 1, last_error = ? WHERE id = ?',
                    [mb_substr((string) ($r['error'] ?? 'The canteen did not say what it ' . $key . '.'), 0, 255), (int) $c['id']]);
                break;
            }
            Db::tx(static function () use ($c, $r) {
                if ($c['action'] === 'settle') {
                    Db::run("UPDATE att_canteen_rows SET canteen_state = 'settled' WHERE canteen_ref = ? AND canteen_state = 'reserved'", [(string) $c['sheet_ref']]);
                } else {
                    Db::run("UPDATE att_canteen_rows SET canteen_state = 'unsettled', canteen_ref = NULL WHERE canteen_ref = ? AND canteen_state = 'reserved'", [(string) $c['sheet_ref']]);
                }
                Db::run("UPDATE att_canteen_calls SET status = 'done', attempts = attempts + 1, last_error = NULL, answer = ?, done_at = CURRENT_TIMESTAMP(6) WHERE id = ?",
                    [mb_substr((string) json_encode($r['data']), 0, 500), (int) $c['id']]);
            });
            $sent++;
        }
        return ['sent' => $sent, 'pending' => self::pendingCount()];
    }

    // ----------------------------------------------------------- the wire

    private static function url(): ?string
    {
        $u = trim((string) ($GLOBALS['config']['canteen_url'] ?? ''));
        if ($u === '') {
            return null;
        }
        $p = parse_url($u);
        if (!is_array($p) || !isset($p['scheme'], $p['host']) || isset($p['query']) || isset($p['user'])) {
            return null;
        }
        // HTTPS only; plain http just for a test stand-in on this machine.
        $local = in_array($p['host'], ['127.0.0.1', 'localhost'], true);
        return $p['scheme'] === 'https' || ($p['scheme'] === 'http' && $local) ? $u : null;
    }

    private static function key(): string
    {
        return (string) ($GLOBALS['config']['canteen_charges_key'] ?? '');
    }

    /** @return array{ok:bool,data?:array,reason_code?:string,error?:string} */
    private static function post(string $action, array $body): array
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
        if (self::$transport !== null) {
            $res = (self::$transport)($action, $body);
            $status = (int) ($res['status'] ?? 0); $raw = (string) ($res['body'] ?? '');
        } else {
            $url = self::url();
            if ($url === null) {
                return self::no('NOT_SET_UP', 'The canteen link is not switched on.');
            }
            if (!function_exists('curl_init')) {
                return self::no('UNREACHABLE', 'This server cannot make HTTPS calls (curl is missing).');
            }
            $ch = curl_init($url . '?a=' . rawurlencode($action));
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Link-Key: ' . self::key()],
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                return self::no('UNREACHABLE', 'The canteen could not be reached' . ($err !== '' ? ' (' . mb_substr($err, 0, 80) . ')' : '') . '. Nothing was changed; try again.');
            }
            $raw = (string) $raw;
        }
        if ($status === 403) {
            return self::no('CANTEEN_KEY', 'The canteen refused the key. Check canteen_charges_key in the server config matches the canteen\'s.');
        }
        if ($status !== 200) {
            return self::no('UNREACHABLE', 'The canteen answered with an error (' . ($status ?: 'no answer') . '). Nothing was changed; try again.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['ok'] ?? null) !== true) {
            return self::no('BAD_ANSWER', 'The canteen\'s answer could not be read' . (is_array($data) && isset($data['error']) ? ': ' . mb_substr((string) $data['error'], 0, 120) : '.'));
        }
        return ['ok' => true, 'data' => $data];
    }

    // ----------------------------------------------------------- helpers

    private static function today(): string
    {
        return FinanceService::today();
    }

    public static function thursdayOnOrBefore(string $ymd): string
    {
        $d = new DateTimeImmutable($ymd);
        $back = ((int) $d->format('N') - 4 + 7) % 7;
        return $d->modify("-$back days")->format('Y-m-d');
    }

    private static function fridayOnOrBefore(string $ymd): string
    {
        $d = new DateTimeImmutable($ymd);
        $back = ((int) $d->format('N') - 5 + 7) % 7;
        return $d->modify("-$back days")->format('Y-m-d');
    }

    private static function isId(mixed $v): bool
    {
        return is_int($v) ? $v > 0 : (is_string($v) && preg_match('/^[1-9]\d{0,17}$/', $v) === 1);
    }

    private static function isPaisa(mixed $v): bool
    {
        return is_int($v) && $v >= 0 && $v <= 100_000_000_00;
    }

    private static function isDay(mixed $v): bool
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
    }

    private static function dt(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/', $v, $m) || !self::isDay($m[1])) {
            return null;
        }
        return $m[1] . ' ' . $m[2];
    }

    /** @return ?list<int> */
    private static function intList(mixed $v): ?array
    {
        if (!is_array($v)) {
            return null;
        }
        $out = [];
        foreach ($v as $x) {
            if (!self::isId($x)) { return null; }
            $out[] = (int) $x;
        }
        return $out;
    }

    private static function rupees(int $paisa): string
    {
        return 'Rs ' . number_format(intdiv($paisa, 100)) . ($paisa % 100 ? '.' . str_pad((string) ($paisa % 100), 2, '0', STR_PAD_LEFT) : '');
    }

    private static function no(string $code, string $message): array
    {
        return ['ok' => false, 'reason_code' => $code, 'error' => $message];
    }
}
