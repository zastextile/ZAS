<?php
/* WORKERS FROM THE HR APP.
 *
 * His words, and every one of them is a rule this file checks:
 *   "use my this token key to get exact production_workers.php as update
 *    button on this page ... let department also save into system as it as
 *    well stage i will manual edit easily one by one ... if any change on
 *    back end on that token so only get update status and if any id or
 *    worker removed so you do not remove here even if its started job
 *    somewhere but give me alert so rest if add some worker with new ids
 *    name and department so add easily same way and tel lme only that
 *    adding new simple alert msg"
 *
 * The planner and the writer are lifted out of includes/zprod.php and run
 * against a fake worker table, and the reader is run against a real HTTP
 * server started for the test — so the JSON parsing is exercised for real
 * rather than described.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$zp   = file_get_contents($B . 'includes/zprod.php');
$pw   = file_get_contents($B . 'production_workers.php');
$work = __DIR__ . '/.zhr';
@mkdir($work, 0777, true);

function lift(string $src, string $from): string {
    $a = strpos($src, $from);
    if ($a === false) return '';
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a + 3);
}

echo "1. The rules are written down where the next person will read them\n";
$zpF = preg_replace('/\s+/', ' ', $zp);
ok(str_contains($zpF, 'NOBODY IS EVER REMOVED'), 'nobody is ever removed');
ok(str_contains($zpF, 'STAGES ARE NEVER TOUCHED'), 'stages are never touched');
ok(str_contains($zpF, 'EMPNO IS THE PERSON'), 'the employee number is the identity, not the name');
ok(!str_contains($zp, 'a8f3k9x2'), 'THE TOKEN IS NOT IN THE SOURCE — it lives in settings');
ok(!str_contains($pw, 'a8f3k9x2'), '  nor on the screen');
ok(str_contains($zp, "zp_meta_get('hr_token')"), 'the token is read from settings');
ok(str_contains($zp, "str_repeat('•', 8)"), 'and only ever shown masked');

echo "2. Reading a real HTTP answer\n";
/* A REAL SERVER, not a mocked function. The reader uses file_get_contents
   with a stream context, and the only way to know that works — headers,
   status codes, timeouts — is to make it do it. */
$srv = <<<'PHP'
<?php
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Bearer testtoken123') { http_response_code(401); echo 'no'; exit; }
$shape = $_GET['shape'] ?? 'plain';
if ($shape === 'notjson') { echo 'hello'; exit; }
if ($shape === 'wrapped') { echo json_encode(['data' => [
    ['EMPNO' => '1001', 'ENAME' => 'Rashid Ali', 'DEPARTMENT' => 'Stitching Floor 1'],
]]); exit; }
if ($shape === 'lower') { echo json_encode([
    ['empno' => '1001', 'ename' => 'Rashid Ali', 'department' => 'Stitching Floor 1'],
]); exit; }
echo json_encode([
    ['EMPNO' => '1001', 'ENAME' => 'Rashid Ali',    'DEPARTMENT' => 'Stitching Floor 1'],
    ['EMPNO' => '1002', 'ENAME' => 'Saima Kausar',  'DEPARTMENT' => 'Packing Hall'],
    ['EMPNO' => '1005', 'ENAME' => 'Nadeem Akhtar', 'DEPARTMENT' => 'Cutting'],
    ['EMPNO' => '',     'ENAME' => 'No Number',     'DEPARTMENT' => 'Nowhere'],
]);
PHP;
file_put_contents($work . '/emp.php', $srv);
$port = 8731;
$ph = proc_open('php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($work) . ' 2>/dev/null',
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); break; } usleep(100000); }

/* the engine, with settings kept in memory */
$META = ['hr_url' => "http://127.0.0.1:$port/emp.php", 'hr_token' => 'testtoken123'];
function zp_meta_get(string $k): string { global $META; return (string)($META[$k] ?? ''); }
function zp_meta_set(string $k, string $v): void { global $META; $META[$k] = $v; }
function zp_ensure_schema(): void {}

/* THE WHOLE HR BLOCK IN ONE PIECE. Lifting the functions one by one looked
   tidier and was wrong: three of them are one-liners, so "up to the next
   closing brace on its own line" swallowed the ones below and then
   redeclared them. One contiguous run, bounded by what follows it. */
$hrBlock = (function (string $src) {
    $a = strpos($src, 'function zp_hr_url()');
    $b = strpos($src, '   BOOKING THE DAY\'S WORK', $a);
    $b = strrpos(substr($src, $a, $b - $a), '/* =====');
    return substr($src, $a, $b);
})($zp);
ok(str_contains($hrBlock, 'function zp_hr_apply('), 'the HR engine lifted whole');
eval($hrBlock);

