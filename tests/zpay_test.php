<?php
/* PAYING A WORKER — earned, paid, balance, and nothing else.
 *
 * "just deal financie for workers only against production so simple pay
 *  cash system very simple worker ledger etc"
 *
 * A much larger proposal was turned down, so what is checked here is as
 * much that the system stayed SMALL as that it works: no accounts, no
 * vouchers, no double entry.
 */
$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$zp = file_get_contents($B . 'includes/zprod.php');
$pg = file_get_contents($B . 'production_pay.php');

function lift(string $src, string $from): string {
    $a = strpos($src, $from);
    if ($a === false) return '';
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a + 3);
}

echo "1. It stayed small\n";
foreach (['zp_cash_account', 'zp_voucher', 'chart_of_accounts', 'journal', 'zp_ledger_head'] as $big)
    ok(!str_contains($zp, $big), "no $big — this is not an accounting system");
ok(str_contains($zp, 'CREATE TABLE IF NOT EXISTS zp_pay'), 'one table, zp_pay');
ok(substr_count($zp, 'CREATE TABLE IF NOT EXISTS zp_pay') === 1, '  exactly one');
$zpF = preg_replace('/\s+/', ' ', $zp);
ok(str_contains($zpF, 'there is no chart of accounts, no cash account, no voucher, no double entry')
   || str_contains($zpF, 'no chart of accounts, no cash account, no voucher, no double'),
   'and the file says so, so nobody grows it by accident');

echo "2. Earned and paid\n";
$ROWS = [
  'earned' => [['worker_id'=>1,'a'=>18450], ['worker_id'=>2,'a'=>21300], ['worker_id'=>3,'a'=>5000]],
  'paid'   => [['worker_id'=>1,'a'=>10000]],
];
$WORKERS = [
  ['id'=>1,'worker_code'=>'136','worker_name'=>'ABDUL REHMAN','department'=>'MADEUPS','is_active'=>1],
  ['id'=>2,'worker_code'=>'145','worker_name'=>'ABDULLAH','department'=>'MADEUPS','is_active'=>1],
  ['id'=>3,'worker_code'=>'406','worker_name'=>'LEFT US','department'=>'HEAD OFFICE','is_active'=>0],
  ['id'=>4,'worker_code'=>'999','worker_name'=>'NEVER WORKED','department'=>'KITCHEN','is_active'=>0],
];
function zp_workers(bool $a = false): array { global $WORKERS; return $WORKERS; }
function zp_ensure_schema(): void {}
$LOG = []; $INSERTED = [];
final class PStmt {
    public function __construct(private string $sql) {}
    public function execute($a = []) { global $LOG, $INSERTED; $LOG[] = [$this->sql, $a];
        if (str_contains($this->sql, 'INSERT INTO zp_pay')) $INSERTED[] = $a; return true; }
    public function fetchAll() {
        global $ROWS;
        if (str_contains($this->sql, 'FROM zp_entries')) return array_map(fn($r) => ['worker_id'=>$r['worker_id'],'a'=>$r['a']], $ROWS['earned']);
        if (str_contains($this->sql, 'FROM zp_pay'))     return array_map(fn($r) => ['worker_id'=>$r['worker_id'],'a'=>$r['a']], $ROWS['paid']);
        return [];
    }
    public function fetchColumn() { return 'active'; }
}
final class PDb {
    public function prepare($s) { return new PStmt($s); }
    public function query($s) { return new PStmt($s); }
    public function exec($s) { return 1; }
    public function beginTransaction() { global $LOG; $LOG[] = ['BEGIN', []]; return true; }
    public function commit() { global $LOG; $LOG[] = ['COMMIT', []]; return true; }
    public function rollBack() { global $LOG; $LOG[] = ['ROLLBACK', []]; return true; }
}
$DB = new PDb();
function db() { global $DB; return $DB; }

eval(lift($zp, 'function zp_earned_map('));
eval(lift($zp, 'function zp_paid_map('));
eval(lift($zp, 'function zp_worker_balances('));
eval(lift($zp, 'function zp_pay_save('));

$b = zp_worker_balances();
$by = []; foreach ($b as $r) $by[$r['id']] = $r;
ok(($by[1]['balance'] ?? null) === 8450.0, 'earned 18,450 less paid 10,000 is 8,450, got ' . ($by[1]['balance'] ?? 'null'));
ok(($by[2]['balance'] ?? null) === 21300.0, 'nothing paid yet means the whole lot is owed');
ok($b[0]['id'] === 2, 'MOST OWED FIRST — that is the order you pay in, got ' . $b[0]['id']);
/* SOMEBODY SWITCHED OFF WITH MONEY OWING MUST NOT VANISH. That is how a
   wage gets forgotten. */
ok(isset($by[3]), 'an INACTIVE worker who is still owed money is still listed');
ok(!isset($by[4]), '  but one who never worked and is owed nothing is not clutter');

