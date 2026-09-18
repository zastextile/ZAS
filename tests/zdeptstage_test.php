<?php
/* ALLOTTING STAGES TO A WHOLE FLOOR AT ONCE.
 *
 * His words, after syncing 200+ people out of the HR app:
 *   "if worker related to stage and that product / operation is being done
 *    then show related stage worker in list otherwise do not show all long
 *    worker list in every production data entry"
 *   "each worker edit then choose stage dificult for me for the first time
 *    so guide me to apply stage as ease"
 *   "some worker are not related to direct production so do not want show
 *    them on production pages if no relevant stage"
 *
 * Two things had to change, and the second is the reason the first was
 * useless: the rule "no stage allotted means every stage" was right while
 * the table was empty and wrong the moment 200 people arrived with none.
 */
$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$zp = file_get_contents($B . 'includes/zprod.php');
$pw = file_get_contents($B . 'production_workers.php');
$pe = file_get_contents($B . 'production_entry.php');

function lift(string $src, string $from): string {
    $a = strpos($src, $from);
    if ($a === false) return '';
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a + 3);
}

echo "1. No stage allotted now means NOT PRODUCTION\n";
eval(lift($zp, 'function zp_worker_does_stage('));
$map = [1 => [10], 2 => [10, 20], 3 => [20]];      // 4 and 5 are allotted nothing
ok(zp_worker_does_stage($map, 1, 10) === true,  'a cutter is offered Cutting');
ok(zp_worker_does_stage($map, 1, 20) === false, '  and not Stitching');
ok(zp_worker_does_stage($map, 2, 10) === true && zp_worker_does_stage($map, 2, 20) === true,
   'somebody on both is offered both — multiple stages still work');
/* THE CHANGE. */
ok(zp_worker_does_stage($map, 4, 10) === false,
   'THE MAN IN THE KITCHEN, allotted nothing, is NOT offered Cutting');
ok(zp_worker_does_stage($map, 4, 20) === false, '  nor Stitching');
/* AND THE ONE EXCEPTION THAT MUST SURVIVE. */
ok(zp_worker_does_stage($map, 4, 0) === true,
   'an operation with NO stage still asks nobody to prove anything');

echo "2. The browser says exactly the same thing\n";
$wf = (function (string $src) {
    $a = strpos($src, '  function wFits(w, sid){');
    $b = strpos($src, "\n  }\n", $a);
    return substr($src, $a, $b - $a + 4);
})($pe);
ok($wf !== '', 'wFits lifted from the entry screen');
ok(!str_contains($wf, 'if (!w.sg || !w.sg.length) return true;'),
   'THE OLD "nothing means everything" LINE IS GONE from the browser too');
ok(str_contains($wf, "return !!(w.sg && w.sg.length && w.sg.indexOf(sid) >= 0);"),
   '  and it now asks the same question the server asks');

echo "3. But a stage nobody is on yet still offers the whole floor\n";
/* THE SAFETY NET. Without it, uploading this file makes every production
   list empty until 200 people have been allotted one by one — which is the
   opposite of what he asked for. */
ok(str_contains($pe, 'var onStage = 0;'), 'the entry screen counts who NAMES the stage');
ok(str_contains($pe, 'if (onStage) { narrowed = all.length - fit.length; use = fit; }'),
   '  and narrows only when somebody does');
$peF = preg_replace('/\s+/', ' ', $pe);
ok(str_contains($peF, 'type a name or code to reach anybody'),
   'and the cut is said, with everybody still reachable by typing');

echo "4. The picture, department by department\n";
$WORKERS = [
    ['id'=>1,'worker_code'=>'136','worker_name'=>'ABDUL REHMAN','department'=>'MADEUPS UNI # 1','is_active'=>1],
    ['id'=>2,'worker_code'=>'145','worker_name'=>'ABDULLAH',    'department'=>'MADEUPS UNI # 1','is_active'=>1],
    ['id'=>3,'worker_code'=>'406','worker_name'=>'ABDUL REHMAN','department'=>'HEAD OFFICE',    'is_active'=>1],
    ['id'=>4,'worker_code'=>'439','worker_name'=>'AHMAD',       'department'=>'KITCHEN UNIT # 1','is_active'=>1],
    ['id'=>5,'worker_code'=>'620','worker_name'=>'ABDUL REHMAN','department'=>'GARMENT UNIT # 2','is_active'=>1],
    ['id'=>6,'worker_code'=>'999','worker_name'=>'NO DEPT',     'department'=>'',                'is_active'=>1],
    ['id'=>7,'worker_code'=>'888','worker_name'=>'SWITCHED OFF','department'=>'MADEUPS UNI # 1','is_active'=>0],
];
$WSTAGE = [1 => [10]];                         // one cutter so far
function zp_workers(bool $activeOnly = false): array {
    global $WORKERS;
    return $activeOnly ? array_values(array_filter($WORKERS, fn($w) => (int)$w['is_active'] === 1)) : $WORKERS;
}
function zp_worker_stage_map(): array { global $WSTAGE; return $WSTAGE; }
function zp_stage_all(bool $a = false): array {
    return [['id'=>10,'name'=>'Cutting'], ['id'=>20,'name'=>'Stitching'], ['id'=>30,'name'=>'Packing']];
}
$SAVED = [];
function zp_save_worker_stages(int $wid, array $sids): void {
    global $SAVED, $WSTAGE; sort($sids); $SAVED[$wid] = $sids; $WSTAGE[$wid] = $sids;
}
function zp_ensure_schema(): void {}
final class SStmt { public function execute($a=[]){return true;} public function fetchAll(){return [];} public function fetchColumn(){return 0;} }
final class SDb {
    public array $log = [];
    public function prepare($s){ $this->log[]=$s; return new SStmt(); }
    public function query($s){ return new SStmt(); }
    public function exec($s){ return 1; }
    public function beginTransaction(){ $this->log[]='BEGIN'; return true; }
    public function commit(){ $this->log[]='COMMIT'; return true; }
    public function rollBack(){ $this->log[]='ROLLBACK'; return true; }
}
$DB = new SDb();
function db(){ global $DB; return $DB; }

