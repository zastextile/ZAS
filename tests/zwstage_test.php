<?php
/* WORKER STAGES — a short, relevant list on a floor of three hundred.
 *
 * The whole feature rests on ONE rule, and the rule is the dangerous part:
 * nothing allotted must mean EVERY stage. The table ships empty, so if that
 * default were the other way round the entry screen would offer nobody the
 * day the file was uploaded and the floor would stop.
 *
 * So the rule is tested twice — once as PHP decides it, once as the browser
 * decides it — because two copies of a rule is exactly how the two sides
 * drift apart. The browser copy is LIFTED out of the shipped page and RUN,
 * not read.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$work = __DIR__ . '/.zws';
@mkdir($work, 0777, true);

$zp = file_get_contents($B . 'includes/zprod.php');
$pw = file_get_contents($B . 'production_workers.php');
$pe = file_get_contents($B . 'production_entry.php');

/* ------------------------------------------------------------------ */
echo "1. The table, and what an empty one means\n";

ok(str_contains($zp, 'CREATE TABLE IF NOT EXISTS zp_worker_stage'), 'zp_worker_stage is created');
ok(str_contains($zp, 'UNIQUE KEY uniq_ws (worker_id, stage_id)'),
   '  one row per worker per stage, so a double tick cannot double-count');
ok(str_contains($zp, 'NO ROW MEANS EVERY STAGE'), '  and the default is written down where the table is made');

/* THE RULE, RUN. zp_worker_does_stage() is pure — it takes the map — so it
   lifts out and runs with no database at all. */
$a = strpos($zp, 'function zp_worker_does_stage');
$b = strpos($zp, "\n}", $a);
$fn = substr($zp, $a, $b - $a + 2);
/* THE RULE CHANGED ON 18 SEP, deliberately, and this file changed with it.
   It used to be "nothing allotted means every stage", which was right while
   the table was empty. Then 200 people arrived from the HR app with no
   stages and every one of them passed every stage, so the allotment
   narrowed nothing. It is now "nothing allotted means not production" — and
   the safety that kept day one working MOVED to the caller rather than
   going away. Both halves are checked below. */
ok($fn !== '' && str_contains($fn, "return \$mine ? in_array(\$stageId, \$mine, true) : false;"),
   'the rule lifted');
eval($fn);

$map = [7 => [2, 3], 9 => [2]];          // 7 does Stitching+Packing, 9 does Stitching
ok(zp_worker_does_stage($map, 7, 2) === true,  'an allotted worker is offered on their stage');
ok(zp_worker_does_stage($map, 7, 3) === true,  '  on every one of their stages');
ok(zp_worker_does_stage($map, 9, 3) === false, 'and not offered on a stage they are not on');
/* THE ONE THAT MATTERS. */
ok(zp_worker_does_stage($map, 42, 3) === false,
   'A WORKER WITH NOTHING ALLOTTED IS NOT PRODUCTION — the man in the kitchen');
ok(zp_worker_does_stage([], 7, 3) === false,
   'and this function alone would offer nobody from an empty table — which is why');
ok(zp_worker_does_stage($map, 9, 0) === true,  'an operation with no stage asks nobody to prove anything');

/* ------------------------------------------------------------------ */
echo "2. Saving the ticks\n";

/* zp_save_worker_stages lifted and run against a fake PDO, so what it really
   sends to the database is inspected rather than assumed. */
$save = substr($zp, strpos($zp, 'function zp_save_worker_stages'));
$save = substr($save, 0, strpos($save, "\n}") + 2);
ok(str_contains($save, 'DELETE FROM zp_worker_stage WHERE worker_id=?'),
   'it clears first, so unticking really unticks');

final class FakeStmt {
    public array $runs = [];
    public function __construct(private string $sql, private object $db) {}
    public function execute(array $a = []): bool { $this->db->log[] = [$this->sql, $a]; return true; }
    public function fetchAll(): array { return []; }
    public function fetchColumn() { return 0; }
}
final class FakeDb {
    public array $log = [];
    public function prepare(string $s) { return new FakeStmt($s, $this); }
    public function query(string $s) { return new FakeStmt($s, $this); }
    public function exec(string $s) { return 1; }
}
$DB = new FakeDb();
function db() { global $DB; return $DB; }
function zp_ensure_schema(): void {}
function zp_stage_all(bool $a = false): array {
    return [['id' => 1, 'name' => 'Cutting'], ['id' => 2, 'name' => 'Stitching'],
            ['id' => 3, 'name' => 'Packing']];
}
eval($save);