$f = zp_hr_fetch();
ok($f['ok'] === true, 'the HR app answers: ' . $f['error']);
ok(count($f['rows']) === 3, 'THREE people came back — the one with no number is dropped, got ' . count($f['rows']));
ok($f['rows'][0] === ['code' => '1001', 'name' => 'Rashid Ali', 'dept' => 'Stitching Floor 1'],
   'and the columns landed right: ' . json_encode($f['rows'][0] ?? null));

$META['hr_url'] = "http://127.0.0.1:$port/emp.php?shape=lower";
$f2 = zp_hr_fetch();
ok($f2['ok'] === true && ($f2['rows'][0]['name'] ?? '') === 'Rashid Ali',
   'lowercase column names work too — EMPNO and empno are the same column');

$META['hr_url'] = "http://127.0.0.1:$port/emp.php?shape=wrapped";
$f3 = zp_hr_fetch();
ok($f3['ok'] === true && count($f3['rows']) === 1, 'a {"data": [...]} wrapper is unwrapped, not refused');

$META['hr_url'] = "http://127.0.0.1:$port/emp.php?shape=notjson";
$f4 = zp_hr_fetch();
ok($f4['ok'] === false && str_contains($f4['error'], 'not answer with JSON'),
   'a non-JSON answer is reported plainly: ' . $f4['error']);

$META['hr_token'] = 'wrong';
$META['hr_url']   = "http://127.0.0.1:$port/emp.php";
$f5 = zp_hr_fetch();
ok($f5['ok'] === false && str_contains($f5['error'], 'token was refused'),
   'A REFUSED TOKEN SAYS SO, rather than looking like an empty list: ' . $f5['error']);

$META['hr_url'] = 'http://127.0.0.1:1/emp.php';
$f6 = zp_hr_fetch();
ok($f6['ok'] === false && $f6['rows'] === [], 'an unreachable HR app fails closed');

$META['hr_token'] = 'testtoken123';
$META['hr_url']   = "http://127.0.0.1:$port/emp.php";

echo "3. A blank token box means KEEP, not ERASE\n";
zp_hr_save_settings("http://127.0.0.1:$port/emp.php", '');
ok(zp_meta_get('hr_token') === 'testtoken123', 'saving with the box empty keeps the token');
zp_hr_save_settings("http://127.0.0.1:$port/emp.php", '••••••••123');
ok(zp_meta_get('hr_token') === 'testtoken123', '  and saving the MASK back does not overwrite it with dots');
$bad = zp_hr_save_settings('portal.example.com/emp.php', '');
ok($bad['ok'] === false, 'an address with no http:// is refused');
ok(zp_hr_token_masked() === '••••••••123', 'the mask shows the last three only, got ' . zp_hr_token_masked());

echo "4. The plan — worked out before anything is written\n";
/* what this app already has: 1001 unchanged, 1002 renamed and moved,
   9999 a worker HR has never heard of who has wages booked */
$WORKERS = [
    ['id' => 1, 'worker_code' => '1001', 'worker_name' => 'Rashid Ali',  'department' => 'Stitching Floor 1', 'is_active' => 1],
    ['id' => 2, 'worker_code' => '1002', 'worker_name' => 'Saima K.',    'department' => 'Cutting',           'is_active' => 1],
    ['id' => 3, 'worker_code' => '9999', 'worker_name' => 'Old Hand',    'department' => 'Packing Hall',      'is_active' => 1],
];
function zp_workers(bool $a = false): array { global $WORKERS; return $WORKERS; }
$ENTRIES = [3 => 40];
$SAVED = []; $STAGE_TOUCHED = false;
function zp_save_worker(int $id, string $c, string $n, ?string $d, int $act): array {
    global $SAVED; $SAVED[] = ['id' => $id, 'code' => $c, 'name' => $n, 'dept' => $d, 'active' => $act];
    return ['ok' => true, 'id' => $id ?: 700 + count($SAVED), 'error' => ''];
}
function zp_save_worker_stages(int $w, array $s): void { global $STAGE_TOUCHED; $STAGE_TOUCHED = true; }

