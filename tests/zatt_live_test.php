<?php
declare(strict_types=1);

/* ATTENDANCE REPORTS — LIVE, WITH FACES
 *
 * Thirty people across three departments, each with a drawn portrait (not a
 * real person) stored through the ERP's real R2 client into the stand-in
 * bucket (tests/fake_r2.php). Today's entries are made relative to now: some
 * came in minutes ago, most are inside, a few just went out, some went home,
 * the rest are not in. Then:
 *   - /reports/live counts them the way the screen shows them
 *   - a face is the stored photo, sent by this server only, to someone
 *     allowed to see that department; a HOD cannot fetch another
 *     department's face; every view is in the audit log
 *   - the Live tab: tiles, incoming and outgoing faces, the present ring,
 *     everyone inside with department chips, Hide faces, the wall screen
 *   - nothing pushes a phone screen sideways; no script error
 * LIVE_SHOTS=dir keeps screenshots.
 */
$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
$config = require $B . 'config/config.php';
if (($config['r2_access_key'] ?? '') !== 'TESTKEY') { echo "SKIP: not the test config — refusing to touch a real bucket\n"; exit(0); }
require_once $B . 'includes/storage.php';
foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler',
          'SyncService','OvertimeService','ReportService','HrService','AdminService','EntryAdmin','Http','Storage','Images','PhotoService'] as $c) {
    require_once $B . "includes/att/$c.php";
}
use Att\Db;
use Att\PhotoService as Ph;
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

/* the stand-in bucket */
$r2dir = sys_get_temp_dir() . '/zas_fake_r2_live_' . getmypid();
@mkdir("$r2dir/objects", 0777, true);
$r2 = proc_open('exec php -S 127.0.0.1:8750 ' . escapeshellarg(__DIR__ . '/fake_r2.php'),
    [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pp, null, ['FAKE_R2_DIR' => $r2dir] + getenv());
register_shutdown_function(static function () use ($r2, $r2dir) { if (is_resource($r2)) { proc_terminate($r2, 9); proc_close($r2); } exec('rm -rf ' . escapeshellarg($r2dir)); });
for ($i = 0; $i < 60; $i++) { $cc = @fsockopen('127.0.0.1', 8750, $e1, $e2, 0.2); if ($cc) { fclose($cc); break; } usleep(200000); }

/* a drawn portrait: background, shoulders, neck, face, hair, eyes — varied, never a real person */
function portrait(int $i): string
{
    $im = imagecreatetruecolor(360, 360);
    $bg = [[214, 228, 243], [226, 236, 222], [240, 228, 214], [228, 222, 240], [220, 236, 236]][$i % 5];
    imagefilledrectangle($im, 0, 0, 360, 360, imagecolorallocate($im, ...$bg));
    $skin = [[236, 196, 164], [214, 166, 128], [190, 138, 100], [160, 112, 80], [226, 182, 146]][($i * 3) % 5];
    $shirt = [[30, 64, 124], [120, 30, 40], [40, 100, 70], [90, 90, 100], [180, 120, 30], [60, 50, 120]][($i * 7) % 6];
    $hair = [[30, 24, 20], [60, 40, 25], [20, 20, 22], [90, 60, 40]][($i * 5) % 4];
    imagefilledellipse($im, 180, 380, 330, 220, imagecolorallocate($im, ...$shirt));
    imagefilledrectangle($im, 155, 230, 205, 290, imagecolorallocate($im, ...$skin));
    imagefilledellipse($im, 180, 170, 150, 180, imagecolorallocate($im, ...$skin));
    $h = imagecolorallocate($im, ...$hair);
    if ($i % 3 === 1) {                                     // a head scarf
        imagefilledellipse($im, 180, 150, 190, 200, imagecolorallocate($im, ...[[70, 90, 140], [140, 60, 90], [60, 110, 100]][$i % 3]));
        imagefilledellipse($im, 180, 182, 132, 150, imagecolorallocate($im, ...$skin));
    } else {
        imagefilledarc($im, 180, 140, 160, 130, 180, 360, $h, IMG_ARC_PIE);
        if ($i % 4 === 0) { imagefilledellipse($im, 180, 236, 120, 70, $h); imagefilledellipse($im, 180, 214, 112, 60, imagecolorallocate($im, ...$skin)); }   // a beard
    }
    $eye = imagecolorallocate($im, 35, 30, 30);
    imagefilledellipse($im, 152, 172, 13, 13, $eye); imagefilledellipse($im, 208, 172, 13, 13, $eye);
    imagearc($im, 180, 212, 50, 22, 20, 160, imagecolorallocate($im, 120, 60, 50));
    ob_start(); imagejpeg($im, null, 90); $out = (string) ob_get_clean(); imagedestroy($im);
    return $out;
}

echo "1. Thirty people, their photos, and today so far\n";
$admin = $mk('Admin Person', 'admin@zas.test', 'admin');
$hodU = $mk('Hina Hod', 'hod@zas.test');
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?, 'attrep', 'v'), (?, 'atthod', 'vc')")->execute([$hodU, $hodU]);
$names = ['Ali Raza', 'Ayesha Khan', 'Usman Tariq', 'Mehnaz Bibi', 'Bilal Ahmed', 'Imran Shah', 'Sana Malik', 'Hamza Iqbal', 'Nadia Pervez', 'Kashif Ali',
          'Rabia Noor', 'Faisal Mehmood', 'Zainab Akhtar', 'Asad Butt', 'Hina Yousaf', 'Tariq Jamil', 'Sadia Riaz', 'Waqas Anwar', 'Maryam Saleem', 'Junaid Khan',
          'Farah Naz', 'Adeel Hussain', 'Saima Ashraf', 'Naveed Akram', 'Iqra Javed', 'Shahid Latif', 'Amna Zafar', 'Danish Qureshi', 'Kiran Shahzad', 'Omer Farooq'];