$DB->log = [];
zp_save_worker_stages(7, ['2', '3', '2', '99', '0', '']);   // dupes, a dead stage, junk
$del = array_filter($DB->log, fn($r) => str_contains($r[0], 'DELETE'));
$ins = array_values(array_filter($DB->log, fn($r) => str_contains($r[0], 'INSERT')));
ok(count($del) === 1, 'one delete, for that worker only: ' . count($del));
ok(count($ins) === 2, 'two inserts — the duplicate 2 is not written twice, got ' . count($ins));
$got = array_map(fn($r) => $r[1][1], $ins);
sort($got);
ok($got === [2, 3], '  and they are the two real stages, got ' . json_encode($got));
ok(!in_array(99, $got, true), '  a stage that no longer exists is dropped, not saved');

$DB->log = [];
zp_save_worker_stages(7, []);
ok(count(array_filter($DB->log, fn($r) => str_contains($r[0], 'DELETE'))) === 1
   && !array_filter($DB->log, fn($r) => str_contains($r[0], 'INSERT')),
   'unticking everything deletes and inserts nothing — back to "any stage"');

$DB->log = [];
zp_save_worker_stages(0, [2]);
ok($DB->log === [], 'a worker id of 0 writes nothing at all');

/* ------------------------------------------------------------------ */
echo "3. The screen saves against the right worker\n";

/* A NEW worker posts id 0. Saving the ticks against the posted id would write
   them against nobody — they have to go against the id the save returned. */
ok(preg_match('/zp_save_worker_stages\(\(int\)\$r\[.id.\]/', $pw) === 1,
   'the ticks are saved against the id zp_save_worker returned, not the posted one');
ok(strpos($pw, 'zp_save_worker_stages') < strpos($pw, "redirect('production_workers.php')"),
   '  and before the redirect, or nothing would be written');
ok(str_contains($pw, 'name="stage[]"'), 'the form posts the ticks');
ok(str_contains($pw, 'type="checkbox"'), '  as real checkboxes, so it works with no JavaScript');
ok(substr_count($pw, 'csrf_field()') >= 2, 'every form on the page is still CSRF-guarded');
ok(str_contains(preg_replace('/\s+/', ' ', $pw), 'NO STAGE TICKED MEANS NOT PRODUCTION'),
   'the rule is stated on the screen that sets it');

/* The delete path must take the allotment with it, or it re-attaches to
   whoever next gets that auto-increment id. */
ok(str_contains($zp, 'DELETE FROM zp_worker_stage WHERE worker_id=?'), 'deleting a worker clears their ticks');
$d = strpos($zp, 'function zp_delete_worker');
ok(strpos($zp, 'DELETE FROM zp_worker_stage', $d) > $d, '  in zp_delete_worker itself');

/* ------------------------------------------------------------------ */
echo "4. The stage id travels with the work row\n";

ok(str_contains($zp, "'sid'  => \$stageId,"), 'zp_work_index carries the stage id');
ok(str_contains($zp, 'THE STAGE ID TRAVELS WITH THE ROW'), '  and says why a name would not do');
/* Renaming a stage must not unpick anybody. The id is what is matched on both
   sides, so this is a check that no name comparison crept in. */
ok(!preg_match('/wFits\([^)]*\)\s*\{[^}]*\.st\b/s', $pe), 'the browser never matches a worker on a stage NAME');

/* ------------------------------------------------------------------ */
echo "5. The narrowing, run in a browser\n";

/* wFits and the tab-A branch of sugList are LIFTED out of the shipped page. */
$wa = strpos($pe, 'function wFits(w, sid){');
$wb = strpos($pe, "\n  }", $wa);
$wFits = substr($pe, $wa, $wb - $wa + 4);
ok(str_contains($wFits, 'return !!(w.sg && w.sg.length && w.sg.indexOf(sid) >= 0);'),
   'the browser copy of the rule lifted, and it says the same thing');

