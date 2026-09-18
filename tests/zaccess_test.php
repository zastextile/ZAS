<?php
/* USER ACCESS — what a person may do, and when.
 *
 * The window logic and the one-time read-across are where the damage
 * would be: get the window wrong and staff are locked out of their own
 * jobs; get the read-across wrong and everybody loses their access on
 * upgrade day and an admin has to re-tick 108 boxes per person.
 *
 * So both are LIFTED out of includes/access.php and RUN — against a fake
 * clock and a fake PDO — rather than read for strings. The admin screen
 * is then rendered and driven in Chromium.
 */

$B  = __DIR__ . '/app_src/public_html/';
$ac = file_get_contents($B . 'includes/access.php');
$ua = file_get_contents($B . 'user_access.php');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$work = __DIR__ . '/.zaccess';
@mkdir($work, 0777, true);

echo "1. It is wired in at the two gates, and nowhere else\n";
$auth = file_get_contents($B . 'includes/auth.php');
$help = file_get_contents($B . 'includes/helpers.php');
$boot = file_get_contents($B . 'includes/bootstrap.php');
ok(str_contains($boot, "require_once __DIR__ . '/access.php'"), 'the engine is loaded in bootstrap');
ok(strpos($boot, "/auth.php") < strpos($boot, "/access.php"),
   '  after auth.php, because it uses current_user()');
ok(str_contains($auth, "zu_window_state(\$u) === 'no'"),
   'require_login() stops a HARD-blocked user on every page');
ok(str_contains($help, 'zu_guard_write()'), 'verify_csrf() stops a read-only user from writing');
/* function_exists guards mean a half-uploaded copy cannot take the site down */
ok(substr_count($auth, "function_exists('zu_window_state')") === 1, '  guarded by function_exists');
ok(substr_count($help, "function_exists('zu_guard_write')") === 1, '  and so is the write gate');
ok(str_contains($ac, "if ((\$_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;"),
   'a GET is never blocked — being read-only means being able to read');

echo "2. Nobody can lock the admins out\n";
ok(str_contains($ac, "if ((\$u['role'] ?? '') === 'admin') return 'yes';"),
   'an admin is never outside their window');
ok(str_contains($ac, "a locked building with the keys inside"), '  and the reason is written down');
ok(str_contains($ac, "Admin accounts are not limited here"), 'saving an admin\'s rights is refused');
ok(str_contains($ac, 'Admin accounts are never limited by hours'), 'and so is saving an admin\'s window');

/* ------------------------------------------------------------------ */
echo "3. The window, RUN against a fake clock\n";

/* Lift the real functions. The clock is the only thing replaced. */
$fns = '';
foreach (['zu_window_state', 'zu_window_words', 'zu_guard_write'] as $fn) {
    if (preg_match('/\nfunction ' . $fn . '\(.*?\n}/s', $ac, $m)) $fns .= $m[0] . "\n";
    else ok(false, "could not lift $fn()");
}
ok(substr_count($fns, 'function ') >= 3, 'the window functions lifted out of the shipped file');

$harness = '<?php
/* THE CLOCK IS REPLACED, AND IT HAS TO BE DONE IN A NAMESPACE.
   PHP refuses to redeclare date() in the global namespace — "Cannot
   redeclare function date()". Inside a namespace an unqualified date()
   resolves to the local one first, so the lifted code (which lives in
   this namespace too) sees the fake clock while everything else still
   reaches the real builtin through a leading backslash. */
namespace T;
function date($f, $t = null){ return \date($f, $GLOBALS["NOW"]); }
function current_user(){ return $GLOBALS["U"]; }
' . $fns . '
$out = [];
$base = ["id"=>2,"role"=>"staff","is_active"=>1,"acc_days"=>"12345",
         "acc_t1"=>"09:00:00","acc_t2"=>"17:30:00","acc_from"=>null,"acc_until"=>null,
         "acc_outside"=>"readonly"];
$at = function($s) { return strtotime($s); };

