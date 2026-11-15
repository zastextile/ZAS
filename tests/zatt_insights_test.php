<?php
declare(strict_types=1);

/* ATTENDANCE REPORTS — INSIGHTS AND THE MONTHLY REGISTER
 *
 * A month of attendance for three departments (October 2026, Mon–Sat
 * shifts 09:00–17:00, 10 minutes allowed), built so each figure can be
 * checked by hand: one person absent three days running, one late six
 * times, one leaving at 13:00 four times. Then, in a browser:
 *   - Insights: attendance rate and punctuality match the lines; one bar
 *     per shift day; arrivals by time; the department scorecard; the three
 *     watch lists name the right people; hover shows the numbers
 *   - Monthly register: one line per person, a letter per day, the
 *     absences exactly where they were, totals, the CSV file
 *   - nothing pushes a phone screen sideways; no script error
 * INS_SHOTS=dir keeps screenshots.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler',
          'SyncService','OvertimeService','ReportService','HrService','AdminService','EntryAdmin'] as $c) {
    require_once $B . "includes/att/$c.php";
}
use Att\Db;
use Att\EntryAdmin as E;
use Att\Employees;
use Att\HodService;
use Att\Migrations;
use Att\Policy;
use Att\ReportService as R;
use Att\Roles;
use Att\Shifts;
use Att\SyncService as S;

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
$hash = password_hash('CorrectHorse9', PASSWORD_DEFAULT);
$mk = function (string $name, string $email, string $role = 'colleague') use ($pdo, $hash): int {
    $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)")->execute([$name, $email, $hash, $role]);
    return (int) $pdo->lastInsertId();
};
echo "1. A month of attendance\n";
$admin = $mk('Admin Person', 'admin@zas.test', 'admin');
$day = Shifts::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '17:00', 'grace_in_minutes' => 10, 'grace_out_minutes' => 10]);
Policy::set('default_shift_id', (string) $day, null, 'string');
Policy::set('auto_out_enabled', '0', null, 'bool'); Policy::flush();
$depts = ['Stitching' => 10, 'Packing' => 8, 'Cutting' => 8];
$special = ['S001' => 'Absent Thrice', 'S002' => 'Often Late', 'P001' => 'Short Days', 'C007' => 'Mostly Away', 'C008' => 'Has Left'];
$awayDays = [3, 5, 7, 10, 14, 17, 21, 24];          // Mostly Away: 8 days not come, scattered
/* Has Left stopped coming on the 15th and was marked as left: on the roll the figures leave them out */
$people = [];
foreach ($depts as $dn => $cnt) {
    $did = Employees::createDepartment($dn);
    for ($i = 1; $i <= $cnt; $i++) {
        $c = substr($dn, 0, 1) . sprintf('%03d', $i);
        $people[$c] = Employees::create(['code' => $c, 'full_name' => $special[$c] ?? ($dn . ' Worker ' . $i), 'department_id' => $did]);
    }
}
$k = fn(string $local) => new DateTimeImmutable($local, new DateTimeZone('Asia/Karachi'));
mt_srand(7);
$exp = ['rows' => 0, 'worked' => 0, 'late' => 0, 'absent' => 0, 'absentOf' => []];
$act = ['rows' => 0, 'worked' => 0, 'late' => 0, 'absent' => 0];
for ($d = 1; $d <= 31; $d++) {
    $date = sprintf('2026-10-%02d', $d);
    if ((int) (new DateTimeImmutable($date))->format('w') === 0) { continue; }            // Sunday: no shift
    foreach ($people as $c => $id) {
        Db::run('INSERT INTO att_shift_instances (employee_id, shift_id, work_date, starts_at, ends_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $day, $date, $date . ' 04:00:00', $date . ' 12:00:00']);
        $exp['rows']++; $on = $c !== 'C008'; if ($on) { $act['rows']++; }
        $absent = ($c === 'S001' && in_array($d, [12, 13, 14], true)) || ($c === 'C007' && in_array($d, $awayDays, true)) || ($c === 'C008' && $d >= 15)
            || (!in_array($c, ['S001', 'C007', 'C008'], true) && mt_rand(1, 100) <= 5);
        if ($absent) { $exp['absent']++; if ($on) { $act['absent']++; } $exp['absentOf'][$c][] = $d; continue; }
        $late = ($c === 'S002' && in_array($d, [2, 6, 9, 16, 20, 27], true)) || (!in_array($c, ['S002', 'C007', 'C008'], true) && mt_rand(1, 100) <= 6);
        $in = $late ? sprintf('09:%02d', mt_rand(15, 45)) : sprintf('08:%02d', mt_rand(35, 59));
        $out = ($c === 'P001' && in_array($d, [5, 8, 19, 22], true)) ? '13:00' : sprintf('17:%02d', mt_rand(0, 25));
        $r = E::add($c, $k("$date $in"), $k("$date $out"), 'October demo', $admin);
        if (!$r['ok']) { echo "seed failed $c $date: {$r['error']}\n"; }
        $exp['worked']++; if ($late) { $exp['late']++; }
        if ($on) { $act['worked']++; if ($late) { $act['late']++; } }
    }
}
Db::run("UPDATE att_employees SET active = 0 WHERE code = 'C008'");
$rep = R::attendance(['from' => '2026-10-01', 'to' => '2026-10-31'], null);
ok($rep['ok'] && count($rep['rows']) === $exp['rows'] && $rep['totals']['absent'] === $exp['absent'] && $rep['totals']['late'] === $exp['late'],
   'a month for 26 people (one has since left): ' . $exp['rows'] . ' shift days, ' . $exp['absent'] . ' not come, ' . $exp['late'] . ' late — the report agrees');
ok(count(array_filter($rep['rows'], fn($x) => $x['employee_active'] === 0)) === 27, '  each line says whether the person is still on the roll');
$rate = round(1000 * $act['worked'] / $act['rows']) / 10;
$punc = round(1000 * ($act['worked'] - $act['late']) / $act['worked']) / 10;
$rateAll = round(1000 * $exp['worked'] / $exp['rows']) / 10;
$fmt = fn(float $v) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');

echo "2. In a browser\n";
$port = 8741;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))), [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $cc = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($cc) { fclose($cc); break; } usleep(200000); }
$work = __DIR__ . '/.zattinsights';
@mkdir($work, 0777, true);
file_put_contents($work . '/run.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2]; const SHOTS = process.argv[3] || ''; const EXP = JSON.parse(process.argv[4]);
const out = [];
const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
async function signIn(ctx) {
  const p = await ctx.newPage();
  await p.goto(BASE + '/att/manifest.webmanifest');
  await p.evaluate(async () => fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@zas.test', password: 'CorrectHorse9' }) }));
  return p;
}
const loaded = (p) => p.waitForFunction(() => !/^Loading…$/.test(document.getElementById('attr-body').textContent.trim()), null, { timeout: 30000 });
(async () => {
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, deviceScaleFactor: SHOTS ? 2 : 1, acceptDownloads: true });
    const p = await signIn(ctx);
    const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.goto(BASE + '/att_reports.php#ins'); await loaded(p);
    const tabs = (await p.locator('#attr-tabs button').allTextContents()).join('|');
    check('the reports have Insights and Monthly register beside the old tabs', /^Today\|Insights\|Attendance\|Monthly register\|Movements/.test(tabs), tabs);
    await p.fill('#f-from', '2026-10-01'); await p.fill('#f-to', '2026-10-31'); await p.click('#f-show');
    await p.waitForSelector('#ins-tiles'); await p.waitForTimeout(200);
    const tiles = await p.textContent('#ins-tiles');
    check('INSIGHTS: attendance rate ' + EXP.rate + '% and punctuality ' + EXP.punc + '%, as counted from the lines', tiles.includes(EXP.rate + '%') && tiles.includes(EXP.punc + '%'), tiles);
    check('  one bar per shift day (' + EXP.days + ')', (await p.locator('#ins-trend svg .hit').count()) === EXP.days, await p.locator('#ins-trend svg .hit').count());
    const arr = await p.locator('#ins-arrive svg .bar').count();
    check('  arrivals by time: on-time and late bars, with a legend', arr > 4 && (await p.locator('#ins-arrive svg .bar.late').count()) > 0 && /On time/.test(await p.textContent('#ins-arrive')));
    await p.locator('#ins-trend svg .hit').nth(3).hover();
    const tipT = await p.locator('.ctip').textContent();
    check('  hovering a bar shows the day and its numbers', await p.locator('.ctip').isVisible() && /Attendance \d/.test(tipT) && /came of/.test(tipT), tipT);
    check('  the department scorecard has the three departments', (await p.locator('#ins-dept tbody tr').count()) === 3);
    check('  "Absent several days in a row" names Absent Thrice — 3 days in a row', /Absent Thrice[\s\S]*3 days in a row/.test(await p.textContent('#ins-absent')), await p.textContent('#ins-absent').catch(() => ''));
    check('  "Often late" names Often Late — 6 times', /Often Late[\s\S]*6 times/.test(await p.textContent('#ins-late')));
    check('  "Short days" names Short Days — 4 days', /Short Days[\s\S]*4 days/.test(await p.textContent('#ins-short')));
    check('PEOPLE WHO LEFT: Has Left is not counted; the tick box offers "Include 1 person who left", off', !/Has Left/.test(await p.textContent('#attr-body')) && /Include 1 person who left/.test(await p.textContent('label.lefttog')) && !(await p.isChecked('#with-left')));
    await p.check('#with-left');
    check('  ticked: they come back in and the rate falls to ' + EXP.rateAll + '%', (await p.textContent('#ins-tiles')).includes(EXP.rateAll + '%') && /Has Left/.test(await p.textContent('#ins-absent')));
    await p.uncheck('#with-left');
    check('  there is no "Mostly absent" list — three balanced lists', (await p.locator('#ins-most').count()) === 0 && (await p.locator('.cols3 .panel').count()) === 3);
    if (SHOTS) { await p.mouse.move(5, 5); await p.waitForTimeout(100); await p.screenshot({ path: SHOTS + '/insights.png', fullPage: true }); }
    await p.locator('#ins-late li', { hasText: 'Often Late' }).click(); await loaded(p);
    await p.waitForSelector('#attr-body .seg button[data-v=lines][aria-pressed=true]');
    check('  clicking a name opens their days on the Attendance tab', (await p.inputValue('#f-emp')) === 'S002' && /Often Late/.test(await p.textContent('#attr-body')));
    await p.fill('#f-emp', ''); await p.click('#f-show'); await loaded(p);

    await p.click('#attr-tabs button:has-text("Monthly register")'); await loaded(p);
    await p.fill('#r-month', '2026-10'); await p.click('#r-show');
    await p.waitForSelector('#reg-box table.reg');
    const people = await p.locator('#reg-box tbody tr:not(.grp)').count();
    check('REGISTER: one line per person on the roll (25), grouped by department', people === 25 && (await p.locator('#reg-box tr.grp').count()) === 3, people);
    await p.check('#with-left');
    check('  with "Include … who left" ticked: 26', (await p.locator('#reg-box tbody tr:not(.grp)').count()) === 26);
    await p.uncheck('#with-left');
    check('  31 day columns', (await p.locator('#reg-box thead th.d').count()) === 31);
    check('  every absence is an A, every late day an L', (await p.locator('#reg-box td.kA').count()) === EXP.absent && (await p.locator('#reg-box td.kL').count()) === EXP.late,
      (await p.locator('#reg-box td.kA').count()) + ' A, ' + (await p.locator('#reg-box td.kL').count()) + ' L');
    const row = p.locator('#reg-box tbody tr', { hasText: 'Absent Thrice' });
    const cells = await row.locator('td.d').allTextContents();
    check('  Absent Thrice: A on the 12th, 13th and 14th; Sundays have no shift', cells[11] === 'A' && cells[12] === 'A' && cells[13] === 'A' && cells[3] === '·', cells.join(''));
    check('  hovering a box says IN, OUT and hours', /IN \d\d:\d\d · OUT \d\d:\d\d/.test(await row.locator('td.kP').first().getAttribute('title')));
    check('  totals and the bottom line', /25 people/.test(await p.textContent('#reg-box tfoot')));
    const regFit = await p.evaluate(() => { const b = document.getElementById('reg-box'); return b.scrollWidth - b.clientWidth; });
    check('  the whole month and its totals fit at 1366 px (no sliding)', regFit <= 1, '+' + regFit + 'px');
    const [dl] = await Promise.all([p.waitForEvent('download'), p.click('#r-csv')]);
    const fs = require('fs'); const csv = fs.readFileSync(await dl.path(), 'utf8');
    check('  Download gives the register as a CSV file for Excel', dl.suggestedFilename() === 'attendance-register-2026-10.csv' && csv.split('\n').length === 26 && /Absent Thrice/.test(csv) && !/Has Left/.test(csv), dl.suggestedFilename());
    if (SHOTS) await p.screenshot({ path: SHOTS + '/register.png', fullPage: true });
    check('no script error', errs.length === 0, errs.join(' | '));
    await ctx.close();

    const m = await browser.newContext({ viewport: { width: 390, height: 800 }, deviceScaleFactor: SHOTS ? 2 : 1 });
    const mp = await signIn(m);
    const wide = [];
    for (const t of ['ins', 'reg']) {
      await mp.goto(BASE + '/att_reports.php#' + t); await mp.reload(); await loaded(mp); await mp.waitForTimeout(300);
      if (t === 'ins') { await mp.fill('#f-from', '2026-10-01'); await mp.fill('#f-to', '2026-10-31'); await mp.click('#f-show'); await mp.waitForSelector('#ins-tiles'); }
      else { await mp.fill('#r-month', '2026-10'); await mp.click('#r-show'); await mp.waitForSelector('#reg-box'); }
      const o = await mp.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      if (o > 1) wide.push(t + ' +' + o);
      if (SHOTS) await mp.screenshot({ path: SHOTS + '/phone-' + t + '.png', fullPage: t === 'ins' });
    }
    check('at phone width neither pushes the page sideways (the register slides inside its box)', wide.length === 0, wide.join(', '));
    await m.close();
  } catch (e) { check('the browser run completed', false, e && e.message); }
  finally { await browser.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
$days = (int) Db::value('SELECT COUNT(DISTINCT work_date) FROM att_shift_instances');
$expJs = json_encode(['rate' => $fmt($rate), 'punc' => $fmt($punc), 'rateAll' => $fmt($rateAll), 'days' => $days, 'absent' => $act['absent'], 'late' => $act['late']]);
$raw = (string) shell_exec('cd ' . escapeshellarg($work) . ' && timeout 240 node run.js ' . escapeshellarg("http://127.0.0.1:$port") . ' ' . escapeshellarg((string) (getenv('INS_SHOTS') ?: '')) . ' ' . escapeshellarg($expJs) . ' 2>&1');
$results = [];
if (preg_match('/RESULT(\[.*\])\s*$/s', $raw, $m)) { $results = json_decode($m[1], true) ?: []; }
ok(count($results) >= 19, 'the browser run reported its checks (' . count($results) . ')' . (count($results) ? '' : "\n$raw"));
foreach ($results as $r) { ok($r['pass'], $r['name'] . ($r['pass'] ? '' : ' — ' . $r['extra'])); }

echo "\nINSIGHTS AND REGISTER: $P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
