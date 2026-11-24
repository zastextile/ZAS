<?php
declare(strict_types=1);
/*
 * CANTEEN CHARGES ON PAY (owner, 24 Nov 2026).
 *   - "Get canteen charges" copies what the canteen says is owed; pressing it twice changes nothing
 *   - each person gets ONE line "Canteen 13–19 Nov · 1 Biryani, 6 Tea (2 orders)", refunds netted,
 *     whole orders oldest first, never more than the pay there is
 *   - sending the sheet reserves its orders; what the canteen refuses comes off the sheet at once
 *   - approve settles; send back / cancel / everyone removed releases; nothing is ever charged twice
 *   - the canteen down, or an answer lost, charges nothing and loses nothing
 *   - each person's weekly limit goes to the canteen with the card check
 * The canteen here is a stand-in that keeps the same rules as the real one.
 */
$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler',
          'SyncService','OvertimeService','ReportService','HrService','AdminService','EntryAdmin','FinanceService','CardService','CanteenService'] as $c) {
    require_once $B . "includes/att/$c.php";
}
use Att\CanteenService as K;
use Att\CardService;
use Att\Db;
use Att\Employees;
use Att\FinanceService as Fin;
use Att\Migrations;
use Att\Roles;

try {
    $pdo = new PDO($dsn, $dbu, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) { echo "SKIP: no MySQL available ({$e->getMessage()})\n"; exit(0); }
Db::bind($pdo);
Db::useUtc();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $t) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $t['tn'] . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'colleague', department VARCHAR(60) NULL,
            can_see_rates TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE zu_perm (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, module VARCHAR(24) NOT NULL,
            actions VARCHAR(12) NOT NULL DEFAULT '', updated_by INT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_module (user_id, module), INDEX(user_id))");
$mig = new Migrations($B . 'sql/att');
$mig->up();
$n = fn(string $sql, array $p = []) => (int) Db::value($sql, $p);
$pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES ('Admin Person','admin@zas.test','x','admin')")->execute();
$admin = (int) $pdo->lastInsertId();
Roles::grant($admin, Roles::ADMIN);
$d = Employees::createDepartment('Stitching');
$emp = [];
foreach (['S001' => 6000000, 'S002' => 50000, 'S003' => 4000000, 'S004' => 4000000, 'S005' => 4000000, 'S006' => 4000000] as $c => $basic) {
    $emp[$c] = Employees::create(['code' => $c, 'full_name' => "Person $c", 'department_id' => $d]);
}
Fin::setToday('2026-11-30');
foreach (['S001' => 6000000, 'S002' => 50000, 'S003' => 4000000, 'S004' => 4000000, 'S005' => 4000000, 'S006' => 4000000] as $c => $basic) {
    Fin::saveProfile($emp[$c], ['pay_type' => 'salary', 'basic_paisa' => $basic], '', $admin);
}
$A = Fin::caps($admin);
$line = function (array $lines, string $key): ?array { foreach ($lines as $l) { if ($l['key'] === $key) { return $l; } } return null; };
$prev = function (string $c) use ($emp, $A, $line): ?array {
    foreach (Fin::preview('salary', '2026-11-01', '2026-11-30', $A)['rows'] ?? [] as $r) { if ($r['employee_id'] === $emp[$c]) { return $line($r['lines'], 'canteen'); } }
    return null;
};
$sheetOf = fn(array $codes) => Fin::createSheet('salary', '2026-11-01', '2026-11-30', array_map(fn($c) => ['employee_id' => $emp[$c]], $codes), $A, $admin);
$state = fn(string $kind, int $ext) => Db::one('SELECT zas_state, canteen_state, canteen_ref, sheet_id FROM att_canteen_rows WHERE kind = ? AND ext_id = ?', [$kind, $ext]);