/* Wed 2026-09-16 is a weekday; Sun 2026-09-20 is not. */
$GLOBALS["U"] = $base;
$GLOBALS["NOW"] = $at("2026-09-16 11:00");   $out["midShift"]   = zu_window_state();
$GLOBALS["NOW"] = $at("2026-09-16 08:59");   $out["tooEarly"]   = zu_window_state();
$GLOBALS["NOW"] = $at("2026-09-16 09:00");   $out["onTheDot"]   = zu_window_state();
$GLOBALS["NOW"] = $at("2026-09-16 17:30");   $out["lastMinute"] = zu_window_state();
$GLOBALS["NOW"] = $at("2026-09-16 17:31");   $out["afterHours"] = zu_window_state();
$GLOBALS["NOW"] = $at("2026-09-20 11:00");   $out["sunday"]     = zu_window_state();

/* the same person, set to hard block */
$GLOBALS["U"] = array_merge($base, ["acc_outside"=>"block"]);
$GLOBALS["NOW"] = $at("2026-09-16 21:00");   $out["blockNight"] = zu_window_state();
$GLOBALS["NOW"] = $at("2026-09-16 11:00");   $out["blockDay"]   = zu_window_state();

/* NIGHT SHIFT: 20:00 to 06:00 runs THROUGH midnight */
$GLOBALS["U"] = array_merge($base, ["acc_days"=>"1234567","acc_t1"=>"20:00:00","acc_t2"=>"06:00:00"]);
$GLOBALS["NOW"] = $at("2026-09-16 22:00");   $out["night22"] = zu_window_state();
$GLOBALS["NOW"] = $at("2026-09-17 03:00");   $out["night03"] = zu_window_state();
$GLOBALS["NOW"] = $at("2026-09-16 12:00");   $out["nightNoon"] = zu_window_state();

/* a temporary login with an end date */
$GLOBALS["U"] = array_merge($base, ["acc_days"=>"1234567","acc_t1"=>null,"acc_t2"=>null,
                                    "acc_from"=>"2026-09-01","acc_until"=>"2026-10-31"]);
$GLOBALS["NOW"] = $at("2026-09-16 11:00");   $out["inRange"]    = zu_window_state();
$GLOBALS["NOW"] = $at("2026-11-01 11:00");   $out["expired"]    = zu_window_state();
$GLOBALS["NOW"] = $at("2026-08-31 11:00");   $out["notYet"]     = zu_window_state();

/* no limits at all */
$GLOBALS["U"] = ["id"=>3,"role"=>"staff","is_active"=>1,"acc_days"=>"1234567",
                 "acc_t1"=>null,"acc_t2"=>null,"acc_from"=>null,"acc_until"=>null,"acc_outside"=>"readonly"];
$GLOBALS["NOW"] = $at("2026-09-20 03:00");   $out["noLimit"] = zu_window_state();

/* an admin, at 3am on a Sunday, with every limit set against them */
$GLOBALS["U"] = array_merge($base, ["role"=>"admin","acc_outside"=>"block","acc_until"=>"2020-01-01"]);
$GLOBALS["NOW"] = $at("2026-09-20 03:00");   $out["admin"] = zu_window_state();

/* switched off entirely */
$GLOBALS["U"] = array_merge($base, ["is_active"=>0]);
$GLOBALS["NOW"] = $at("2026-09-16 11:00");   $out["disabled"] = zu_window_state();

/* the words */
$out["words"] = zu_window_words($base);
$out["wordsOpen"] = zu_window_words(["acc_days"=>"1234567"]);
$out["wordsEnds"] = zu_window_words(array_merge($base, ["acc_until"=>"2026-10-31"]));