$deptIds = ['Stitching' => Employees::createDepartment('Stitching'), 'Cutting' => Employees::createDepartment('Cutting'), 'Packing' => Employees::createDepartment('Packing')];
Roles::grant($hodU, Roles::HOD, $deptIds['Packing']);
$day = Shifts::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '17:00', 'grace_in_minutes' => 10, 'grace_out_minutes' => 10]);
Policy::set('default_shift_id', (string) $day, null, 'string');
Policy::set('auto_out_enabled', '0', null, 'bool'); Policy::flush();
$ids = []; $dn = array_keys($deptIds);
foreach ($names as $i => $nm) {
    $c = sprintf('E%03d', $i + 1);
    $ids[$c] = Employees::create(['code' => $c, 'full_name' => $nm, 'department_id' => $deptIds[$dn[$i % 3]]]);
    if ($i !== 7 && $i !== 19) { Ph::setReference($ids[$c], portrait($i), true, $admin); }       // two have no photo yet
}
ok($n('SELECT COUNT(*) FROM att_employees WHERE reference_photo_key IS NOT NULL') === 28, '28 of the 30 have a photo on file (stored, re-made, in the private bucket)');

$nowK = new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi'));
$dayStart = $nowK->setTime(0, 1);
$at = function (int $minsAgo) use ($nowK, $dayStart): DateTimeImmutable { $t = $nowK->modify("-$minsAgo minutes"); return $t < $dayStart ? $dayStart->modify('+' . (600 - $minsAgo) . ' seconds') : $t; };
$todayK = $nowK->format('Y-m-d');
$utcS = $nowK->modify('-6 hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$utcE = $nowK->modify('+4 hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$plan = [];
foreach (array_keys($ids) as $i => $c) {
    Db::run('INSERT INTO att_shift_instances (employee_id, shift_id, work_date, starts_at, ends_at) VALUES (?, ?, ?, ?, ?)', [$ids[$c], $day, $todayK, $utcS, $utcE]);
    $plan[$c] = $i < 5 ? 'incoming' : ($i < 21 ? 'inside' : ($i < 24 ? 'outgoing' : ($i < 27 ? 'gone' : 'notin')));
}
/* those inside came through the gate: real scans, as a gate phone sends them */
$gkU = $mk('Gul Gate', 'gate@zas.test');
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?, 'attgate', 'vc')")->execute([$gkU]);
Roles::grant($gkU, Roles::GATEKEEPER);
S::registerDevice('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Main gate', 'gate', $gkU);
$gseq = 0;
$gateIn = function (string $c, DateTimeImmutable $t) use (&$gseq, $gkU): array {
    $r = S::process(['event_uuid' => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$gseq), 'device_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'device_sequence' => $gseq, 'employee_code' => $c, 'event_type' => 'GATE_IN', 'occurred_at' => $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        'timezone' => 'UTC', 'payload' => []], $gkU, true);
    return ['ok' => in_array($r['receipt_status'] ?? '', ['ACCEPTED'], true), 'error' => json_encode($r)];
};
foreach ($plan as $c => $what) {
    $r = match ($what) {
        'incoming' => $gateIn($c, $at(2 + 4 * (int) substr($c, 1))),
        'inside'   => $gateIn($c, $at(120 + (int) substr($c, 1))),
        'outgoing' => E::add($c, $at(200), $at(5 + (int) substr($c, 1) % 20), 'live demo', $admin),
        'gone'     => E::add($c, $at(300), $at(90), 'live demo', $admin),
        default    => ['ok' => true],
    };
    if (!$r['ok']) { echo "  seed $c: {$r['error']}\n"; }
}
/* forgotten OUTs: two from yesterday's shift, one from today, closed by the system */
$k = fn(string $local) => new DateTimeImmutable($local, new DateTimeZone('Asia/Karachi'));
$yK = $nowK->modify('-1 day')->format('Y-m-d');
foreach (['E028', 'E029'] as $c) {
    Db::run('INSERT INTO att_shift_instances (employee_id, shift_id, work_date, starts_at, ends_at) VALUES (?, ?, ?, ?, ?)',
        [$ids[$c], $day, $yK, $yK . ' 04:00:00', $yK . ' 12:00:00']);
    $ry = E::add($c, $k("$yK 08:55"), $k("$yK 17:00"), 'live demo', $admin);
    Db::run("UPDATE att_entry_cycles SET close_source = 'auto_shift_end', auto_closed_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', time() - 8 * 3600), $ry['entry_cycle_id']]);
}
Db::run("UPDATE att_entry_cycles SET close_source = 'auto_shift_end', auto_closed_at = ? WHERE employee_id = ? AND closed_at IS NOT NULL ORDER BY id DESC LIMIT 1", [gmdate('Y-m-d H:i:s', time() - 60), $ids['E025']]);
$lv = R::live(null);
ok($lv['ok'] && count($lv['incoming']) === 5 && $lv['inside_now'] === 21 && count($lv['outgoing']) === 3 && $lv['gone_home'] === 6 && $lv['not_in'] === 3 && $lv['came_in'] === 27 && $lv['active_employees'] === 30,
   'LIVE: 5 came in the last 30 min, 21 inside (the 5 included), 3 just went out, 6 gone home (the 3 included), 3 not in, 27 of 30 came — '
   . json_encode(['in' => count($lv['incoming']), 'inside' => $lv['inside_now'], 'out' => count($lv['outgoing']), 'gone' => $lv['gone_home'], 'notin' => $lv['not_in']]));
ok($lv['incoming'][0]['employee_code'] === 'E001' && $lv['incoming'][0]['has_photo'] === true, '  newest first, with whether a photo is on file');
$hodLive = R::live([$deptIds['Packing']]);
ok($hodLive['active_employees'] === 10 && !in_array('Stitching', array_column($hodLive['inside'], 'department_name'), true), 'a HOD sees their own department only');

echo "2. Over the web\n";
$port = 8742;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))), [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $cc = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($cc) { fclose($cc); break; } usleep(200000); }
function sess(string $email): string
{
    global $port;
    $jar = tempnam(sys_get_temp_dir(), 'attlive');
    $ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=%2Fauth%2Flogin");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => 'CorrectHorse9']), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    curl_exec($ch); curl_close($ch);
    return $jar;
}
function fetchUrl(?string $jar, string $route, array $qs = []): array
{
    global $port;
    $ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=" . rawurlencode($route) . ($qs ? '&' . http_build_query($qs) : ''));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    $raw = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return ['status' => $code, 'head' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
}
$aj = sess('admin@zas.test'); $hj = sess('hod@zas.test');
ok(fetchUrl(null, '/reports/live')['status'] === 401 && fetchUrl(null, '/reports/live/photo', ['id' => $ids['E001']])['status'] === 401, 'not signed in: no list, no face');
$ph = fetchUrl($aj, '/reports/live/photo', ['id' => $ids['E001']]);
ok($ph['status'] === 200 && str_contains($ph['head'], 'image/webp') && stripos($ph['head'], 'no-store') !== false && strlen($ph['body']) > 1000,
   'a face is the picture itself (WebP, never cached, never a link) — ' . $ph['status']);