/* ------------------------------------------------ the stand-in canteen */
final class FakeCanteen
{
    public array $orders = [];
    public array $refunds = [];
    public array $calls = [];
    public string $mode = 'up';            // up | down | lose_answer | wrong_key
    public function order(int $id, int $emp, string $at, array $items, ?string $week = null): void
    {
        $this->orders[$id] = ['order_id' => $id, 'employee_id' => $emp, 'ordered_at' => $at, 'week_from' => $week ?? self::friday(substr($at, 0, 10)),
            'total' => array_sum(array_column($items, 'amount')), 'items' => $items, 's' => 'unsettled', 'ref' => null, 'cancelled' => false];
    }
    public function refund(int $id, int $orderId, int $emp, string $at, int $amount): void
    {
        $this->refunds[$id] = ['refund_id' => $id, 'order_id' => $orderId, 'employee_id' => $emp, 'created_at' => $at, 'amount' => $amount, 'reason' => 'cold tea', 's' => 'unsettled', 'ref' => null];
    }
    private static function friday(string $ymd): string
    {
        $t = new DateTimeImmutable($ymd); return $t->modify('-' . (((int) $t->format('N') - 5 + 7) % 7) . ' days')->format('Y-m-d');
    }
    public function handle(string $a, array $b): array
    {
        $this->calls[] = [$a, $b];
        if ($this->mode === 'down') { return ['status' => 0, 'body' => '']; }
        if ($this->mode === 'wrong_key') { return ['status' => 403, 'body' => '']; }
        $out = $this->run($a, $b);
        return $this->mode === 'lose_answer' ? ['status' => 0, 'body' => ''] : ['status' => 200, 'body' => json_encode($out)];
    }
    private function run(string $a, array $b): array
    {
        $ref = (string) ($b['sheet_ref'] ?? '');
        if ($a === 'charges') {
            $o = array_values(array_map(fn($x) => array_diff_key($x, ['s' => 1, 'ref' => 1, 'cancelled' => 1]),
                array_filter($this->orders, fn($x) => $x['s'] === 'unsettled' && !$x['cancelled'] && substr($x['ordered_at'], 0, 10) <= $b['up_to'])));
            $r = array_values(array_map(fn($x) => array_diff_key($x, ['s' => 1, 'ref' => 1]),
                array_filter($this->refunds, fn($x) => $x['s'] === 'unsettled' && substr($x['created_at'], 0, 10) <= $b['up_to'])));
            return ['ok' => true, 'up_to' => $b['up_to'], 'currency' => 'PKR-paisa', 'orders' => $o, 'refunds' => $r];
        }
        if ($a === 'reserve') {
            $res = ['ok' => true, 'sheet_ref' => $ref, 'reserved' => ['orders' => 0, 'refunds' => 0], 'already' => ['orders' => 0, 'refunds' => 0], 'refused' => ['order_ids' => [], 'refund_ids' => []]];
            foreach (['orders' => 'order_ids', 'refunds' => 'refund_ids'] as $kind => $key) {
                foreach ($b[$key] as $id) {
                    $x = &$this->{$kind}[$id];
                    if ($x === null || ($x['cancelled'] ?? false) || $x['s'] === 'settled' || ($x['s'] === 'reserved' && $x['ref'] !== $ref)) { $res['refused'][$key][] = $id; }
                    elseif ($x['s'] === 'reserved') { $res['already'][$kind]++; }
                    else { $x['s'] = 'reserved'; $x['ref'] = $ref; $res['reserved'][$kind]++; }
                    unset($x);
                }
            }
            return $res;
        }
        $to = $a === 'settle' ? 'settled' : 'unsettled';
        $cnt = ['orders' => 0, 'refunds' => 0];
        foreach (['orders', 'refunds'] as $kind) {
            foreach ($this->{$kind} as &$x) {
                if ($x['s'] === 'reserved' && $x['ref'] === $ref) { $x['s'] = $to; $x['ref'] = $to === 'settled' ? $ref : null; $cnt[$kind]++; }
            }
            unset($x);
        }
        return ['ok' => true, 'sheet_ref' => $ref, $a === 'settle' ? 'settled' : 'released' => $cnt];
    }
}
$fake = new FakeCanteen();
$it = fn(string $name, int $qty, int $amount) => ['name' => $name, 'qty' => $qty, 'amount' => $amount];
$fake->order(9001, $emp['S001'], '2026-11-14 13:05:22', [$it('Biryani', 1, 25000), $it('Tea', 2, 7000)], '2026-11-13');
$fake->order(9002, $emp['S001'], '2026-11-18 10:00:00', [$it('Tea', 4, 14000)]);
$fake->refund(41, 8870, $emp['S001'], '2026-11-15 10:00:00', 7000);
$fake->order(9003, $emp['S001'], '2026-11-28 10:00:00', [$it('Tea', 1, 3500)]);            // after the Thursday: not yet
$fake->order(9010, $emp['S002'], '2026-11-14 12:00:00', [$it('Biryani', 1, 30000)]);
$fake->order(9011, $emp['S002'], '2026-11-20 12:00:00', [$it('Biryani', 1, 30000)]);
$fake->order(9020, $emp['S003'], '2026-11-21 12:00:00', [$it('Samosa', 2, 10000)]);
$fake->order(9030, $emp['S004'], '2026-11-21 12:00:00', [$it('Tea', 1, 5000)]);
$fake->order(9040, $emp['S005'], '2026-11-21 12:00:00', [$it('Tea', 1, 6000)]);
$fake->order(9050, $emp['S006'], '2026-11-21 12:00:00', [$it('Tea', 1, 8000)]);
$fake->order(9098, 999999, '2026-11-21 12:00:00', [$it('Tea', 1, 1000)]);                // not a ZAS person
$fake->orders[9099] = ['order_id' => 9099, 'employee_id' => $emp['S003'], 'ordered_at' => '2026-11-21 12:00:00', 'week_from' => '2026-11-20', 'total' => 9999,
    'items' => [$it('Tea', 1, 1000)], 's' => 'unsettled', 'ref' => null, 'cancelled' => false];                    // items do not add up