/* THE WRITE GATE. A GET must pass even when locked out. */
$GLOBALS["U"] = array_merge($base, ["acc_outside"=>"readonly"]);
$GLOBALS["NOW"] = $at("2026-09-16 23:00");
$_SERVER["REQUEST_METHOD"] = "GET";
$out["getPasses"] = "yes";  zu_guard_write();     // must not exit
$_SERVER["REQUEST_METHOD"] = "POST";
echo \json_encode($out);
$GLOBALS["BLOCKED"] = true;
zu_guard_write();                                  // must exit here
echo "NEVER-REACHED";
';
file_put_contents($work . '/win.php', $harness);
$raw = shell_exec('php ' . escapeshellarg($work . '/win.php') . ' 2>&1');
$jsonPart = substr($raw, 0, strpos($raw, '}') !== false ? strrpos($raw, '}') + 1 : 0);
$R = json_decode($jsonPart, true);

if (!is_array($R)) { echo "  FAIL: the window harness did not run:\n" . substr((string)$raw, 0, 700) . "\n"; $F++; }
else {
    ok($R['midShift'] === 'yes', 'mid-shift on a weekday: can work');
    ok($R['tooEarly'] === 'ro', 'one minute before the start: read only');
    ok($R['onTheDot'] === 'yes', 'the start minute itself counts as inside');
    ok($R['lastMinute'] === 'yes', 'and so does the end minute');
    ok($R['afterHours'] === 'ro', 'one minute after the end: read only');
    ok($R['sunday'] === 'ro', 'a day that is not in the list: read only');

    ok($R['blockNight'] === 'no', 'set to hard block, outside hours: no access');
    ok($R['blockDay'] === 'yes', '  but inside hours they work normally');

    /* THE ONE THAT WOULD HAVE SILENTLY LOCKED OUT THE NIGHT SHIFT. */
    ok($R['night22'] === 'yes', 'a 20:00–06:00 window works at 22:00');
    ok($R['night03'] === 'yes', '  and at 03:00, because it runs through midnight');
    ok($R['nightNoon'] === 'ro', '  and correctly refuses at noon');

    ok($R['inRange'] === 'yes', 'inside the date range: can work');
    ok($R['expired'] === 'ro', 'past the end date: locked to read only');
    ok($R['notYet'] === 'ro', 'before the start date: the same');

    ok($R['noLimit'] === 'yes', 'no limits set: 3am on a Sunday is fine');
    ok($R['admin'] === 'yes', 'an ADMIN at 3am Sunday with an expired range: still yes');
    ok($R['disabled'] === 'off', 'a disabled account reads as off, not as a window problem');

    ok(str_contains($R['words'], 'Mon–Fri'), 'the sentence names the days: ' . $R['words']);
    ok(str_contains($R['words'], '09:00 and 17:30'), '  and the hours');
    ok(str_contains($R['wordsOpen'], 'any day') && str_contains($R['wordsOpen'], 'any hour'),
       '  and says "any" when nothing is set: ' . $R['wordsOpen']);
    ok(str_contains($R['wordsEnds'], 'Access ends 2026-10-31'), '  and names an end date');

    ok(str_contains($raw, 'Outside your working hours'),
       'a POST outside the window is refused with an explanation');
    ok(!str_contains($raw, 'NEVER-REACHED'), '  and execution stops there');
}

/* ------------------------------------------------------------------ */
echo "4. The read-across: nobody loses access on upgrade day\n";

$seedFns = '';
foreach (['zu_modules', 'zu_module_actions', 'zu_seed_user', 'zu_save_perms'] as $fn) {
    if (preg_match('/\nfunction ' . $fn . '\(.*?\n}\n/s', $ac, $m)) $seedFns .= $m[0] . "\n";
}
ok(str_contains($seedFns, 'function zu_seed_user'), 'zu_seed_user() lifted');

$h2 = '<?php
class FS { public $s; public $r; function __construct($s,$r){$this->s=$s;$this->r=$r;}
  function execute($p=[]){ $GLOBALS["W"][] = [$this->s,$p]; return true; }
  function fetch(){ return $this->r[0] ?? false; } function fetchAll(){ return $this->r; }
  function fetchColumn(){ $x = $this->r[0] ?? []; return is_array($x) ? reset($x) : $x; } }