ok($n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'photo.view' AND new_data LIKE '%live dashboard%' AND subject_id = ?", [$ids['E001']]) === 1, '  and the view is in the audit log, as "live dashboard"');
$packing = array_values(array_filter($ids, fn($id) => (int) Db::value('SELECT department_id FROM att_employees WHERE id = ?', [$id]) === $deptIds['Packing']))[0];
ok(fetchUrl($hj, '/reports/live/photo', ['id' => $ids['E001']])['status'] === 404 && fetchUrl($hj, '/reports/live/photo', ['id' => $packing])['status'] === 200,
   'a HOD gets faces from their own department only (another department answers "no such employee")');
ok(fetchUrl($aj, '/reports/live/photo', ['id' => $ids['E008']])['status'] === 404, 'no photo on file: 404, and the screen shows initials');

echo "3. The Live tab\n";
$work = __DIR__ . '/.zattlive';
@mkdir($work, 0777, true);
file_put_contents($work . '/run.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2]; const SHOTS = process.argv[3] || '';
const out = [];
const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
async function signIn(ctx, email) {
  const p = await ctx.newPage();
  await p.goto(BASE + '/att/manifest.webmanifest');
  await p.evaluate(async (email) => fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password: 'CorrectHorse9' }) }), email);
  return p;
}
(async () => {
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, deviceScaleFactor: SHOTS ? 2 : 1 });
    const p = await signIn(ctx, 'admin@zas.test');
    const errs = []; p.on('pageerror', e => errs.push(e.message));
    const photoReqs = [], singleReqs = [];
    p.on('request', r => { const u = r.url(); if (/route=%2Freports%2Flive%2Fphotos/.test(u)) photoReqs.push(u); else if (/route=%2Freports%2Flive%2Fphoto(&|$)/.test(u)) singleReqs.push(u); });
    await p.evaluate(() => { try { localStorage.removeItem('attr-hide-faces'); } catch (e) {} });
    await p.goto(BASE + '/att_reports.php#live');
    await p.waitForSelector('#live-grid');
    const tabs = (await p.locator('#attr-tabs button').allTextContents()).join('|');
    check('Live is a tab beside the others', /^Today\|Live\|Insights/.test(tabs), tabs);
    const tx = async (s) => (await p.textContent(s)).replace(/\s+/g, ' ');
    const big = async (s) => (await p.textContent(s + ' > b')).replace(/\D+/g, '');
    const k = [await big('#live-in'), await big('#live-inside'), await big('#live-out'), await big('#live-gone'), await big('#live-notin')].join(',');
    check('tiles: Incoming 5, Inside now 21, Outgoing 3, Gone home 6, Not in today 3', k === '5,21,3,6,3', k);
    check('the clock ticks', /\d\d:\d\d:\d\d/.test(await p.textContent('#live-clock')));
    check('incoming faces with a green ring: 5; outgoing with amber: 3', (await p.locator('#live-flow-in .av.g').count()) === 5 && (await p.locator('#live-flow-out .av.o').count()) === 3);
    check('the ring says 90% present', /90%/.test(await p.textContent('svg.ring')));
    await p.waitForFunction(() => document.querySelectorAll('#live-grid .av.has').length >= 19, null, { timeout: 15000 }).catch(() => {});
    const loaded = await p.locator('#live-grid .av.has').count(), all = await p.locator('#live-grid .av').count();
    check('everyone inside (21) is shown; 19 faces load, initials for the two with no photo on file', all === 21 && loaded === 19, loaded + ' of ' + all);
    const src = await p.locator('#live-grid .av.has').first().evaluate(e => getComputedStyle(e).backgroundImage);
    check('  a face is held in this page only (a small inline picture), never a link to the bucket', /^url\("data:image\/(webp|jpeg);base64,/.test(src), src.slice(0, 40));
    check('  SPEED: all the faces came in at most 2 requests (batches of up to 40), not one per face', photoReqs.length >= 1 && photoReqs.length <= 2 && singleReqs.length === 0, photoReqs.length + ' batch, ' + singleReqs.length + ' single');
    await p.click('#live-inside-panel .chips button:has-text("Cutting")');
    check('department chips narrow the faces (Cutting)', (await p.locator('#live-grid .pf').count()) === (await p.locator('#live-grid .pf .m').count()) && (await p.locator('#live-grid .pf').count()) < 21);
    await p.click('#live-inside-panel .chips button:has-text("All")');
    await p.fill('#live-inside-panel input[type=search]', 'ayesha');
    check('find a name', (await p.locator('#live-grid .pf').count()) === 1);
    await p.fill('#live-inside-panel input[type=search]', '');
    if (SHOTS) await p.screenshot({ path: SHOTS + '/live.png', fullPage: true });
    await p.click('#live-hide'); await p.waitForFunction(() => document.getElementById('live-hide') && document.getElementById('live-hide').textContent === 'Show faces');
    check('Hide faces: initials only, and it is remembered', (await p.locator('#live-grid .av.has').count()) === 0 && (await p.textContent('#live-hide')) === 'Show faces');
    await p.click('#live-hide'); await p.waitForFunction(() => document.getElementById('live-hide') && document.getElementById('live-hide').textContent === 'Hide faces');
    await p.click('#live-wall'); await p.waitForSelector('#attr.wall #live-grid');
    await p.waitForFunction(() => document.querySelectorAll('#live-grid .av.has').length >= 18, null, { timeout: 15000 }).catch(() => {});
    check('Wall screen: the dark full-screen view for a TV', await p.locator('#attr.wall').count() === 1);
    if (SHOTS) await p.screenshot({ path: SHOTS + '/live-wall.png', fullPage: true });
    await p.click('#live-wall');
    await p.click('#attr-tabs button:has-text("Today")'); await p.waitForSelector('#auto-out .agrp');
    const groups = await p.locator('#auto-out .agh b').allTextContents();
    check('TODAY › Not scanned out is split: Today (1) then From yesterday (2)', groups.join('|') === 'Today|From yesterday'
      && (await p.locator('#auto-out .agrp[data-day=today] tbody tr').count()) === 1 && (await p.locator('#auto-out .agrp[data-day=yesterday] tbody tr').count()) === 2, groups.join('|'));
    if (SHOTS) await p.locator('#auto-out').screenshot({ path: SHOTS + '/not-scanned-out-groups.png' });
    check('no script error', errs.length === 0, errs.join(' | '));
    await ctx.close();
    // the HOD's phone: the waiting list as faces, three to a row
    const hc = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: SHOTS ? 2 : 1 });
    const hp = await hc.newPage(); hp.on('dialog', d => d.accept());
    await hp.goto(BASE + '/att/'); await hp.waitForSelector('#email', { timeout: 20000 });
    await hp.fill('#email', 'hod@zas.test'); await hp.fill('#password', 'CorrectHorse9'); await hp.click('#signin');
    await hp.waitForSelector('#pin', { timeout: 20000 }); await hp.fill('#pin', '1234'); await hp.click('#pingo');
    await hp.waitForFunction(() => document.querySelectorAll('#pendinglist .tile').length >= 6, null, { timeout: 30000 }).catch(() => {});
    await hp.waitForFunction(() => document.querySelectorAll('#pendinglist .face.has').length >= 6, null, { timeout: 20000 }).catch(() => {});
    const ht = await hp.locator('#pendinglist .tile').count(), hf = await hp.locator('#pendinglist .face.has').count();
    check('HOD PHONE: the Packing arrivals waiting for confirmation are faces, three to a row (' + ht + ' tiles, ' + hf + ' faces)', ht >= 6 && hf >= 6 && !/Stitching|Cutting/.test(await hp.textContent('#pendinglist')));
    await hp.locator('#pendinglist .tile:has(.face.has)').nth(0).click(); await hp.locator('#pendinglist .tile:has(.face.has)').nth(4).click();
    await hp.waitForTimeout(400);
    await hp.evaluate(() => { const L = document.getElementById('pendinglist'); window.scrollTo(0, L.getBoundingClientRect().top + scrollY - 150); });
    if (SHOTS) await hp.screenshot({ path: SHOTS + '/hod-faces.png' });
    await hc.close();
    const m = await browser.newContext({ viewport: { width: 390, height: 800 }, deviceScaleFactor: SHOTS ? 2 : 1 });
    const mp = await signIn(m, 'admin@zas.test');
    await mp.goto(BASE + '/att_reports.php#live'); await mp.waitForSelector('#live-grid'); await mp.waitForTimeout(500);
    const o = await mp.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    check('at phone width the Live tab does not push the page sideways', o <= 1, '+' + o);
    if (SHOTS) await mp.screenshot({ path: SHOTS + '/live-phone.png', fullPage: true });
    await m.close();
  } catch (e) { check('the browser run completed', false, e && e.message); }
  finally { await browser.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
$raw = (string) shell_exec('cd ' . escapeshellarg($work) . ' && timeout 240 node run.js ' . escapeshellarg("http://127.0.0.1:$port") . ' ' . escapeshellarg((string) (getenv('LIVE_SHOTS') ?: '')) . ' 2>&1');
$results = [];
if (preg_match('/RESULT(\[.*\])\s*$/s', $raw, $m)) { $results = json_decode($m[1], true) ?: []; }
ok(count($results) >= 16, 'the browser run reported its checks (' . count($results) . ')' . (count($results) ? '' : "\n$raw"));
foreach ($results as $r) { ok($r['pass'], $r['name'] . ($r['pass'] ? '' : ' — ' . $r['extra'])); }
ok($n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'photo.view' AND new_data LIKE '%live dashboard%'") >= 20, 'every face shown on the Live tab is in the audit log');
$th = Db::one('SELECT COUNT(*) AS n, MAX(LENGTH(bytes)) AS big FROM att_photo_thumbs');
ok((int) $th['n'] >= 19 && (int) $th['big'] < 10000, 'SPEED: each face is a small copy made once and kept (' . $th['n'] . ' made, the largest ' . $th['big'] . ' bytes — the full photo is ~30 KB)');
$made = (string) Db::value('SELECT made_at FROM att_photo_thumbs WHERE employee_id = ?', [$ids['E002']]);
$t2 = Ph::thumbs([$ids['E002']], $admin, 'test');
ok(isset($t2[$ids['E002']]) && (string) Db::value('SELECT made_at FROM att_photo_thumbs WHERE employee_id = ?', [$ids['E002']]) === $made, '  shown again, the kept copy is used — no new trip to the bucket');
Ph::setReference($ids['E002'], portrait(99), true, $admin);
Ph::thumbs([$ids['E002']], $admin, 'test');
ok((string) Db::value('SELECT photo_key FROM att_photo_thumbs WHERE employee_id = ?', [$ids['E002']]) === (string) Db::value('SELECT reference_photo_key FROM att_employees WHERE id = ?', [$ids['E002']]),
   '  a NEW photo makes a new small copy (the old one is never shown)');
Ph::removeReference($ids['E002'], 'left', $admin);
ok($n('SELECT COUNT(*) FROM att_photo_thumbs WHERE employee_id = ?', [$ids['E002']]) === 0, '  a REMOVED photo takes its small copy with it');
$hb = fetchUrl($hj, '/reports/live/photos', ['ids' => $ids['E001'] . ',' . $packing]);
$hbj = json_decode($hb['body'], true);
ok($hb['status'] === 200 && isset($hbj['photos'][(string) $packing]) && !isset($hbj['photos'][(string) $ids['E001']]), 'in a batch too, a HOD gets only their own department\'s faces');

echo "\nLIVE: $P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