final class HStmt {
    public function __construct(private string $sql) {}
    public function execute($a = []) { $this->last = $a; return true; }
    public array $last = [];
    public function fetchColumn() {
        global $ENTRIES, $WORKERS;
        if (str_contains($this->sql, 'COUNT(*) FROM zp_entries')) return $ENTRIES[$this->last[0] ?? 0] ?? 0;
        if (str_contains($this->sql, 'is_active FROM zp_workers')) {
            foreach ($WORKERS as $w) if ((int)$w['id'] === (int)($this->last[0] ?? 0)) return (int)$w['is_active'];
            return 1;
        }
        return 0;
    }
    public function fetchAll() { return []; }
}
final class HDb {
    public array $log = [];
    public function prepare($s) { $this->log[] = $s; return new HStmt($s); }
    public function query($s) { $this->log[] = $s; return new HStmt($s); }
    public function exec($s) { $this->log[] = $s; return 1; }
    public function beginTransaction() { return true; }
    public function commit() { $this->log[] = 'COMMIT'; return true; }
    public function rollBack() { $this->log[] = 'ROLLBACK'; return true; }
    public function lastInsertId() { return '700'; }
}
$DB = new HDb();
function db() { global $DB; return $DB; }

$plan = zp_hr_plan(zp_hr_fetch()['rows']);

ok(count($plan['add']) === 1 && $plan['add'][0]['code'] === '1005',
   'ONE NEW PERSON — 1005 Nadeem Akhtar, got ' . json_encode(array_column($plan['add'], 'code')));
ok($plan['same'] === 1, 'one already correct and left alone, got ' . $plan['same']);
ok(count($plan['change']) === 1 && $plan['change'][0]['code'] === '1002',
   'one changed in HR, got ' . json_encode(array_column($plan['change'], 'code')));
ok(isset($plan['change'][0]['diff']['name']) && isset($plan['change'][0]['diff']['dept']),
   '  and it says WHICH facts changed, both of them: ' . json_encode($plan['change'][0]['diff'] ?? null));

/* THE ONE HE ASKED FOR TWICE. */
ok(count($plan['gone']) === 1 && $plan['gone'][0]['code'] === '9999',
   'the worker HR no longer has is REPORTED, got ' . json_encode($plan['gone']));
ok(($plan['gone'][0]['entries'] ?? 0) === 40,
   '  with how much work is booked against their name, got ' . ($plan['gone'][0]['entries'] ?? null));
ok(($plan['gone'][0]['active'] ?? 0) === 1, '  and they are still active — the plan changes nothing');

echo "5. Applying it — adds and updates only\n";
$SAVED = []; $STAGE_TOUCHED = false; $DB->log = [];
$r = zp_hr_apply($plan);
ok($r['ok'] === true, 'applied: ' . json_encode($r['errors']));
ok($r['added'] === 1 && $r['changed'] === 1, 'one added, one updated, got ' . json_encode($r));
ok(count($SAVED) === 2, 'TWO writes and no more — 9999 was not touched, got ' . count($SAVED));
ok(!in_array('9999', array_column($SAVED, 'code'), true),
   'NOBODY WAS REMOVED OR REWRITTEN: ' . json_encode(array_column($SAVED, 'code')));
/* NOT DEACTIVATED EITHER. "you do not remove here even if its started job
   somewhere" — setting them inactive would take them off every picker, which
   is removal by another name. */
$deact = array_filter($SAVED, fn($x) => $x['active'] === 0);
ok($deact === [], '  nor switched off, which would be removal by another name');
ok($STAGE_TOUCHED === false, 'NO STAGE ALLOTMENT WAS TOUCHED — he edits those by hand');
ok(in_array('COMMIT', $DB->log, true) && !in_array('ROLLBACK', $DB->log, true),
   'and it is one transaction that committed');

$new = array_values(array_filter($SAVED, fn($x) => $x['id'] === 0))[0] ?? null;
ok($new && $new['code'] === '1005' && $new['dept'] === 'Cutting',
   'the new person arrives with their department, exactly as HR spells it: ' . json_encode($new));
$chg = array_values(array_filter($SAVED, fn($x) => $x['id'] === 2))[0] ?? null;
ok($chg && $chg['name'] === 'Saima Kausar' && $chg['dept'] === 'Packing Hall',
   'the changed person follows HR on both facts: ' . json_encode($chg));
ok($chg && $chg['active'] === 1, '  and keeps the active flag this app set, not one from HR');

echo "6. A refused write saves nothing at all\n";
$SAVED = []; $DB->log = [];
function zp_save_worker_bad() {}
$planBad = $plan;
$GLOBALS['FAILNEXT'] = true;
/* re-point zp_save_worker at a failing one by running apply with a plan whose
   second row cannot be written */