echo "3. Paying\n";
$INSERTED = []; $LOG = [];
$r = zp_pay_save('2026-09-18', [
    ['worker_id'=>1, 'amount'=>'8,450', 'note'=>'weekly'],
    ['worker_id'=>2, 'amount'=>'10000', 'note'=>''],
    ['worker_id'=>0, 'amount'=>'',      'note'=>''],          // the spare row
]);
ok($r['ok'] === true, 'saved: ' . json_encode($r['errors']));
ok($r['saved'] === 2, 'TWO paid, the blank row ignored, got ' . $r['saved']);
ok($r['total'] === 18450.0, 'and the total is right, got ' . $r['total']);
ok(count($INSERTED) === 2, 'two rows written, got ' . count($INSERTED));
ok(($INSERTED[0][2] ?? null) === 8450.0, 'a comma in the amount is read, not refused: ' . json_encode($INSERTED[0] ?? null));
ok(in_array('COMMIT', array_column($LOG, 0), true), 'one transaction, committed');

echo "4. What is refused, and what is only warned about\n";
$INSERTED = [];
$r2 = zp_pay_save('2026-09-18', [['worker_id'=>1,'amount'=>'0','note'=>'']]);
ok($r2['ok'] === false && str_contains(implode(' ', $r2['errors']), 'has no amount'), 'a worker with no amount is refused');
$r3 = zp_pay_save('2026-09-18', [['worker_id'=>99,'amount'=>'100','note'=>'']]);
ok($r3['ok'] === false && str_contains(implode(' ', $r3['errors']), 'not on the list'), 'an unknown worker is refused');
/* THE SAME PERSON TWICE IS ALMOST ALWAYS A SLIP, and the second line would
   quietly double their pay. */
$INSERTED = [];
$r4 = zp_pay_save('2026-09-18', [['worker_id'=>1,'amount'=>'100','note'=>''], ['worker_id'=>1,'amount'=>'100','note'=>'']]);
ok($r4['ok'] === false && str_contains(implode(' ', $r4['errors']), 'twice'), 'the same worker twice on one sheet is caught');
ok($INSERTED === [], '  AND THE GOOD LINE BEFORE IT IS NOT SAVED EITHER');
$r5 = zp_pay_save(date('Y-m-d', strtotime('+2 days')), [['worker_id'=>1,'amount'=>'100','note'=>'']]);
ok($r5['ok'] === false, 'a payment cannot be dated in the future');

/* AN ADVANCE IS ORDINARY. Refusing it sends people back to paying cash off
   the books, which is the one outcome worse than a negative balance. */
$INSERTED = [];
$r6 = zp_pay_save('2026-09-18', [['worker_id'=>2,'amount'=>'50000','note'=>'advance']]);
ok($r6['ok'] === true, 'PAYING MORE THAN IS OWED IS ALLOWED — an advance is ordinary');
ok(count($r6['warn']) === 1 && str_contains($r6['warn'][0], 'ABDULLAH'), '  but it is SAID: ' . json_encode($r6['warn']));
ok(count($INSERTED) === 1, '  and it really is saved');

echo "5. The ledger\n";
eval(lift($zp, 'function zp_worker_ledger('));
ok(str_contains($zp, "\$a['kind'] === 'earned' ? 0 : 1"),
   'earned sorts before paid on the same day — you cannot be paid for work not yet booked');
ok(str_contains($zp, "GROUP BY entry_date"),
   'work is summed PER DAY, not listed per operation — the daily total is the line you can argue with');
ok(str_contains($zpF, 'A CANCELLED PAYMENT IS SHOWN, STRUCK THROUGH, AND COUNTS FOR NOTHING'),
   'a cancelled payment stays visible and counts nothing');

echo "6. A payment is cancelled, never deleted\n";
$canc = lift($zp, 'function zp_pay_cancel(');
ok(str_contains($canc, "UPDATE zp_pay SET status='cancelled'"), 'cancelling marks the row');
ok(!preg_match('/DELETE\s+FROM\s+zp_pay/i', $zp), '  NOTHING in the module deletes a payment');
ok(str_contains($canc, 'Give a reason'), 'and a reason is required');

echo "7. The screen\n";
ok(str_contains($pg, 'name="p_worker[]"') && str_contains($pg, 'name="p_amount[]"'), 'the pay grid posts worker and amount');
ok(str_contains($pg, 'data-owed='), 'each box knows what is owed, so over-paying can be marked');
ok(str_contains($pg, "GRID.attach(tb, { cols:['amount','note']"), 'the shared spreadsheet keys and Excel paste are on it');
ok(str_contains($pg, "GRID.keys(document.getElementById('payForm')"), 'and Ctrl+S / Escape');
ok(str_contains($pg, 'id="payN"') && str_contains($pg, 'id="payT"'),
   'THE BUTTON SAYS HOW MANY AND HOW MUCH — one that does not is pressed twice');
ok(str_contains($pg, 'Clear every amount') && str_contains($pg, 'Fill every amount'), 'and both bulk buttons are there');
ok(str_contains($pg, 'zp_worker_ledger($wid)'), 'the ledger is on the same screen');
ok(str_contains($pg, 'prompt(') && str_contains($pg, "value=\"cancel\""), 'cancelling asks for a reason');
ok(str_contains($pg, 'is_admin()'), '  and only an admin may do it');
ok(str_contains(file_get_contents($B . 'includes/menu.php'), "'href' => 'production_pay.php'"), 'it is on the menu');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