/* The lift starts at opId, not at the filter below it — the branch declares
   opId and uses it in the sort, so starting one line later lifted code that
   could not run. Caught by the harness, which runs it. */
$sa = strpos($pe, "      var opId = picked ? picked.op : 0;");
$sb = strpos($pe, "      return outw;", $sa);
$branch = substr($pe, $sa, $sb - $sa);
ok($branch !== '' && str_contains($branch, 'wFits(w, sid)'), 'the narrowing branch lifted');
ok(!str_contains($branch, '<?'), '  with no PHP left in it');

$WORKERS = [];
/* 300 stitchers, 26 packers, 5 with nothing allotted at all. */
for ($i = 1; $i <= 300; $i++)
    $WORKERS[] = ['id' => $i, 'code' => 'W' . $i, 'name' => 'Stitcher ' . $i, 'dept' => 'Stitching Floor',
                  'sg' => [2], 'hay' => strtolower('w' . $i . ' stitcher ' . $i . ' stitching floor stitching')];
for ($i = 301; $i <= 326; $i++)
    $WORKERS[] = ['id' => $i, 'code' => 'W' . $i, 'name' => 'Packer ' . $i, 'dept' => 'Packing Hall',
                  'sg' => [3], 'hay' => strtolower('w' . $i . ' packer ' . $i . ' packing hall packing')];
for ($i = 327; $i <= 331; $i++)
    $WORKERS[] = ['id' => $i, 'code' => 'W' . $i, 'name' => 'Newcomer ' . $i, 'dept' => '',
                  'sg' => [], 'hay' => strtolower('w' . $i . ' newcomer ' . $i)];

$harness = '<!doctype html><meta charset="utf-8"><body><scr' . 'ipt>'
  . 'var WORKERS = ' . json_encode($WORKERS) . ';'
  . 'var picked = null, t = [];'
  . 'function esc(s){ return String(s); }'
  . 'function score(){ return 0; }'
  . 'function usualOps(){ return 0; }'
  . 'function hit(hay, terms){ for (var i=0;i<terms.length;i++) if (hay.indexOf(terms[i])<0) return false; return true; }'
  . 'function zeWorkerOpt(w){ return { v:w.id, title:w.name }; }'
  . $wFits
  . 'function run(p, text){'
  . '  picked = p; t = (text||"").trim().toLowerCase().split(/\\s+/).filter(Boolean);'
  . '  var click = function(){ return ""; };'
  . $branch
  . '  return { n: outw.length, foot: outw.foot || "", ids: outw.map(function(o){ return o.v; }) };'
  . '}'
  . 'var PACK = {op:1, sid:3, st:"Packing"}, STITCH = {op:2, sid:2, st:"Stitching"},'
  . '    NOSTAGE = {op:3, sid:0, st:""}, GHOST = {op:4, sid:9, st:"Gift Packing"};'
  . 'window.R = {'
  . '  packEmpty:   run(PACK, ""),'
  . '  stitchEmpty: run(STITCH, ""),'
  . '  noStage:     run(NOSTAGE, ""),'
  . '  ghost:       run(GHOST, ""),'
  . '  typedName:   run(PACK, "stitcher 7"),'
  . '  typedCode:   run(PACK, "w150"),'
  . '  nobody:      run(PACK, "zzz")'
  . '};'
  . '</scr' . 'ipt></body>';
file_put_contents($work . '/live.html', $harness);

$drive = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const pg = await br.newPage();
  const errs = []; pg.on('pageerror', e => errs.push(String(e)));
  await pg.goto('file://' + process.argv[2] + '/live.html');
  await pg.waitForTimeout(120);
  const R = await pg.evaluate(() => window.R || null);
  await br.close();
  console.log(JSON.stringify({ errs, R }));
})();
JS;
file_put_contents($work . '/drive.js', $drive);
$raw = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && node ' . escapeshellarg($work . '/drive.js')
                  . ' ' . escapeshellarg($work) . ' 2>&1');