eval(lift($zp, 'function zp_dept_stage_picture('));
eval(lift($zp, 'function zp_apply_dept_stages('));

$pic = zp_dept_stage_picture();
ok(array_keys($pic) === ['(no department)','GARMENT UNIT # 2','HEAD OFFICE','KITCHEN UNIT # 1','MADEUPS UNI # 1'],
   'departments listed, sorted naturally, got ' . json_encode(array_keys($pic)));
ok($pic['MADEUPS UNI # 1']['n'] === 2,
   'THE SWITCHED-OFF WORKER IS NOT COUNTED, got ' . $pic['MADEUPS UNI # 1']['n']);
ok($pic['MADEUPS UNI # 1']['stages'][10] === 1 && $pic['MADEUPS UNI # 1']['none'] === 1,
   '  one of the two is on Cutting, one on nothing: ' . json_encode($pic['MADEUPS UNI # 1']));
ok(isset($pic['(no department)']) && $pic['(no department)']['n'] === 1,
   'A WORKER WITH NO DEPARTMENT GETS THEIR OWN ROW — otherwise nobody could ever reach them');
ok($pic['HEAD OFFICE']['none'] === 1, 'head office has nobody on a stage');

echo "5. Applying a whole department\n";
$SAVED = [];
$r = zp_apply_dept_stages([
    'MADEUPS UNI # 1'  => [10, 20],
    'GARMENT UNIT # 2' => [20, 30],
    'HEAD OFFICE'      => [],            // deliberately nothing — they do no production
], true);
ok($r['ok'] === true, 'applied: ' . $r['error']);
ok($r['people'] === 4 && $r['depts'] === 3,
   'FOUR people across three departments, got ' . json_encode([$r['people'], $r['depts']]));
ok(($SAVED[1] ?? null) === [10, 20] && ($SAVED[2] ?? null) === [10, 20],
   'both madeups workers got both stages: ' . json_encode([$SAVED[1] ?? null, $SAVED[2] ?? null]));
ok(($SAVED[5] ?? null) === [20, 30], 'the garment unit got its two: ' . json_encode($SAVED[5] ?? null));
ok(array_key_exists(3, $SAVED) && $SAVED[3] === [],
   'HEAD OFFICE IS WRITTEN AS EMPTY — "no production" is a real answer, not a skipped row');
ok(!array_key_exists(7, $SAVED), 'the switched-off worker was not touched');
ok(!array_key_exists(4, $SAVED), 'a department not on the form is left alone');
ok(in_array('COMMIT', $DB->log, true), 'one transaction, committed');

echo "6. Add, rather than replace\n";
$WSTAGE = [1 => [10], 2 => [20]];
$SAVED = [];
$r2 = zp_apply_dept_stages(['MADEUPS UNI # 1' => [30]], false);
ok($r2['ok'] === true, 'applied');
ok(($SAVED[1] ?? null) === [10, 30], 'the cutter KEEPS Cutting and gains Packing: ' . json_encode($SAVED[1] ?? null));
ok(($SAVED[2] ?? null) === [20, 30], '  and the stitcher keeps Stitching: ' . json_encode($SAVED[2] ?? null));

echo "7. Nonsense is dropped, not written\n";
$WSTAGE = []; $SAVED = [];
zp_apply_dept_stages(['MADEUPS UNI # 1' => [10, 999, 0, -4]], true);
ok(($SAVED[1] ?? null) === [10], 'a stage id that does not exist is dropped: ' . json_encode($SAVED[1] ?? null));
$SAVED = [];
$r3 = zp_apply_dept_stages(['A DEPARTMENT THAT IS NOT THERE' => [10]], true);
ok($r3['ok'] === true && $r3['people'] === 0 && $SAVED === [],
   'a department with nobody in it writes nothing');

echo "8. The screen\n";
ok(str_contains($pw, 'value="deptstage"'), 'the department form posts its own action');
ok(str_contains($pw, 'name="dept[]"'), 'EVERY department on the form is submitted');
ok(str_contains($pw, 'name="stage[<?= e($dept) ?>][]"'), '  with its ticks named per department');
ok(str_contains($pw, '$want[$d] = array_map'),
   'so a department with nothing ticked is still acted on — that is how "no production" is said');
ok(str_contains($pw, 'these people do no production'), 'and the screen says what an empty row means');
ok(str_contains($pw, '$all = $have > 0 && $have === $p[\'n\'];'),
   'a box is pre-ticked only when EVERYBODY in the department already has it');
ok(str_contains($pw, 'Still offering everybody:'),
   'the screen names the stages that are not narrowed yet, so the first Apply does not look broken');
$pwF = preg_replace('/\s+/', ' ', $pw);
ok(str_contains($pwF, 'NO STAGE TICKED MEANS NOT PRODUCTION'),
   'the rule at the top of the file was changed with the code');
ok(!str_contains($pwF, 'NO STAGE TICKED MEANS EVERY STAGE'), '  and the old one is gone');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