K::setTransport(fn(string $a, array $b) => $fake->handle($a, $b));

echo "1. The database step\n";
ok($n("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('att_canteen_rows','att_canteen_calls')") === 2, 'it makes the two canteen tables');
ok(Db::columnExists('att_emp_cards', 'weekly_limit_paisa') && !Db::columnExists('att_employees', 'weekly_limit_paisa'), '  and the weekly limit sits on the card table, not att_employees');

echo "2. Get canteen charges\n";
ok((K::fetch('2026-11-30', $admin)['reason_code'] ?? '') === 'NOT_SET_UP', 'without the address and key in config, nothing is asked');
$GLOBALS['config']['canteen_url'] = 'http://canteen.example/zas.php';
$GLOBALS['config']['canteen_charges_key'] = str_repeat('k', 40);
ok(!K::configured(), 'plain http to another server is refused: HTTPS only');
$GLOBALS['config']['canteen_url'] = 'https://canteen.example/zas.php';
ok(K::configured(), 'an https address and a 40-character key switch it on');
ok(count($fake->calls) === 0 && $prev('S001') === null, 'before the button, no line and no call');
$f1 = K::fetch('2026-11-30', $admin);
ok($f1['ok'] && $f1['up_to'] === '2026-11-26', 'asks up to the Thursday on or before the last day (26 Nov)');
ok($f1['orders'] === 8 && $f1['refunds'] === 1 && $f1['skipped_count'] === 2, '8 good orders, 1 refund; 2 left out (not a ZAS person, items not adding up)');
$rows1 = $n('SELECT COUNT(*) FROM att_canteen_rows');
$f2 = K::fetch('2026-11-30', $admin);
ok($f2['orders'] === 8 && $n('SELECT COUNT(*) FROM att_canteen_rows') === $rows1, 'pressing it again adds nothing new');
ok(!array_filter($fake->calls, fn($c) => $c[0] !== 'charges'), 'getting charges only reads: nothing is held at the canteen');

echo "3. The line on Prepare pay\n";
$l1 = $prev('S001');
ok($l1 !== null && $l1['label'] === 'Canteen 13–19 Nov · 1 Biryani, 6 Tea (2 orders)', 'one line per person: "' . ($l1['label'] ?? '-') . '"');
ok($l1['side'] === 'deduct' && $l1['amount_paisa'] === 39000 && !$l1['editable'], '  Rs 390 = 320 + 140 − refund 70, taken off, not editable');
ok(str_contains((string) $l1['detail'], '1 refund Rs 70'), '  the detail shows the refund');
$l2 = $prev('S002');
ok($l2 !== null && $l2['amount_paisa'] === 30000 && str_ends_with($l2['label'], '(1 order)'), 'pay of Rs 500: only the first whole order fits; the second waits');