$J = json_decode((string)$raw, true);

if (!is_array($J) || !is_array($J['R'] ?? null)) {
    echo "  FAIL: the driver did not run:\n" . substr((string)$raw, 0, 900) . "\n"; $F++;
} else {
    $R = $J['R'];
    ok(empty($J['errs']), 'the lifted branch runs clean: ' . json_encode($J['errs']));

    /* THE WHOLE POINT, MEASURED. 331 names down to 31 on a packing job:
       the 26 packers plus the 5 who are allotted nothing. */
    /* 26 packers out of 331 people. The five newcomers allotted nothing are
       NOT in it any more — that is the whole change, and it is what he asked
       for: "some worker are not related to direct production so do not want
       show them on production pages if no relevant stage". */
    ok($R['packEmpty']['n'] === 26,
       'a packing job offers the 26 packers out of 331, got ' . $R['packEmpty']['n']);
    ok(str_contains($R['packEmpty']['foot'], '305 not on Packing'),
       '  and says the other 305 are there, got "' . $R['packEmpty']['foot'] . '"');
    ok(!in_array(327, $R['packEmpty']['ids'], true),
       '  THE NEWCOMER WITH NOTHING ALLOTTED IS NOT IN THE LIST');
    ok(!in_array(1, $R['packEmpty']['ids'], true), '  and a stitcher is not');

    ok($R['stitchEmpty']['n'] === 300, 'a stitching job offers the 300 stitchers, got ' . $R['stitchEmpty']['n']);

    /* An operation with no stage narrows nothing — and neither does a stage
       nobody is on, which would otherwise empty the list completely. */
    ok($R['noStage']['n'] === 331, 'an operation with no stage offers everybody, got ' . $R['noStage']['n']);
    ok($R['noStage']['foot'] === '', '  with nothing to explain');
    /* THE SAFETY NET, AND IT IS THE REASON THE CHANGE IS SAFE TO UPLOAD.
       A stage nobody has been put on yet still offers EVERYBODY — all 331,
       newcomers included. So the day this goes up, every stage that has not
       been set up yet behaves exactly as it did before, and he can do one
       floor at a time instead of 200 forms before anything works. */
    ok($R['ghost']['n'] === 331,
       'A STAGE NOBODY IS ALLOTTED TO STILL OFFERS THE WHOLE FLOOR, got ' . $R['ghost']['n']);
    ok(in_array(327, $R['ghost']['ids'], true),
       '  including the people with nothing allotted — nothing goes dark on day one');

    /* TYPING RELEASES THE FILTER. This is what makes it safe: the stand-in is
       always reachable, so the narrowing can never refuse work that happened. */
    /* Every typed word must appear SOMEWHERE in the row's text, so "stitcher 7"
       also matches Stitcher 17 and Stitcher 70 — "7" is in both. That is the
       screen's existing search and it is not the thing under test here. What
       is under test is that a stitcher is reachable at all on a packing job. */
    ok(in_array(7, $R['typedName']['ids'], true),
       'typing a stitcher name on a PACKING job still finds them');
    ok(count($R['typedName']['ids']) < 331,
       '  and the search still narrows, got ' . count($R['typedName']['ids']) . ' of 331');
    ok($R['typedName']['foot'] === '', '  and nothing is said to have been hidden');
    ok($R['typedCode']['n'] === 1 && $R['typedCode']['ids'][0] === 150,
       'typing a code reaches straight through, got ' . json_encode($R['typedCode']['ids']));
    ok($R['nobody']['n'] === 0, 'and a search matching nobody is still empty');
}

/* ------------------------------------------------------------------ */
echo "6. The footer cannot be picked by accident\n";

/* The note is a plain div. If it were a .ze-row the arrow keys would land on
   it and Enter would book a wage against nothing. */
$fa = strpos($pe, 'list.foot ?');
$line = substr($pe, $fa, 120);
ok(str_contains($line, "'<div class=\"ze-none\">'"), 'the note is a ze-none div, not a ze-row');
ok(!str_contains($line, 'ze-row'), '  so arrow keys and Enter step past it');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