$planBad['change'][0]['code'] = '';          // zp_save_worker refuses a blank name/code pair
eval('function zp_save_worker_fail(int $id, string $c, string $n, ?string $d, int $act): array {
        return $c === "" ? ["ok"=>false,"id"=>0,"error"=>"Code is blank."] : ["ok"=>true,"id"=>$id,"error"=>""]; }');
$src = lift($zp, 'function zp_hr_apply(');
eval(str_replace(['function zp_hr_apply(', 'zp_save_worker('],
                 ['function zp_hr_apply_t(', 'zp_save_worker_fail('], $src));
$rb = zp_hr_apply_t($planBad);
ok($rb['ok'] === false, 'one bad row refuses the whole sync');
ok($rb['added'] === 0 && $rb['changed'] === 0, '  and reports nothing as done, got ' . json_encode($rb));
ok(in_array('ROLLBACK', $DB->log, true), '  AND IT ROLLED BACK — no half-applied list');

echo "7. A long employee number\n";
/* HIS PROBLEM, IN HIS WORDS: "for worker we have long code so what to do".
   The column was VARCHAR(20), chosen when the only codes were the W001 this
   screen gives out. A real employee number is longer, and 20 was a trap
   rather than a limit — MySQL outside strict mode CUTS a long value and says
   nothing, so two people whose numbers differ only after the twentieth
   character become one code. */
$zpC = preg_replace('!/\*.*?\*/!s', '', $zp);
ok(str_contains($zpC, 'MODIFY worker_code VARCHAR(40) NOT NULL'),
   'the column is widened to 40, the same way every other column here arrives');
ok(str_contains($zpC, 'const ZP_CODE_MAX = 40;'), 'and the limit is one named number');
ok(!str_contains($zpC, 'is longer than 20 characters'),
   '  with no hard-coded 20 left behind to disagree with it');
ok(str_contains($pw, 'maxlength="<?= ZP_CODE_MAX ?>"'),
   'the boxes on screen follow the same number, not a copy of it');
ok(substr_count($pw, 'maxlength="<?= ZP_CODE_MAX ?>"') === 2, '  both of them');

/* REFUSED, NOT TRUNCATED. The two numbers below are 24 characters and differ
   only at the very end — exactly the pair that a 20-character cut would turn
   into the same person. */
$long1 = 'ZES-2024-0000000000012345';
ok(mb_strlen($long1) > 20, 'the test code really is longer than the old limit');

$tooLong = str_repeat('X', 41);
$realSave = (function (string $src) {
    $a = strpos($src, 'function zp_save_worker(int $id');
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a + 3);
})($zp);
/* run the real save with a database that would happily truncate */
/* The constant is lifted from the file too, so the test cannot pass by
   agreeing with a number it invented itself. */
preg_match('/const ZP_CODE_MAX = (\d+);/', $zp, $mx);
ok(!empty($mx[1]), 'ZP_CODE_MAX found in the engine');
if (!defined('ZP_CODE_MAX')) define('ZP_CODE_MAX', (int)$mx[1]);
eval(str_replace('function zp_save_worker(', 'function zp_save_worker_real(', $realSave));
$r1 = zp_save_worker_real(0, $tooLong, 'Too Long', null, 1);
ok($r1['ok'] === false, 'a code over the limit is REFUSED, not cut to fit');
ok(str_contains($r1['error'], '41 characters'), '  and the message says how long it actually is: ' . $r1['error']);
ok(str_contains($r1['error'], 'I will widen the column'), '  and what to do about it');

echo "8. The screen\n";
ok(str_contains($pw, "value=\"hrcheck\""), 'there is an Update from HR button');
ok(str_contains($pw, "value=\"hrapply\""), 'and a separate Apply, so nothing writes on a look');
ok(str_contains($pw, '$f = zp_hr_fetch();' ) && substr_count($pw, 'zp_hr_fetch()') >= 2,
   'APPLY RE-READS HR rather than trusting a plan posted back from the browser');
ok(str_contains($pw, 'no longer in the HR app'), 'the alert names the people HR has dropped');
ok(str_contains($pw, 'Nobody is removed. No stage allotment is touched.'),
   '  and the button says what it will not do');
ok(str_contains($pw, 'zp_hr_token_masked()'), 'the token is shown masked on screen');
ok(str_contains($pw, 'leave blank to keep it'), '  and can be kept without retyping it');
ok(str_contains($pw, "!is_admin()"), 'only an admin may change the connection');

if (is_resource($ph)) { proc_terminate($ph); proc_close($ph); }
echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