echo "4. Sending the sheet reserves; a refused order comes off at once\n";
$fake->orders[9002]['cancelled'] = true;                                              // cancelled at the counter meanwhile
$s1 = $sheetOf(['S001', 'S002']);
ok($s1['ok'] && isset($s1['canteen_note']), 'the sheet is made, with a note that something came off');
$reserve = array_values(array_filter($fake->calls, fn($c) => $c[0] === 'reserve'));
ok(count($reserve) === 1 && $reserve[0][1]['sheet_ref'] === $s1['ref'] && $reserve[0][1]['refund_ids'] === [41], 'one reserve call with the sheet\'s ref');
$sh1 = Fin::sheet($s1['sheet_id'], $A, $admin);
$it1 = array_values(array_filter($sh1['items'], fn($i) => $i['employee_id'] === $emp['S001']))[0];
$cl = $line($it1['lines'], 'canteen');
ok($cl['amount_paisa'] === 25000 && $cl['label'] === 'Canteen 13–19 Nov · 1 Biryani, 2 Tea (1 order)', 'S001\'s line is now Rs 250 (the cancelled order is off)');
ok($it1['amount_paisa'] === 6000000 - 25000, '  and their pay is worked out again');
ok(($state('order', 9001)['canteen_state'] ?? '') === 'reserved' && ($state('order', 9002)['zas_state'] ?? '') === 'gone', 'held orders are reserved; the cancelled one is set aside');
ok($fake->orders[9001]['s'] === 'reserved' && $fake->orders[9011]['s'] === 'unsettled', 'the canteen holds only what is on the sheet');
$am = Fin::amendSheet($s1['sheet_id'], [['employee_id' => $emp['S001'], 'changes' => [['key' => 'canteen', 'amount_paisa' => 0, 'reason' => 'x']]]], $A, $admin);
ok(($am['reason_code'] ?? '') === 'VALIDATION' && str_contains((string) $am['error'], 'canteen'), 'the canteen line cannot be changed on the sheet');

echo "5. Approve settles; nothing is charged twice\n";
ok(Fin::approve($s1['sheet_id'], $A, $admin)['ok'], 'approved');
ok($fake->orders[9001]['s'] === 'settled' && $fake->refunds[41]['s'] === 'settled', 'the canteen settled the orders and the refund');
ok(($state('order', 9001)['canteen_state'] ?? '') === 'settled' && ($state('order', 9001)['zas_state'] ?? '') === 'charged', '  and ZAS knows it');
$led = Db::one("SELECT * FROM att_fin_ledger WHERE sheet_id = ? AND line_kind = 'canteen' AND employee_id = ?", [$s1['sheet_id'], $emp['S001']]);
ok($led !== null && $led['kind'] === 'deduction' && (int) $led['amount_paisa'] === 25000, 'the ledger has one canteen deduction of Rs 250');
ok(str_starts_with(Fin::lineRef($led), 'CNT-'), '  with a CNT- reference');
K::fetch('2026-11-30', $admin);
ok($n("SELECT COUNT(*) FROM att_canteen_rows WHERE kind = 'order' AND ext_id = 9001") === 1 && ($state('order', 9011)['zas_state'] ?? '') === 'free', 'getting charges again: settled ones do not come back; S002\'s waiting order is still there');
ok($n('SELECT COUNT(*) FROM att_canteen_calls WHERE status = ?', ['pending']) === 0, 'no message waiting');

echo "6. Sent back: the canteen lets go, the charge comes again\n";
$s3 = $sheetOf(['S003']);
ok($fake->orders[9020]['s'] === 'reserved', 'S003\'s order is held');
ok(Fin::sendBack($s3['sheet_id'], 'wrong month', $A, $admin)['ok'] && $fake->orders[9020]['s'] === 'unsettled', 'sent back → released at the canteen');
ok(($state('order', 9020)['canteen_state'] ?? '') === 'unsettled' && ($prev('S003')['amount_paisa'] ?? 0) === 10000, '  and the line is ready for the next sheet');

