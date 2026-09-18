<?php
/* DELETING A WORKER — what actually stops it.
 *
 * His words:
 *   "so this rule is fine but i entered production but later deleted
 *    production so if not active production and any payment so then please
 *    allow delete"
 *
 * He found a hole. The old rule counted EVERY row in zp_entries, cancelled
 * ones included — so a worker entered by mistake, given one test entry and
 * then corrected by cancelling it, could never be removed. The app pointed
 * at a cancelled entry as its reason, which is to say at nothing.
 *
 * The real function is lifted out of includes/zprod.php and run against a
 * database that records every statement, so what it asks and what it writes
 * are both measured.
 */
$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$zp = file_get_contents($B . 'includes/zprod.php');

$fn = (function (string $src) {
    $a = strpos($src, 'function zp_delete_worker(');
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a + 3);
})($zp);

/* A fake PDO whose answers are set per test, and which logs every statement
   so "what did it actually delete" is a fact, not a hope. */
$ANS = ['active' => 0, 'cancelled' => 0];
$LOG = [];
final class DStmt {
    public function __construct(private string $sql) {}
    public function execute($a = []) { global $LOG; $LOG[] = [$this->sql, $a]; return true; }
    public function fetchColumn() {
        global $ANS;
        if (str_contains($this->sql, "status='active'"))  return $ANS['active'];
        if (str_contains($this->sql, "status<>'active'")) return $ANS['cancelled'];
        return 0;
    }
    public function fetchAll() { return []; }
}
final class DDb {
    public function prepare($s) { return new DStmt($s); }
    public function query($s)   { return new DStmt($s); }
    public function exec($s)    { return 1; }
    public function beginTransaction() { global $LOG; $LOG[] = ['BEGIN', []]; return true; }
    public function commit()    { global $LOG; $LOG[] = ['COMMIT', []]; return true; }
    public function rollBack()  { global $LOG; $LOG[] = ['ROLLBACK', []]; return true; }
}
$DB = new DDb();
function db() { global $DB; return $DB; }
function zp_ensure_schema(): void {}
eval($fn);

function wrote(string $needle): bool {
    global $LOG;
    foreach ($LOG as $r) if (str_contains($r[0], $needle)) return true;
    return false;
}

echo "1. A worker with LIVE production is kept\n";
$ANS = ['active' => 12, 'cancelled' => 3]; $LOG = [];
$r = zp_delete_worker(5);
ok($r['ok'] === true && $r['deleted'] === false, 'not deleted');
ok(wrote('UPDATE zp_workers SET is_active=0'), '  set inactive instead');
ok(!wrote('DELETE FROM zp_workers'), '  AND NOTHING WAS DELETED');
ok(!wrote('DELETE FROM zp_entries'), '  their entries are untouched');
ok(str_contains($r['msg'], '12 live production entries'),
   '  the message counts the LIVE ones only, got: ' . $r['msg']);
ok(str_contains($r['msg'], 'Cancel those entries first'),
   '  and says how to get to a delete: ' . $r['msg']);

echo "2. THE ONE HE ASKED FOR — entered, then cancelled, so let it go\n";
$ANS = ['active' => 0, 'cancelled' => 4]; $LOG = [];
$r2 = zp_delete_worker(6);
ok($r2['ok'] === true && $r2['deleted'] === true,
   'DELETED — no live production, so nothing is owed to anybody');
ok(wrote('DELETE FROM zp_workers'), '  the worker row goes');
ok(wrote("DELETE FROM zp_entries WHERE worker_id=? AND status<>'active'"),
   '  and the cancelled entries go with them, rather than pointing at nobody');
ok(wrote('DELETE FROM zp_worker_stage'), '  as does the stage allotment');
ok(str_contains($r2['msg'], '4 cancelled entries were removed'),
   '  AND IT SAYS SO — nothing disappears quietly: ' . $r2['msg']);
/* THE LIVE ROWS OF OTHER WORKERS ARE NOT IN RANGE. The delete is scoped by
   worker AND by status, both. */
$del = array_values(array_filter($GLOBALS['LOG'], fn($r) => str_contains($r[0], 'DELETE FROM zp_entries')))[0];
ok(str_contains($del[0], 'worker_id=?') && str_contains($del[0], "status<>'active'"),
   '  scoped by worker AND by status, both: ' . $del[0]);
ok($del[1] === [6], '  and bound to this worker, got ' . json_encode($del[1]));

echo "3. A worker who never worked\n";
$ANS = ['active' => 0, 'cancelled' => 0]; $LOG = [];
$r3 = zp_delete_worker(7);
ok($r3['deleted'] === true, 'deleted');
ok(!wrote('DELETE FROM zp_entries'), '  and no pointless delete is run: ' . $r3['msg']);
ok($r3['msg'] === 'Worker deleted — they had no entries.', '  plain message, got: ' . $r3['msg']);

echo "4. It is one transaction\n";
$ANS = ['active' => 0, 'cancelled' => 2]; $LOG = [];
zp_delete_worker(8);
$order = array_map(fn($r) => $r[0], $LOG);
$begin = array_search('BEGIN', $order, true);
$commit = array_search('COMMIT', $order, true);
ok($begin !== false && $commit !== false && $begin < $commit,
   'BEGIN before COMMIT, so a half-deleted worker cannot exist');
foreach (['DELETE FROM zp_entries', 'DELETE FROM zp_workers', 'DELETE FROM zp_worker_stage'] as $d) {
    $at = -1;
    foreach ($order as $i => $q) if (str_contains($q, $d)) { $at = $i; break; }
    ok($at > $begin && $at < $commit, "  $d happens inside it");
}

echo "5. The rule is written down\n";
$zpF = preg_replace('/\s+/', ' ', $zp);
ok(str_contains($zpF, 'WHAT STOPS A WORKER BEING DELETED IS MONEY, NOT HISTORY'),
   'the reason is stated where the next person will read it');
ok(str_contains($zpF, 'a cancelled entry carries no money'),
   '  including why a cancelled entry does not count');
ok(!preg_match('/COUNT\(\*\) FROM zp_entries WHERE worker_id=\?"/', $zp),
   'THE OLD UNFILTERED COUNT IS GONE — it was the whole bug');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