class FD { public $tx=false;
  function prepare($s){ return new FS($s, $GLOBALS["FIX"]($s)); }
  function query($s){ return new FS($s, $GLOBALS["FIX"]($s)); }
  function exec($s){ return 1; } function beginTransaction(){ $this->tx=true; return true; }
  function commit(){ $this->tx=false; return true; } function rollBack(){ $this->tx=false; return true; }
  function inTransaction(){ return $this->tx; } }
$GLOBALS["DB"] = new FD(); $GLOBALS["W"] = [];
function db(){ return $GLOBALS["DB"]; }
function zu_ensure_schema(){}
/* zu_save_perms() refreshes the cache and bumps the user cache when it is
   done. Those are other functions with their own jobs; without them here
   the save threw and reported itself as a failure, which looked like a
   bug in the save. */
function zu_perm_map($id, $fresh = false){ return []; }
function cache_bump($k){}
$GLOBALS["FIX"] = function($s){ return strpos($s,"SELECT role") !== false ? [["role"=>$GLOBALS["ROLE"]??"staff"]] : []; };
' . $seedFns . '
function grab(){ $m = []; foreach ($GLOBALS["W"] as $w)
    if (strpos($w[0], "INSERT INTO zu_perm") !== false) $m[$w[1][1]] = $w[1][2];
  return $m; }

$out = [];
/* a storekeeper as the OLD flags had them */
$GLOBALS["W"] = [];
zu_seed_user(["id"=>2,"role"=>"staff","acc_seeded"=>0,
  "inv_view"=>1,"inv_gate"=>1,"inv_store"=>1,"inv_consume"=>1,"inv_post"=>1]);
$out["store"] = grab();

/* a costing colleague */
$GLOBALS["W"] = [];
zu_seed_user(["id"=>3,"role"=>"colleague","acc_seeded"=>0,
  "cost_view"=>1,"cost_create"=>1,"cost_edit"=>1,"cost_proforma"=>1]);
$out["cost"] = grab();

/* production staff, who had no flags at all — the ROLE was their access */
$GLOBALS["W"] = [];
zu_seed_user(["id"=>4,"role"=>"production_staff","acc_seeded"=>0]);
$out["prod"] = grab();

/* already seeded: must do nothing at all */
$GLOBALS["W"] = [];
zu_seed_user(["id"=>5,"role"=>"staff","acc_seeded"=>1,"inv_view"=>1,"inv_gate"=>1]);
$out["already"] = count($GLOBALS["W"]);

/* SAVING writes the legacy columns too, or every old screen goes blind */
$GLOBALS["W"] = []; $GLOBALS["ROLE"] = "staff";
$out["save"] = zu_save_perms(9, ["gate"=>"vcudp","store"=>"vcu","costing"=>"vcud","stockrep"=>"v"], 1);
$legacy = "";
foreach ($GLOBALS["W"] as $w) if (strpos($w[0], "UPDATE users SET") !== false) $legacy = json_encode($w[1]);
$out["legacySql"] = "";
foreach ($GLOBALS["W"] as $w) if (strpos($w[0], "UPDATE users SET") !== false) $out["legacySql"] = $w[0];
$out["legacyVals"] = $legacy;
$out["saved"] = grab();

/* an admin cannot be saved */
$GLOBALS["ROLE"] = "admin";
$out["adminSave"] = zu_save_perms(1, ["gate"=>"v"], 1);

/* "create" without "view" is impossible */
$GLOBALS["ROLE"] = "staff"; $GLOBALS["W"] = [];
zu_save_perms(9, ["gate"=>"c"], 1);
$out["impliedView"] = grab();

/* an action a module does not have is dropped, not stored */
$GLOBALS["W"] = [];
zu_save_perms(9, ["pmaster"=>"vcudpr"], 1);
$out["trimmed"] = grab();

echo json_encode($out);
';
file_put_contents($work . '/seed.php', $h2);
$raw2 = shell_exec('php ' . escapeshellarg($work . '/seed.php') . ' 2>&1');
$S = json_decode((string)$raw2, true);