echo "7. The canteen down, or its answer lost\n";
$fake->mode = 'lose_answer';                         // the canteen reserves, but the answer never arrives
$s6 = $sheetOf(['S006']);
$sh6 = Fin::sheet($s6['sheet_id'], $A, $admin);
ok($s6['ok'] && str_contains((string) ($s6['canteen_note'] ?? ''), 'could not be reached') && $line($sh6['items'][0]['lines'], 'canteen') === null, 'no answer: the canteen line comes off the sheet; the sheet itself is fine');
ok($sh6['items'][0]['amount_paisa'] === 4000000, '  full pay, nothing taken for the canteen');
ok($fake->orders[9050]['s'] === 'reserved' && ($state('order', 9050)['canteen_state'] ?? '') === 'reserved', 'the canteen did hold it, and ZAS assumes so');
ok($prev('S006') === null, '  so it is not offered on another sheet until that is cleared up');
$fake->mode = 'down';
ok((K::fetch('2026-11-30', $admin)['reason_code'] ?? '') === 'UNREACHABLE', 'canteen down: the button says so and changes nothing');
ok(K::pendingCount() === 1, '  the release waits');
$fake->mode = 'up';
Fin::sendBack($s6['sheet_id'], 'redo without canteen', $A, $admin);
$fe = K::fetch('2026-11-30', $admin);
ok($fe['ok'] && $fe['pending_calls'] === 0 && $fake->orders[9050]['s'] === 'unsettled', 'canteen back: the waiting release is sent first');
ok(($prev('S006')['amount_paisa'] ?? 0) === 8000, '  and the order is offered again, once');

echo "8. Cancelled after approval: owed again, never reserved twice\n";
$s4 = $sheetOf(['S004']);
Fin::approve($s4['sheet_id'], $A, $admin);
ok($fake->orders[9030]['s'] === 'settled', 'S004 approved and settled');
ok(Fin::cancelSheet($s4['sheet_id'], 'paid wrong person', $A, $admin)['ok'], 'then the sheet is cancelled');
ok(($state('order', 9030)['zas_state'] ?? '') === 'free' && ($state('order', 9030)['canteen_state'] ?? '') === 'settled', 'the order is owed again here (the canteen has it settled)');
ok(($prev('S004')['amount_paisa'] ?? 0) === 5000, '  so it is on the next sheet');
$before = count(array_filter($fake->calls, fn($c) => $c[0] === 'reserve'));
$s4b = $sheetOf(['S004']);
ok(count(array_filter($fake->calls, fn($c) => $c[0] === 'reserve')) === $before && $s4b['ok'] && !isset($s4b['canteen_note']), '  without asking the canteen to hold it again');
Fin::approve($s4b['sheet_id'], $A, $admin);
ok($n("SELECT COUNT(*) FROM att_fin_ledger WHERE employee_id = ? AND line_kind = 'canteen' AND cancelled_at IS NULL", [$emp['S004']]) === 1, '  one live canteen deduction for S004, the cancelled one struck');

echo "9. One person taken off a waiting sheet\n";
$s5 = $sheetOf(['S003', 'S005']);
ok($fake->orders[9040]['s'] === 'reserved', 'S005\'s order is held with the sheet');
ok(Fin::removeFromSheet($s5['sheet_id'], $emp['S005'], 'leaves next week', $A, $admin)['ok'], 'S005 taken off');
ok($prev('S005') === null, '  their order waits until the sheet is decided');
Fin::approve($s5['sheet_id'], $A, $admin);
ok($fake->orders[9040]['s'] === 'settled' && ($state('order', 9040)['zas_state'] ?? '') === 'free', 'the sheet settled at the canteen, but S005 was not charged here');
ok(($prev('S005')['amount_paisa'] ?? 0) === 6000, '  so it is owed again on their next sheet');

echo "10. Wrong key\n";
$fake->mode = 'wrong_key';
ok((K::fetch('2026-11-30', $admin)['reason_code'] ?? '') === 'CANTEEN_KEY', 'a 403 from the canteen says the key is wrong');
$fake->mode = 'up';

echo "11. Weekly limit per person\n";
$hS1 = hash('sha256', '0001234567');
CardService::link($emp['S001'], $hS1, false, '', null, $admin);
CardService::canteen([$emp['S001']], true, null, $admin);
ok((CardService::check($hS1)['weekly_limit'] ?? 0) === 100000, 'the card check sends the default Rs 1,000');
ok((CardService::setLimit([$emp['S001']], 150050, null, $admin)['reason_code'] ?? '') === 'VALIDATION', 'a limit with paisa is refused: whole rupees only');
ok((CardService::setLimit([$emp['S001'], $emp['S002']], 150000, null, $admin)['changed'] ?? 0) === 2, 'Rs 1,500 for two people');
ok((CardService::check($hS1)['weekly_limit'] ?? 0) === 150000, '  and the canteen is told Rs 1,500');
ok($n("SELECT COUNT(*) FROM att_card_log WHERE action = 'limit' AND reason = 'Rs 1,000 → Rs 1,500'") === 2, '  each change logged with old and new');
ok((CardService::setLimit([$emp['S001']], 150000, null, $admin)['changed'] ?? -1) === 0, 'the same figure again changes nothing');
$pp = array_values(array_filter(CardService::people(null)['people'], fn($p) => $p['employee_id'] === $emp['S003']))[0];
ok($pp['weekly_limit_paisa'] === 100000, 'someone never set shows the default');

echo "12. Undo the step\n";
$mig->down(23);
ok($n("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'att_canteen%'") === 0 && !Db::columnExists('att_emp_cards', 'weekly_limit_paisa'), 'down --to=23 removes only what 0024 added');
$code = <<<'PHPCODE'
<?php
declare(strict_types=1);
$B = $argv[1];
foreach (['Db','Time','Policy','Audit','Roles','Employees','Shifts','ReportService','FinanceService','CardService','CanteenService'] as $c) require_once $B . "includes/att/$c.php";
use Att\Db; use Att\FinanceService as Fin; use Att\CardService;
$pdo = new PDO($argv[2], $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
Db::bind($pdo); Db::useUtc();
Fin::setToday('2026-12-31');
$p = Fin::preview('salary', '2026-12-01', '2026-12-31', Fin::caps((int) $argv[5]));
$has = false; foreach ($p['rows'] ?? [] as $r) { foreach ($r['lines'] as $l) { if ($l['key'] === 'canteen') $has = true; } }
echo json_encode(['ok' => $p['ok'] ?? false, 'rows' => count($p['rows'] ?? []), 'canteen' => $has, 'limit' => CardService::check($argv[6])['weekly_limit'] ?? null]);
PHPCODE;
file_put_contents(__DIR__ . '/.zcanteen.php', $code);
$raw = shell_exec('php ' . escapeshellarg(__DIR__ . '/.zcanteen.php') . ' ' . implode(' ', array_map('escapeshellarg', [$B, $dsn, $dbu, $dbp, (string) $admin, $hS1])) . ' 2>&1');
@unlink(__DIR__ . '/.zcanteen.php');
$r = json_decode((string) $raw, true);
ok(is_array($r) && $r['ok'] && $r['rows'] === 6 && !$r['canteen'], 'without the canteen tables, Prepare pay works exactly as before' . (is_array($r) ? '' : ": $raw"));
ok(is_array($r) && $r['limit'] === 100000, '  and the card check still answers, with the default limit');
$mig->up();
ok(Db::columnExists('att_emp_cards', 'weekly_limit_paisa'), 'and up again');

echo "13. Over a real connection (curl, the key in a header)\n";
$dir = sys_get_temp_dir() . '/zcanteen_' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/zas.php', '<?php
header("Content-Type: application/json");
if (($_SERVER["HTTP_X_LINK_KEY"] ?? "") !== str_repeat("k", 40)) { http_response_code(403); exit; }
$b = json_decode(file_get_contents("php://input"), true);
echo json_encode(["ok" => true, "up_to" => $b["up_to"] ?? null, "seen" => $_GET["a"] ?? "", "orders" => [], "refunds" => []]);');
$srv = proc_open(['php', '-S', '127.0.0.1:8762', '-t', $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(600000);
K::setTransport(null);
$GLOBALS['config']['canteen_url'] = 'http://127.0.0.1:8762/zas.php';
$w = K::fetch('2026-11-30', $admin);
ok(($w['ok'] ?? false) && $w['up_to'] === '2026-11-26' && $w['orders'] === 0, 'a real POST with the key gets the answer' . (($w['ok'] ?? false) ? '' : ': ' . ($w['error'] ?? '?')));
$GLOBALS['config']['canteen_charges_key'] = str_repeat('x', 40);
ok((K::fetch('2026-11-30', $admin)['reason_code'] ?? '') === 'CANTEEN_KEY', '  the wrong key gets 403 → CANTEEN_KEY');
$GLOBALS['config']['canteen_url'] = 'http://127.0.0.1:8763/zas.php';
ok((K::fetch('2026-11-30', $admin)['reason_code'] ?? '') === 'UNREACHABLE', '  nobody listening → UNREACHABLE, nothing changed');
proc_terminate($srv); proc_close($srv);
@unlink($dir . '/zas.php'); @rmdir($dir);

echo "\nCANTEEN: $P passed, $F failed\n";
exit($F ? 1 : 0);