if (!is_array($S)) { echo "  FAIL: the seed harness did not run:\n" . substr((string)$raw2, 0, 700) . "\n"; $F++; }
else {
    ok(($S['store']['gate'] ?? '') === 'vcudp',
       'a storekeeper keeps gate view/create/update/delete AND post, got ' . json_encode($S['store']['gate'] ?? null));
    ok(($S['store']['store'] ?? '') === 'vcudp', '  and the same on store issues');
    ok(($S['store']['stockrep'] ?? '') === 'v', '  and can still read the stock reports');
    ok(!isset($S['store']['costing']), '  and gains nothing in costing they never had');

    ok(str_contains($S['cost']['costing'] ?? '', 'c') && str_contains($S['cost']['costing'] ?? '', 'u'),
       'a costing colleague keeps create and update, got ' . json_encode($S['cost']['costing'] ?? null));
    ok(!str_contains($S['cost']['costing'] ?? '', 'd'),
       '  and does NOT gain delete, which they never had');
    ok(str_contains($S['cost']['proforma'] ?? '', 'c'), '  and keeps proforma');

    /* production_staff had no flags — their ROLE was the access, and it
       has to survive or every production user is locked out on day one */
    ok(($S['prod']['prod'] ?? '') === 'vcu',
       'production staff keep production entry, got ' . json_encode($S['prod']['prod'] ?? null));
    ok(($S['prod']['prodrep'] ?? '') === 'v', '  and their reports');

    ok($S['already'] === 0, 'a user already seeded is not touched again');

    echo "   saving keeps the OLD flags in step\n";
    ok(($S['save']['ok'] ?? false) === true, 'the save succeeds');
    ok(str_contains($S['legacySql'], 'inv_gate=?') && str_contains($S['legacySql'], 'cost_view=?'),
       'and it writes the legacy columns, so every existing screen still works');
    ok(str_contains($S['legacySql'], 'acc_seeded=?'), '  and marks the user as seeded');

    ok(($S['adminSave']['ok'] ?? true) === false, 'an admin cannot be saved through this path');
    ok(str_contains($S['adminSave']['error'] ?? '', 'Admin accounts'), '  with a reason: ' . ($S['adminSave']['error'] ?? ''));

    ok(($S['impliedView']['gate'] ?? '') === 'vc',
       '"create" alone becomes view+create — a right nobody could use otherwise, got '
       . json_encode($S['impliedView']['gate'] ?? null));
    ok(($S['trimmed']['pmaster'] ?? '') === 'vcud',
       'post and reverse are dropped on a module that has neither, got '
       . json_encode($S['trimmed']['pmaster'] ?? null));
}

echo "5. Settings that would lock somebody out are refused\n";
ok(str_contains($ac, "NO DAYS AT ALL IS NOT A SETTING, IT IS A LOCKOUT"),
   'clearing every day is treated as "no limit", not as a permanent lockout');
ok(str_contains($ac, "if (\$t1 === '' || \$t2 === '') { \$t1 = ''; \$t2 = ''; }"),
   'one time without the other is dropped — it cannot be compared');
ok(str_contains($ac, 'The end date is before the start date'),
   'an end date before the start date is refused');

echo "6. The admin screen\n";
ok(str_contains($ua, "if (!is_admin())"), 'the page is admin only');
ok(str_contains($ua, 'verify_csrf()'), 'and the save is CSRF-checked');
ok(substr_count($ua, '<div class="zskin">') === 1, 'it opts into the skin once');
ok(substr_count($ua, 'closes .zskin') === 1, '  and closes it once');
/* ok() returns null, so "ok(A) || ok(false,...)" always ran the second
   one and reported a failure whatever A was. Two plain assertions. */
ok(str_contains(file_get_contents($B . 'includes/menu.php'), "'href' => 'user_access.php'"),
   'it is in the Administration menu');
ok(str_contains($ua, 'date_default_timezone_get()'),
   'the screen tells the admin which clock the hours are in');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
