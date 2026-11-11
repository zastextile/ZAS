<?php
declare(strict_types=1);

/* ATTENDANCE M5 — EMPLOYEE PHOTOS AND CAPTURE & FLAG
 *
 * The rules this milestone exists to keep:
 *
 *   AT07   No photo for a normal entry; a flagged photo is private.
 *   §6     A picture is checked by reading it, re-made before it is stored,
 *          never public, and every view of one is in the audit log.
 *   §7     A picture that fails to store never blocks the entry.
 *
 * The storage in these tests is tests/fake_r2.php — a stand-in bucket on this
 * machine — reached through the ERP's REAL R2 client (includes/storage.php),
 * so the code that runs in production is the code that runs here. What a
 * stand-in cannot prove is that a signature is right; that is checked
 * directly against the examples Amazon publishes (step 1).
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }

if (!is_file($B . 'config/config.php')) {
    echo "SKIP: no test config at public_html/config/config.php — run tests/run_att.sh\n";
    exit(0);
}
$config = require $B . 'config/config.php';         // the global the ERP's R2 client reads
if (($config['r2_access_key'] ?? '') !== 'TESTKEY') {
    echo "SKIP: the config in public_html is not the test config — refusing to touch a real bucket\n";
    exit(0);
}
require_once $B . 'includes/storage.php';
foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler',
          'SyncService','HrService','AdminService','Http','Storage','Images','PhotoService'] as $c) {
    require_once $B . "includes/att/$c.php";
}

use Att\Db;
use Att\Employees;
use Att\Images;
use Att\Migrations;
use Att\PhotoService as Ph;
use Att\Policy;
use Att\Roles;
use Att\Shifts;
use Att\Storage;
use Att\SyncService as S;

try {
    $pdo = new PDO($dsn, $dbu, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) { echo "SKIP: no MySQL available ({$e->getMessage()})\n"; exit(0); }
Db::bind($pdo);
Db::useUtc();

/* ------------------------------------------------------- the stand-in bucket */
$r2dir = sys_get_temp_dir() . '/zas_fake_r2_' . getmypid();
@mkdir("$r2dir/objects", 0777, true);
$r2 = proc_open('exec php -S 127.0.0.1:8750 ' . escapeshellarg(__DIR__ . '/fake_r2.php'),
    [1 => ['file', '/tmp/att_fake_r2.log', 'a'], 2 => ['file', '/tmp/att_fake_r2.log', 'a']], $pp, null, ['FAKE_R2_DIR' => $r2dir] + getenv());
register_shutdown_function(static function () use ($r2, $r2dir) {
    if (is_resource($r2)) { proc_terminate($r2, 9); proc_close($r2); }
    exec('rm -rf ' . escapeshellarg($r2dir));
});
for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', 8750, $e1, $e2, 0.2); if ($c) { fclose($c); break; } usleep(200000); }

$objects = function (string $prefix = '') use ($r2dir): array {
    $root = "$r2dir/objects"; $out = [];
    if (!is_dir($root)) { return $out; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        $k = substr($f->getPathname(), strlen($root) + 1);
        if ($f->isFile() && str_starts_with($k, $prefix)) { $out[] = $k; }
    }
    sort($out);
    return $out;
};
$drop = function (string $key, string $bytes) use ($r2dir): void {       // a person putting a file in the bucket
    @mkdir(dirname("$r2dir/objects/$key"), 0777, true);
    file_put_contents("$r2dir/objects/$key", $bytes);
};
$down = function (bool $on) use ($r2dir): void { $on ? touch("$r2dir/.down") : @unlink("$r2dir/.down"); };

/* ------------------------------------------------------------ test pictures */
/** A picture with structure in it, w × h, as JPEG / PNG / WebP bytes. */
function picture(int $w, int $h, string $fmt = 'jpeg', ?callable $paint = null): string
{
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y += 4) {
        imagefilledrectangle($im, 0, $y, $w, $y + 4, imagecolorallocate($im, 90 + (int) (100 * $y / $h), 120, 150 - (int) (60 * $y / $h)));
    }
    imagefilledellipse($im, (int) ($w / 2), (int) ($h * 0.35), (int) ($w * 0.4), (int) ($h * 0.3), imagecolorallocate($im, 224, 172, 140));
    if ($paint) { $paint($im); }
    ob_start();
    match ($fmt) { 'png' => imagepng($im), 'webp' => imagewebp($im, null, 90), default => imagejpeg($im, null, 90) };
    $out = (string) ob_get_clean();
    imagedestroy($im);
    return $out;
}
/** Insert a segment straight after the JPEG start marker. */
function withSegment(string $jpeg, string $marker, string $data): string
{
    return substr($jpeg, 0, 2) . $marker . pack('n', strlen($data) + 2) . $data . substr($jpeg, 2);
}
function dims(string $bytes): array { $i = getimagesizefromstring($bytes); return [$i[0], $i[1], $i['mime']]; }

/* ===================================================================== */
echo "1. The request signer, against Amazon's own published examples\n";
$ak = 'AKIAIOSFODNN7EXAMPLE'; $sk = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'; $d = '20130524T000000Z'; $e = hash('sha256', '');
$h = ['host' => 'examplebucket.s3.amazonaws.com', 'x-amz-content-sha256' => $e, 'x-amz-date' => $d];
ok(Storage::sigV4('GET', '/', ['max-keys' => '2', 'prefix' => 'J'], $h, $e, $ak, $sk, 'us-east-1', $d)['signature']
   === '34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', 'LIST A FOLDER signs to the published value');
ok(Storage::sigV4('GET', '/', ['lifecycle' => ''], $h, $e, $ak, $sk, 'us-east-1', $d)['signature']
   === 'fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543', 'a query with an empty value signs to the published value');
ok(Storage::sigV4('GET', '/test.txt', [], $h + ['range' => 'bytes=0-9'], $e, $ak, $sk, 'us-east-1', $d)['signature']
   === 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', 'an object read with an extra header signs to the published value');
ok(Storage::canonicalQuery(['prefix' => 'employee-photos/', 'list-type' => '2']) === 'list-type=2&prefix=employee-photos%2F', 'query parameters are sorted and the slash is escaped');

/* ===================================================================== */
echo "2. What counts as a picture is decided by reading the file\n";
ok(Images::inspect(picture(300, 400))['mime'] === 'image/jpeg' && Images::inspect(picture(300, 400, 'png'))['mime'] === 'image/png'
   && Images::inspect(picture(300, 400, 'webp'))['mime'] === 'image/webp', 'JPG, PNG and WebP are recognised');
ok(Images::inspect('<?php echo "hello"; ?>') === null, 'a PHP file is not a picture');
ok(Images::inspect('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>') === null, 'an SVG (which can carry script) is refused');
$gif = (function () { $im = imagecreatetruecolor(200, 250); ob_start(); imagegif($im); return (string) ob_get_clean(); })();
ok(Images::inspect($gif) === null, 'a GIF is refused');
ok(Images::inspect("\xFF\xD8\xFF\xE0" . str_repeat('A', 500)) === null, 'the first bytes of a JPEG followed by rubbish is refused');
ok((Images::normalize('not a picture at all', Images::REFERENCE)['reason_code'] ?? '') === 'NOT_AN_IMAGE', 'normalising a non-picture says NOT_AN_IMAGE');

echo "3. Nothing is kept as it arrived: fixed size, WebP, no hidden contents\n";
foreach ([[3000, 4000, 'portrait'], [4000, 3000, 'landscape'], [1500, 1500, 'square'], [300, 2000, 'very tall'], [200, 250, 'small']] as [$w, $hh, $what]) {
    $r = Images::normalize(picture($w, $hh), Images::REFERENCE);
    ok($r['ok'] && dims($r['bytes']) === [480, 600, 'image/webp'], "a $what $w × $hh upload becomes exactly 480 × 600 WebP");
}
$big = picture(3000, 4000);
$r = Images::normalize($big, Images::REFERENCE);
ok(strlen($r['bytes']) < 60000 && strlen($r['bytes']) < strlen($big) / 5, 'a ' . round(strlen($big) / 1024) . ' KB camera picture is stored as ' . round(strlen($r['bytes']) / 1024, 1) . ' KB');
ok((Images::normalize(picture(100, 120), Images::REFERENCE)['reason_code'] ?? '') === 'IMAGE_TOO_SMALL', 'a 100 × 120 thumbnail is too small to recognise anybody and is refused');
ok((Images::normalize(str_repeat('x', Images::MAX_SOURCE + 1), Images::REFERENCE)['reason_code'] ?? '') === 'IMAGE_TOO_LARGE', 'a file over 10 MB is refused before anything reads it');

/* A PNG whose header claims 20000 × 20000: tiny file, 1.6 GB to decode. */
$png = picture(200, 250, 'png');
$ihdr = substr($png, 12, 17);                                   // "IHDR" + 13 bytes
$ihdr = 'IHDR' . pack('N', 20000) . pack('N', 20000) . substr($ihdr, 12);
$bomb = substr($png, 0, 12) . $ihdr . pack('N', crc32($ihdr)) . substr($png, 33);
ok((Images::normalize($bomb, Images::REFERENCE)['reason_code'] ?? '') === 'IMAGE_TOO_LARGE', 'A PICTURE CLAIMING 400 MEGAPIXELS IS REFUSED BEFORE IT IS DECODED');

/* A real JPEG with a script hidden in a comment block, GPS-like text in EXIF,
   and PHP appended after the end marker. Only pixels may survive. */
$poly = withSegment(picture(600, 800), "\xFF\xFE", '<?php system($_GET["c"]); ?> COMMENTMARK');
$poly = withSegment($poly, "\xFF\xE1", "Exif\0\0MM\0*\0\0\0\x08\0\0 GPSMARK lat=24.86 lon=67.00 Model=PHONEMARK");
$poly .= '<?php echo "TAILMARK"; ?>';
ok(Images::inspect($poly) !== null, '(seed) the doctored file still passes as a JPEG — which is the point');
$clean = Images::normalize($poly, Images::REFERENCE);
$leak = array_filter(['COMMENTMARK', 'GPSMARK', 'PHONEMARK', 'TAILMARK', '<?php'], fn($m) => str_contains($clean['bytes'], $m));
ok($clean['ok'] && $leak === [], 'THE STORED FILE CARRIES NONE OF IT: no comment, no location, no phone model, no appended code' . ($leak ? ' — found ' . implode(',', $leak) : ''));

/* A phone held upright: stored sideways with a note saying "turn it". */
$side = withSegment(picture(400, 200), "\xFF\xE1", "Exif\0\0MM\0*\0\0\0\x08\0\x01\x01\x12\0\x03\0\0\0\x01\0\x06\0\0\0\0\0\0");
$up = Images::normalize($side, Images::FLAG);
ok($up['ok'] && [$up['width'], $up['height']] === [200, 400], 'a sideways phone picture is turned upright before the turning note is thrown away');

/* The crop favours the upper part, where the face is. */
$tall = picture(400, 1000, 'png', function ($im) {
    imagefilledrectangle($im, 0, 150, 400, 250, imagecolorallocate($im, 255, 0, 0));      // near the top: must survive
    imagefilledrectangle($im, 0, 800, 400, 900, imagecolorallocate($im, 0, 0, 255));      // near the bottom: must be cut
});
$cr = imagecreatefromstring(Images::normalize($tall, Images::REFERENCE)['bytes']);
$reds = 0; $blues = 0;
for ($y = 0; $y < 600; $y += 6) { $c = imagecolorsforindex($cr, imagecolorat($cr, 240, $y)); if ($c['red'] > 200 && $c['blue'] < 80) { $reds++; } if ($c['blue'] > 200 && $c['red'] < 80) { $blues++; } }
ok($reds > 5 && $blues === 0, 'a too-tall picture is cropped from the bottom, keeping the upper part');

$fl = Images::normalize(picture(4000, 3000), Images::FLAG);
ok($fl['ok'] && [$fl['width'], $fl['height']] === [1280, 960] && $fl['mime'] === 'image/webp', 'a flagged still is fitted inside 1280 and NOT cropped — it is evidence');
ok(Images::mimeOf($fl['bytes']) === 'image/webp' && Images::mimeOf(picture(50, 50)) === 'image/jpeg', 'a stored file\'s type is read from its own first bytes');

/* ===================================================================== */
/* A clean database with gate, HOD, HR and a clerk.                        */
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $t) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $t['tn'] . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$legacy = ['cost_view','cost_create','cost_edit','cost_delete','cost_import','cost_print','cost_proforma',
           'inv_view','inv_gate','inv_store','inv_consume','inv_master','inv_post','inv_adjust'];
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'colleague', department VARCHAR(60) NULL,
            can_see_rates TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, "
            . implode(', ', array_map(fn($c) => "$c TINYINT(1) NOT NULL DEFAULT 0", $legacy)) . ")");
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
$admin = $mk('Admin Person', 'admin@zas.test', 'admin');
$gk    = $mk('Gul Gatekeeper', 'gate@zas.test');
$hodU  = $mk('Hina Hod', 'hod@zas.test');
$hr    = $mk('Rana HR', 'hr@zas.test');
$clerk = $mk('Clara Clerk', 'clerk@zas.test');
$nobody = $mk('Nadir Nobody', 'nobody@zas.test');
$perm = $pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?,?,?)");
foreach ([[$gk, 'attgate', 'vc'], [$gk, 'attflag', 'vc'], [$hodU, 'atthod', 'vc'], [$hr, 'atthr', 'vud'], [$clerk, 'attadm', 'v']] as $row) { $perm->execute($row); }

$dS = Employees::createDepartment('Stitching');
$dP = Employees::createDepartment('Packing');
$emp = [];
foreach ([['E001', 'Imran Ali', $dS], ['E002', 'Sara Khan', $dP], ['E003', 'Bilal Ahmed', $dS], ['E004', 'Nadia Noor', $dS], ['E005', 'Asad Mir', $dS]] as [$code, $name, $dept]) {
    $emp[$code] = Employees::create(['code' => $code, 'full_name' => $name, 'department_id' => $dept]);
}
Roles::grant($gk, Roles::GATEKEEPER);
Roles::grant($hodU, Roles::HOD, $dS);
Roles::grant($hr, Roles::HR);
$day = Shifts::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '17:00']);
Policy::set('default_shift_id', (string) $day, null, 'string'); Policy::flush();
const GATE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
S::registerDevice(GATE, 'Gate A', 'gate', $gk);
$seq = 0; $un = 0;
$uuid = function () use (&$un): string { return sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$un); };
/* A scan on 2 March 2026 at the given UTC time (05:00Z is 10:00 at the factory). */
$scan = function (string $type, string $code, string $hm, ?string $id = null) use (&$seq, $uuid, $gk): array {
    return S::process(['event_uuid' => $id ?? $uuid(), 'device_id' => GATE, 'device_sequence' => ++$seq, 'employee_code' => $code,
        'event_type' => $type, 'occurred_at' => "2026-03-02T$hm:00Z", 'timezone' => 'UTC', 'payload' => []], $gk, true);
};

/* ===================================================================== */
echo "4. AT07 — an ordinary day at the gate stores no picture at all\n";
$in1 = $uuid();
ok($scan('GATE_IN', 'E001', '05:00', $in1)['receipt_status'] === 'ACCEPTED', '(seed) E001 comes in');
$scan('GATE_OUT', 'E001', '05:30'); $scan('GATE_IN', 'E001', '05:40'); $scan('GATE_IN', 'E002', '05:10'); $scan('GATE_IN', 'E003', '05:15');
$cyc = (int) Db::value('SELECT id FROM att_entry_cycles WHERE employee_id = ? ORDER BY id LIMIT 1', [$emp['E001']]);
Att\HodService::confirm(['event_uuid' => $uuid(), 'hod_user_id' => $hodU, 'entry_cycle_id' => $cyc, 'employee_id' => $emp['E001'], 'device_time' => '2026-03-02T05:05:00Z', 'timezone' => 'UTC']);
ok($objects() === [], 'FIVE SCANS AND A HOD CONFIRMATION: NOTHING IN THE BUCKET');
ok($n('SELECT COUNT(*) FROM att_flags') === 0, 'and no flag row');
$cols = array_column(Db::all("SELECT COLUMN_NAME AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('att_events','att_entry_cycles','att_hod_arrivals')"), 'c');
ok(array_filter($cols, fn($c) => stripos($c, 'photo') !== false || stripos($c, 'image') !== false) === [],
   'the gate event, entry and HOD tables have no column a picture could go in');
ok((Ph::storeFlagPhoto($uuid(), picture(600, 800), $gk)['reason_code'] ?? '') === 'FLAG_NOT_FOUND' && $objects() === [],
   'A STILL WITH NO FLAG TO BELONG TO IS REFUSED, and nothing is stored');

/* ===================================================================== */
echo "5. Reference photos\n";
$r = Ph::setReference($emp['E001'], picture(1200, 1600), false, $admin);
ok(($r['reason_code'] ?? '') === 'NOTICE_REQUIRED' && $objects() === [], 'without confirming the employee was told, the photo is refused and not stored');
$r = Ph::setReference($emp['E001'], picture(1200, 1600), true, $admin);
ok($r['ok'] && $r['width'] === 480 && $r['height'] === 600 && $r['format'] === 'webp', 'with the notice confirmed it is stored: 480 × 600 WebP');
$refs = $objects('attendance/ref/');
ok(count($refs) === 1 && preg_match('~^attendance/ref/[0-9a-f]{40}\.webp$~', $refs[0]) === 1, 'under a random name that says nothing about whose it is');
ok(dims(file_get_contents("$r2dir/objects/{$refs[0]}")) === [480, 600, 'image/webp'], 'what is in the bucket is the re-made picture, not the upload');
$row = Db::one('SELECT reference_photo_key, notice_ack_at, reference_photo_at FROM att_employees WHERE id = ?', [$emp['E001']]);
ok($row['reference_photo_key'] === $refs[0] && $row['notice_ack_at'] !== null && $row['reference_photo_at'] !== null, 'the employee record points at it, and the notice date is recorded');
$log = (string) file_get_contents("$r2dir/.log");
ok(!str_contains($log, 'UNSIGNED'), 'every request the server made to the bucket was signed');

ok(Ph::setReference($emp['E001'], picture(900, 900, 'png'), false, $admin)['ok'], 'replacing it needs no second notice');
$refs2 = $objects('attendance/ref/');
ok(count($refs2) === 1 && $refs2[0] !== $refs[0], 'THE OLD PICTURE IS DELETED FROM THE BUCKET when it is replaced');

$bad = Ph::setReference($emp['E002'], '<?php phpinfo();', true, $admin);
ok(($bad['reason_code'] ?? '') === 'NOT_AN_IMAGE' && count($objects('attendance/ref/')) === 1 && Db::value('SELECT notice_ack_at FROM att_employees WHERE id = ?', [$emp['E002']]) === null,
   'a file that is not a picture is refused; nothing stored, nothing recorded');

$down(true);
$f = Ph::setReference($emp['E002'], picture(800, 1000), true, $admin);
ok(($f['reason_code'] ?? '') === 'STORAGE_FAILED' && Db::value('SELECT reference_photo_key FROM att_employees WHERE id = ?', [$emp['E002']]) === null,
   'with storage down the upload fails cleanly and the employee record is unchanged');
ok($n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'photo.store_failed'") === 1 && Ph::status()['failures_7_days'] === 1, 'the failure is logged and counted for the Setup screen');
$down(false);

$v = Ph::reference($emp['E001'], $gk, 'code entered on a phone');
ok($v['ok'] && $v['mime'] === 'image/webp' && dims($v['bytes'])[0] === 480, 'the picture can be read back');
$a = Db::one("SELECT actor_id, new_data FROM att_audit_logs WHERE action = 'photo.view' ORDER BY id DESC LIMIT 1");
ok((int) $a['actor_id'] === $gk && json_decode($a['new_data'], true)['purpose'] === 'code entered on a phone', 'EVERY VIEW IS IN THE AUDIT LOG: who, whose picture, from where');
ok((Ph::reference($emp['E003'], $gk, 'x')['reason_code'] ?? '') === 'NO_PHOTO', 'an employee with no photo says so');

ok((Ph::removeReference($emp['E001'], '', $admin)['reason_code'] ?? '') === 'REASON_REQUIRED', 'removing a photo needs a reason');
ok(Ph::removeReference($emp['E001'], 'employee asked', $admin)['ok'] && $objects('attendance/ref/') === []
   && Db::value('SELECT reference_photo_key FROM att_employees WHERE id = ?', [$emp['E001']]) === null, 'with one, it is deleted from the bucket and from the record');

/* ===================================================================== */
echo "6. Bringing in photos dropped into the bucket, named by employee code\n";
$drop('employee-photos/E001.jpg', picture(2000, 2600));
$drop('employee-photos/e002.PNG', picture(700, 900, 'png'));
$drop('employee-photos/E003.jpg', 'this is a text file that somebody renamed');
$drop('employee-photos/E004.webp', picture(640, 800, 'webp'));
$drop('employee-photos/E001.png', picture(500, 600, 'png'));
$drop('employee-photos/X999.jpg', picture(500, 600));
$drop('employee-photos/old/E005.jpg', picture(500, 600));
ok((Ph::importBatch(10, [], false, $admin)['reason_code'] ?? '') === 'NOTICE_REQUIRED' && $objects('attendance/ref/') === [], 'it will not start until the notice is confirmed');
$skip = []; $done = []; $failed = []; $rounds = 0;
do {
    $b = Ph::importBatch(2, $skip, true, $admin);
    foreach ($b['done'] as $x) { $done[] = $x['code']; }
    foreach ($b['failed'] as $x) { $failed[$x['file']] = $x['error']; if (isset($x['key'])) { $skip[] = $x['key']; } }
    $rounds++;
} while ($b['ok'] && $b['remaining'] > 0 && $rounds < 10);
sort($done);
ok($done === ['E001', 'E002', 'E004'], 'three people get a photo, matched by file name whatever the letter case (' . implode(',', $done) . ')');
ok($rounds >= 2, "it worked through the folder a few at a time ($rounds rounds), not all in one request");
ok(isset($failed['E003.jpg']) && str_contains($failed['E003.jpg'], 'not a JPG'), 'the renamed text file is reported, by name, as not a picture');
ok(isset($failed['E001.png']) && str_contains($failed['E001.png'], 'second file'), 'a second file for the same employee is reported, not silently used');
ok($b['unmatched'] === ['X999.jpg', 'old/E005.jpg'], 'a file matching nobody, and one in a sub-folder, are listed as unmatched');
ok(count($objects('attendance/ref/')) === 3 && count($objects('employee-photos/')) === 7, 'three re-made pictures stored; ALL SEVEN HAND-OVER FILES LEFT WHERE THEY WERE');
ok($n('SELECT COUNT(*) FROM att_employees WHERE reference_photo_key IS NOT NULL AND notice_ack_at IS NOT NULL') === 3, 'each has the notice recorded');
$again = Ph::importBatch(10, [], true, $admin);
ok($again['already'] === 3 && $again['done'] === [] && count($objects('attendance/ref/')) === 3, 'running it again does nothing to the three already done');
$drop('employee-photos/E001.jpg', picture(1800, 2400, 'jpeg', fn($im) => imagefilledrectangle($im, 0, 0, 50, 50, imagecolorallocate($im, 255, 255, 0))));
$third = Ph::importBatch(10, [], true, $admin);
ok(array_column($third['done'], 'code') === ['E001'] && count($objects('attendance/ref/')) === 3, 'a file that was replaced in the folder is picked up, and the old stored picture is cleaned out');
Policy::set('photo_import_prefix', 'attendance/ref/', null, 'string'); Policy::flush();
ok(Ph::importPrefix() === 'employee-photos/', 'the hand-over folder can never be set to the folder the system itself writes to');
Policy::set('photo_import_prefix', 'employee-photos/', null, 'string'); Policy::flush();

/* ===================================================================== */
echo "7. Capture & Flag\n";
$fu = $uuid();
ok((Ph::createFlag(['flag_uuid' => $fu, 'gate_event_uuid' => $in1, 'reason_code' => 'NOT_A_REASON'], $gk)['reason_code'] ?? '') === 'VALIDATION', 'a made-up reason is refused');
ok((Ph::createFlag(['flag_uuid' => $fu, 'gate_event_uuid' => $in1, 'reason_code' => 'OTHER'], $gk)['reason_code'] ?? '') === 'VALIDATION', '"Other" needs a note');
ok((Ph::createFlag(['flag_uuid' => 'nope', 'gate_event_uuid' => $in1, 'reason_code' => 'NO_CARD'], $gk)['reason_code'] ?? '') === 'VALIDATION', 'a flag with no valid id is refused');
$notYet = Ph::createFlag(['flag_uuid' => $fu, 'gate_event_uuid' => $uuid(), 'reason_code' => 'NO_CARD'], $gk);
ok(($notYet['reason_code'] ?? '') === 'GATE_EVENT_NOT_RECEIVED' && ($notYet['retry'] ?? false) === true && $n('SELECT COUNT(*) FROM att_flags') === 0,
   'a flag whose scan has not reached the server yet is told to try again — not refused, not stored half-made');
$fl = Ph::createFlag(['flag_uuid' => $fu, 'gate_event_uuid' => $in1, 'reason_code' => 'FACE_MISMATCH', 'note' => 'beard, photo has none',
                      'occurred_at' => '2026-03-02T05:01:00Z', 'timezone' => 'UTC', 'device_id' => GATE], $gk);
ok($fl['ok'] && $fl['already'] === false && $fl['entry_cycle_id'] === $cyc, 'flagged by the scan\'s own id: the server works out which entry it was');
$fr = Db::one('SELECT * FROM att_flags WHERE flag_uuid = ?', [$fu]);
ok((int) $fr['employee_id'] === $emp['E001'] && (int) $fr['flagged_by'] === $gk && $fr['status'] === 'open' && $fr['photo_key'] === null && (int) $fr['device_id'] > 0,
   'the flag records who, by whom, from which phone — and stands on its own, with no photo yet');
$re = Ph::createFlag(['flag_uuid' => $fu, 'gate_event_uuid' => $in1, 'reason_code' => 'FACE_MISMATCH'], $gk);
ok($re['ok'] && $re['already'] === true && $n('SELECT COUNT(*) FROM att_flags') === 1, 'the same flag uploaded twice is one flag');

$dupScan = $uuid();
$rej = $scan('GATE_IN', 'E003', '05:15', $dupScan);
ok($rej['receipt_status'] === 'REJECTED', '(seed) a second IN for E003 in the same minute is refused by the 60-second rule');
$f2 = Ph::createFlag(['flag_uuid' => $uuid(), 'gate_event_uuid' => $dupScan, 'reason_code' => 'OTHER_PERSONS_CARD'], $gk);
ok($f2['ok'] && $f2['entry_cycle_id'] === null && (int) Db::value('SELECT employee_id FROM att_flags WHERE id = ?', [$f2['flag_id']]) === $emp['E003'],
   'a REFUSED scan can still be flagged: it has no entry, but the employee is known');
$f3 = Ph::createFlag(['flag_uuid' => $uuid(), 'entry_cycle_id' => $cyc, 'reason_code' => 'NO_CARD'], $gk);
ok($f3['ok'] && $f3['entry_cycle_id'] === $cyc, 'a caller that knows the entry number can flag by it');
ok((Ph::createFlag(['flag_uuid' => $uuid(), 'entry_cycle_id' => 999999, 'reason_code' => 'NO_CARD'], $gk)['reason_code'] ?? '') === 'CYCLE_NOT_FOUND', 'an entry that does not exist is refused');

echo "8. The still that goes with a flag\n";
$still = picture(3000, 4000);
$before = count($objects('attendance/flags/'));
Policy::set('photo_max_bytes', '200000', null, 'int'); Policy::flush();
ok((Ph::storeFlagPhoto($fu, $still, $gk)['reason_code'] ?? '') === 'IMAGE_TOO_LARGE' && count($objects('attendance/flags/')) === $before, 'a still over the size limit is refused');
Policy::set('photo_max_bytes', '5242880', null, 'int'); Policy::flush();
$down(true);
$sf = Ph::storeFlagPhoto($fu, $still, $gk);
ok(($sf['retry'] ?? false) === true && ($sf['reason_code'] ?? '') === 'STORAGE_FAILED', 'with storage down the phone is told to keep the picture and try again');
ok(Db::value('SELECT status FROM att_flags WHERE flag_uuid = ?', [$fu]) === 'open' && $n('SELECT COUNT(*) FROM att_entry_cycles WHERE id = ? AND closed_at IS NOT NULL', [$cyc]) === 1,
   'THE FLAG AND THE ENTRY ARE UNTOUCHED BY THE FAILED UPLOAD');
$down(false);
$st = Ph::storeFlagPhoto($fu, $still, $gk);
$fo = $objects('attendance/flags/');
ok($st['ok'] && $st['already'] === false && count($fo) === 1 && preg_match('~^attendance/flags/\d{4}/\d{2}/[0-9a-f]{40}\.webp$~', $fo[0]) === 1, 'then it stores, under a random name');
ok(dims(file_get_contents("$r2dir/objects/{$fo[0]}")) === [960, 1280, 'image/webp'], 'as 960 × 1280 WebP — the whole frame, not cropped');
ok(Ph::storeFlagPhoto($fu, $still, $gk)['already'] === true && count($objects('attendance/flags/')) === 1, 'the same upload replayed is not stored twice');
ok((Ph::storeFlagPhoto($fu, picture(800, 800), $gk)['reason_code'] ?? '') === 'PHOTO_EXISTS' && count($objects('attendance/flags/')) === 1, 'a DIFFERENT picture for the same flag is refused: one still per flag, and it cannot be swapped');
$fp = Ph::flagPhoto((int) $fr['id'], 'flag', $hr);
ok($fp['ok'] && dims($fp['bytes'])[0] === 960, 'HR can open it');
ok(Ph::flagPhoto((int) $fr['id'], 'reference', $hr)['ok'], 'and the employee\'s reference photo beside it');
ok($n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'photo.view' AND actor_id = ?", [$hr]) === 2, 'both views are in the audit log under HR\'s name');

echo "9. Review and retention\n";
$list = Ph::flags();
ok(count($list['open']) === 3 && $list['open'][0]['reason'] !== '' && isset($list['open'][0]['flagged_by_name']), 'HR sees three open flags, with the reason in words and who raised it');
ok(!str_contains(json_encode($list), 'attendance/') && !array_key_exists('photo_key', $list['open'][0]), 'the list says a photo exists; it never says where');
ok((Ph::reviewFlag((int) $fr['id'], '', $hr)['reason_code'] ?? '') === 'REASON_REQUIRED', 'closing a flag needs a finding');
ok(Ph::reviewFlag((int) $fr['id'], 'Checked with supervisor — same person, grew a beard. Photo to be retaken.', $hr)['ok'], 'reviewed');
ok((Ph::reviewFlag((int) $fr['id'], 'again', $hr)['reason_code'] ?? '') === 'ALREADY_REVIEWED', 'it cannot be reviewed twice');
$l2 = Ph::flags();
ok(count($l2['open']) === 2 && count($l2['reviewed']) === 1 && $l2['reviewed'][0]['review_note'] !== '', 'it moves to the reviewed list with its finding');

ok(Ph::purgeExpired()['purged'] === 0, 'a fresh still is not purged');
$pdo->prepare('UPDATE att_flags SET photo_stored_at = (UTC_TIMESTAMP(6) - INTERVAL 91 DAY) WHERE id = ?')->execute([(int) $fr['id']]);
$down(true);
$pg = Ph::purgeExpired();
ok($pg['purged'] === 0 && $pg['failed'] === 1 && Db::value('SELECT photo_key FROM att_flags WHERE id = ?', [(int) $fr['id']]) !== null,
   'if the delete fails the record still says the picture exists — it is not marked gone until it is');
$down(false);
$pg = Ph::purgeExpired();
ok($pg['purged'] === 1 && $objects('attendance/flags/') === [], 'AFTER 90 DAYS THE STILL IS DELETED FROM THE BUCKET');
$after = Db::one('SELECT photo_key, photo_purged_at, status, review_note, reason_code FROM att_flags WHERE id = ?', [(int) $fr['id']]);
ok($after['photo_key'] === null && $after['photo_purged_at'] !== null && $after['status'] === 'reviewed' && $after['reason_code'] === 'FACE_MISMATCH', 'the flag, its reason and its review are kept');
ok(str_contains((string) (Ph::flagPhoto((int) $fr['id'], 'flag', $hr)['error'] ?? ''), 'retention'), 'opening it now explains that the photo passed its retention period');
ok((Ph::storeFlagPhoto($fu, picture(800, 800), $gk)['reason_code'] ?? '') === 'PHOTO_EXISTS', 'and a purged flag cannot be given a new picture');
ok(count($objects('attendance/ref/')) === 3, 'reference photos are not touched by the retention purge');
ok(Ph::purgeIfDue() !== null && Ph::purgeIfDue() === null, 'the automatic purge runs at most once a day');

$t = Ph::selfTest($admin);
ok($t['ok'] && $t['passed'] === true && count($t['steps']) === 4 && $objects('attendance/selftest/') === [], 'the connection test writes, reads, lists and deletes — and leaves nothing behind');
$down(true);
$t = Ph::selfTest($admin);
ok($t['passed'] === false && $t['steps'][0]['ok'] === false && !str_contains(json_encode($t), 'test-secret'), 'with storage down it says which step failed, and never shows a credential');
$down(false);

/* ===================================================================== */
/* Over real HTTP.                                                         */
$port = 8732;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))),
    [1 => ['file', '/tmp/att_m5_srv.log', 'a'], 2 => ['file', '/tmp/att_m5_srv.log', 'a']], $pipes);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); break; } usleep(200000); }

$jar = tempnam(sys_get_temp_dir(), 'attm5');
/** $file: bytes to send as the multipart field "photo" (then $data are the other fields). */
function call(string $method, string $path, array $data = [], ?string $csrf = null, ?string $file = null): array
{
    global $port, $jar;
    $url = "http://127.0.0.1:$port/api/v1/index.php?route=" . rawurlencode($path);
    $h = ['Accept: application/json'];
    if ($csrf !== null) { $h[] = "X-CSRF-Token: $csrf"; }
    $ch = curl_init();
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true];
    if ($file !== null) { $opts[CURLOPT_POSTFIELDS] = $data + ['photo' => new CURLStringFile($file, 'holiday.exe', 'application/x-msdownload')]; }
    elseif ($method !== 'GET') { $opts[CURLOPT_POSTFIELDS] = json_encode($data); $h[] = 'Content-Type: application/json'; }
    elseif ($data !== []) { $url .= '&' . http_build_query($data); }
    $opts[CURLOPT_URL] = $url; $opts[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($raw, $hs);
    $json = json_decode($body, true);
    return ['status' => $status, 'headers' => strtolower(substr($raw, 0, $hs)), 'body' => is_array($json) ? $json : [], 'raw' => $body];
}
function signIn(string $email): string
{
    global $jar;
    file_put_contents($jar, '');
    return (string) (call('POST', '/auth/login', ['email' => $email, 'password' => 'CorrectHorse9'])['body']['csrf_token'] ?? '');
}

echo "10. Over HTTP: who is shown a photo\n";
ok(call('GET', '/employees/E001/photo')['status'] === 401, 'signed out: 401');
$gs = signIn('gate@zas.test');
$views = $n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'photo.view' AND actor_id = ?", [$gk]);
$img = call('GET', '/employees/E001/photo');
ok($img['status'] === 200 && dims($img['raw']) === [480, 600, 'image/webp'], 'THE GATEKEEPER GETS THE PICTURE ITSELF for the code they entered');
ok(str_contains($img['headers'], 'content-type: image/webp') && str_contains($img['headers'], 'cache-control: private, no-store') && str_contains($img['headers'], 'x-content-type-options: nosniff'),
   'marked private and not to be kept by the phone');
ok(!str_contains($img['headers'], 'location:') && !str_contains($img['headers'], 'r2') && !str_contains($img['headers'], 'attendance/'), 'no link, no redirect, nothing about where it is stored');
ok($n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'photo.view' AND actor_id = ?", [$gk]) === $views + 1, 'and the view is logged');
$none = call('GET', '/employees/E003/photo');
ok($none['status'] === 404 && ($none['body']['reason_code'] ?? '') === 'NO_PHOTO', 'no photo on file: 404 NO_PHOTO');
ok(call('GET', '/employees/NOPE/photo')['status'] === 404, 'no such code: 404');
$me = call('GET', '/auth/me')['body'];
ok(($me['can']['flag'] ?? false) === true && isset($me['flag_reasons']['FACE_MISMATCH']), 'the phone is told it may flag, and the reasons to offer');
$snap = call('GET', '/employees/sync-snapshot');
$one  = call('GET', '/employees/E001');
$e1 = array_values(array_filter($snap['body']['employees'] ?? [], fn($x) => $x['code'] === 'E001'))[0] ?? [];
ok((int) ($e1['has_photo'] ?? 0) === 1 && ($one['body']['employee']['has_photo'] ?? false) === true, 'the phone\'s employee list says WHETHER there is a photo');
ok(!str_contains($snap['raw'] . $one['raw'], 'attendance/') && !str_contains($snap['raw'] . $one['raw'], 'reference_photo'), 'BUT NEVER WHERE IT IS: no storage key in anything a phone receives');

signIn('hod@zas.test');
ok(call('GET', '/employees/E001/photo')['status'] === 200, 'the HOD of Stitching sees a Stitching employee\'s photo');
ok(call('GET', '/employees/E002/photo')['status'] === 404, 'THE HOD CANNOT SEE THE PHOTO OF SOMEBODY IN ANOTHER DEPARTMENT — same answer as "no such employee"');
$hsnap = call('GET', '/employees/sync-snapshot');
$hcodes = array_column($hsnap['body']['employees'] ?? [], 'code'); sort($hcodes);
ok($hsnap['status'] === 200 && $hcodes === ['E001', 'E003', 'E004', 'E005'],
   'A HOD WITH ONLY THE HOD PERMISSION GETS THEIR OWN DEPARTMENT\'S LIST for the phone — and nobody else\'s (' . implode(',', $hcodes) . ')');
ok(call('GET', '/employees/E002')['status'] === 404 && call('GET', '/employees/E001')['status'] === 200, '  the same for a single lookup');
$pend = call('GET', '/sync/changes');
ok($pend['status'] === 200 && !str_contains($pend['raw'], 'attendance/') && str_contains($pend['raw'], 'has_photo'), 'the HOD\'s pending list carries has_photo and no key');
signIn('nobody@zas.test');
ok(call('GET', '/employees/E001/photo')['status'] === 403, 'a login with no attendance permission: 403');

echo "11. Over HTTP: flag and still, as the phone sends them\n";
$hs = signIn('hod@zas.test');
ok(call('POST', '/flags', ['flag_uuid' => $uuid(), 'entry_cycle_id' => $cyc, 'reason_code' => 'NO_CARD'], $hs)['status'] === 403, 'a login without the flag permission cannot flag');
$gs = signIn('gate@zas.test');
$hf = $uuid();
ok(call('POST', '/flags', ['flag_uuid' => $hf, 'gate_event_uuid' => $in1, 'reason_code' => 'NO_CARD'])['status'] === 419, 'no CSRF token: refused');
$wait = call('POST', '/flags', ['flag_uuid' => $hf, 'gate_event_uuid' => $uuid(), 'reason_code' => 'NO_CARD'], $gs);
ok($wait['status'] === 409 && ($wait['body']['reason_code'] ?? '') === 'GATE_EVENT_NOT_RECEIVED', 'the scan has not arrived yet: 409, which the phone reads as "keep it"');
$mkf = call('POST', '/flags', ['flag_uuid' => $hf, 'gate_event_uuid' => $in1, 'reason_code' => 'NO_CARD', 'device_id' => GATE], $gs);
ok($mkf['status'] === 200 && ($mkf['body']['flag_id'] ?? 0) > 0, 'flag recorded');
$notpic = call('POST', "/flags/$hf/photo", [], $gs, '<?php echo "x";');
ok($notpic['status'] === 422 && ($notpic['body']['reason_code'] ?? '') === 'NOT_AN_IMAGE', 'a non-picture sent as the still: 422');
$up = call('POST', "/flags/$hf/photo", [], $gs, picture(1600, 1200));
ok($up['status'] === 200 && ($up['body']['already'] ?? null) === false, 'A PICTURE SENT WITH THE NAME "holiday.exe" AND THE WRONG TYPE IS ACCEPTED — the file is judged by its contents, not its label');
ok(count($objects('attendance/flags/')) === 1, 'and stored');
ok(call('POST', "/flags/$hf/photo", [], null, picture(1600, 1200))['status'] === 419, 'an upload without the CSRF token is refused');
ok(call('POST', '/flags/' . $uuid() . '/photo', [], $gs, picture(800, 600))['status'] === 404, 'a still for a flag that does not exist: 404');
$down(true);
$hf2 = $uuid();
call('POST', '/flags', ['flag_uuid' => $hf2, 'entry_cycle_id' => $cyc, 'reason_code' => 'NO_CARD'], $gs);
$dn = call('POST', "/flags/$hf2/photo", [], $gs, picture(800, 600));
ok($dn['status'] === 503 && ($dn['body']['retry'] ?? false) === true, 'storage down: 503 and "retry" — the phone keeps the picture');
$down(false);
ok(call('POST', "/flags/$hf2/photo", [], $gs, picture(800, 600))['status'] === 200, 'and the retry succeeds');

echo "12. Over HTTP: the three contract routes, for a caller that knows the entry\n";
$cf = call('POST', "/attendance/$cyc/flag", ['reason_code' => 'FACE_MISMATCH', 'note' => 'looks different'], $gs);
$cu = (string) ($cf['body']['flag_uuid'] ?? '');
ok($cf['status'] === 200 && preg_match('/^[0-9a-f-]{36}$/', $cu) === 1, 'POST /attendance/{cycle}/flag makes a flag and returns its id');
ok(call('POST', "/attendance/$cyc/photo-confirm", ['flag_uuid' => $cu], $gs)['status'] === 409, 'confirming before any upload: 409 PHOTO_NOT_STORED');
$url = call('POST', "/attendance/$cyc/photo-upload-url", ['flag_uuid' => $cu], $gs);
$target = (string) ($url['body']['upload']['url'] ?? '');
ok($url['status'] === 200 && str_starts_with($target, 'api/v1/index.php?route=') && !str_contains($target, 'r2') && !str_contains($target, 'X-Amz'),
   'photo-upload-url answers with an address ON THIS SERVER — a phone is never given a way to write into the bucket');
ok(call('POST', '/attendance/999999/photo-upload-url', ['flag_uuid' => $cu], $gs)['status'] === 404, 'a flag cannot be claimed for a different entry');
parse_str((string) parse_url($target, PHP_URL_QUERY), $q);
$pic = picture(1000, 1400);
ok(call('POST', (string) $q['route'], [], $gs, $pic)['status'] === 200, 'the still is sent to that address');
$cc = call('POST', "/attendance/$cyc/photo-confirm", ['flag_uuid' => $cu, 'sha256' => hash('sha256', $pic)], $gs);
ok($cc['status'] === 200 && ($cc['body']['stored'] ?? false) === true && ($cc['body']['bytes'] ?? 0) > 0, 'photo-confirm with the matching fingerprint: stored');
ok(call('POST', "/attendance/$cyc/photo-confirm", ['flag_uuid' => $cu, 'sha256' => hash('sha256', 'other')], $gs)['status'] === 409, 'a fingerprint that does not match what was stored: 409');
ok(call('POST', "/attendance/$cyc/photo-upload-url", ['flag_uuid' => $cu], $gs)['status'] === 409, 'asking for another upload address once a photo exists: 409');

echo "13. Over HTTP: HR\n";
ok(call('GET', '/hr/flags')['status'] === 403, 'the gatekeeper cannot read the HR flag list');
$fid = (int) $mkf['body']['flag_id'];
ok(call('GET', "/hr/flags/$fid/photo")['status'] === 403, 'nor open a flagged photo through it');
$rs = signIn('hr@zas.test');
$fl = call('GET', '/hr/flags');
ok($fl['status'] === 200 && count($fl['body']['open'] ?? []) >= 4 && !str_contains($fl['raw'], 'attendance/'), 'HR gets the list, with no storage key in it');
$ph = call('GET', "/hr/flags/$fid/photo");
ok($ph['status'] === 200 && dims($ph['raw']) === [1280, 960, 'image/webp'] && str_contains($ph['headers'], 'no-store'), 'HR opens the flagged still');
ok(call('GET', "/hr/flags/$fid/photo", ['which' => 'reference'])['status'] === 200, 'and the reference photo for comparison');
ok(call('POST', "/hr/flags/$fid/review", ['reason' => ''], $rs)['status'] === 422, 'review without a finding: 422');
ok(call('POST', "/hr/flags/$fid/review", ['reason' => 'card lost, replacement issued'], $rs)['status'] === 200, 'review with one: saved');
ok(Db::value('SELECT status FROM att_flags WHERE id = ?', [$fid]) === 'reviewed' && (int) Db::value('SELECT reviewed_by FROM att_flags WHERE id = ?', [$fid]) === $hr, 'under HR\'s name');

echo "14. Over HTTP: the office loads and removes reference photos\n";
$cs = signIn('clerk@zas.test');
ok(call('GET', '/admin/photos/status')['status'] === 200, 'a clerk with "view" can see the photo status');
ok(call('POST', '/admin/employees/photo', ['id' => $emp['E005'], 'notice_given' => '1'], $cs, picture(900, 1200))['status'] === 403, 'but cannot load a photo');
ok(call('POST', '/admin/photos/import', ['notice_given' => true], $cs)['status'] === 403 && call('POST', '/admin/photos/test', [], $cs)['status'] === 403, 'nor bring photos in, nor run the connection test');
$as = signIn('admin@zas.test');
$nn = call('POST', '/admin/employees/photo', ['id' => $emp['E005']], $as, picture(900, 1200));
ok($nn['status'] === 422 && ($nn['body']['reason_code'] ?? '') === 'NOTICE_REQUIRED', 'the admin without the notice ticked: 422 NOTICE_REQUIRED');
$okp = call('POST', '/admin/employees/photo', ['id' => $emp['E005'], 'notice_given' => '1'], $as, picture(900, 1200));
ok($okp['status'] === 200 && ($okp['body']['width'] ?? 0) === 480, 'with it: stored at 480 × 600');
ok(call('GET', '/admin/employees/photo', ['id' => $emp['E005']])['status'] === 200, 'the Setup screen can show it back');
$lst = call('GET', '/admin/employees', ['query' => 'E005']);
ok((int) ($lst['body']['employees'][0]['has_photo'] ?? 0) === 1 && !str_contains($lst['raw'], 'attendance/'), 'the employee list shows "has photo", not the key');
$stt = call('GET', '/admin/photos/status')['body'];
ok(($stt['storage_connected'] ?? false) === true && ($stt['image_tools'] ?? false) === true && ($stt['with_photo'] ?? 0) === 4 && ($stt['employees'] ?? 0) === 5, 'status: storage connected, 4 of 5 employees have a photo');
$tst = call('POST', '/admin/photos/test', [], $as);
ok($tst['status'] === 200 && ($tst['body']['passed'] ?? false) === true, 'the connection test passes from the screen');
ok(call('POST', '/admin/employees/photo/remove', ['id' => $emp['E005'], 'reason' => 'left the company'], $as)['status'] === 200
   && call('GET', '/admin/employees/photo', ['id' => $emp['E005']])['status'] === 404, 'removed with a reason: gone');

echo "15. Giving the Gatekeeper job also gives the flag permission\n";
$newGk = $mk('Gulzar Second', 'gate2@zas.test');
call('POST', '/admin/jobs/grant', ['user_id' => $newGk, 'role' => 'gatekeeper'], $as);
$p = fn(string $m) => (string) Db::value('SELECT actions FROM zu_perm WHERE user_id = ? AND module = ?', [$newGk, $m]);
ok(str_contains($p('attgate'), 'c') && str_contains($p('attflag'), 'c'), 'a new gatekeeper can scan AND flag');
call('POST', '/admin/jobs/revoke', ['user_id' => $newGk, 'role' => 'gatekeeper'], $as);
ok($p('attgate') === '' && $p('attflag') === '', 'and loses both when the job is taken away');
$sett = call('GET', '/admin/settings')['body'];
ok(in_array('photo_retention_days', array_column($sett['settings'] ?? [], 'key'), true), 'the retention period is on the Settings tab');
ok(call('POST', '/admin/settings', ['values' => ['photo_retention_days' => 3]], $as)['status'] === 422, 'and cannot be set below a week');

/* ===================================================================== */
echo "16. In a browser: the phone, the Setup screen, the HR screen\n";
/* A shift that covers any time of day, so this part does not depend on when
   the tests happen to run. */
$allDay = Shifts::create(['name' => 'All day', 'start_time' => '00:00', 'end_time' => '23:59', 'grace_in_minutes' => 30, 'grace_out_minutes' => 30]);
Policy::set('default_shift_id', (string) $allDay, null, 'string'); Policy::flush();
$drop('employee-photos/E005.jpg', picture(1200, 1500));
$work = __DIR__ . '/.zattm5';
@mkdir($work, 0777, true);
file_put_contents($work . '/still.jpg', picture(2400, 3200));
file_put_contents($work . '/face.png', picture(900, 1300, 'png'));
file_put_contents($work . '/notes.txt', 'not a picture');
$shot = getenv('ATT_SHOT_DIR') ?: '';
file_put_contents($work . '/run.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2], SHOT = process.argv[3] || '', DIR = __dirname;
const out = [];
const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
async function phone(page, email) {
  await page.goto(BASE + '/att/');
  await page.waitForSelector('#email', { timeout: 20000 });
  await page.fill('#email', email);
  await page.fill('#password', 'CorrectHorse9');
  await page.click('#signin');
  await page.waitForSelector('#pin', { timeout: 20000 });
  await page.fill('#pin', '1234');
  await page.click('#pingo');
  await page.waitForSelector('#status', { timeout: 20000 });
  // Poll for a plain value: a promise handed back from the page counts as
  // "true" at once, which is how this once passed with an empty list.
  for (let i = 0; i < 100; i++) {
    if ((await page.evaluate(() => AttDB.getAll('employees').then(r => r.length))) > 0) return;
    await page.waitForTimeout(200);
  }
  throw new Error('the phone never loaded its employee list for ' + email);
}
async function desk(page, email) {
  await page.goto(BASE + '/att/manifest.webmanifest');
  return page.evaluate(async (email) => (await fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password: 'CorrectHorse9' }) })).status, email);
}
const state = (page, id) => page.getAttribute('#' + id, 'data-state');
const waiting = (page) => page.getAttribute('#waiting', 'data-count');
(async () => {
  const browser = await chromium.launch();
  try {
    // ============================================================ gate phone
    const ctx = await browser.newContext({ viewport: { width: 390, height: 800 } });
    const page = await ctx.newPage();
    const errors = [], urls = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('request', r => urls.push(r.url()));
    page.on('dialog', d => d.accept());
    await phone(page, 'gate@zas.test');

    await page.fill('#code', 'E001');
    await page.waitForSelector('#empphoto[data-state="shown"]', { timeout: 20000 });
    const nat = await page.evaluate(() => { const i = document.querySelector('#empphoto img'); return [i.naturalWidth, i.naturalHeight]; });
    check('TYPING A CODE SHOWS THE EMPLOYEE\'S PHOTO beside the name, before IN or OUT is pressed', nat[0] === 480 && nat[1] === 600, nat.join('x'));
    check('  with the name', /Imran Ali/.test(await page.textContent('#empname')));
    const photoUrls = urls.filter(u => /photo/.test(decodeURIComponent(u)));
    check('  fetched from this app\'s own API — the phone never contacts the storage', photoUrls.length >= 1 && photoUrls.every(u => u.startsWith(BASE + '/api/v1/')) && !urls.some(u => /:8750|r2\.cloudflarestorage|X-Amz/i.test(u)), photoUrls.join(' '));
    if (SHOT) await page.screenshot({ path: SHOT + '/att-m5-gate-photo.png' });

    await page.fill('#code', 'E003');
    await page.waitForSelector('#empphoto[data-state="none"]');
    check('an employee with no photo shows "No photo on file", and the name still shows', /No photo on file/.test(await page.textContent('#empphoto')) && /Bilal/.test(await page.textContent('#empname')));
    await page.fill('#code', 'ZZZ');
    await page.waitForFunction(() => document.getElementById('empphoto').hidden);
    check('an unknown code shows no photo box at all', true);

    // an ordinary IN: no camera, no picture
    await page.fill('#code', 'E004');
    await page.waitForSelector('#empphoto[data-state="shown"]');
    await page.click('#btn-in');
    await page.waitForSelector('#recent .item[data-state="acked"]', { timeout: 20000 });
    check('after IN is pressed the photo is cleared for the next person', await page.evaluate(() => document.getElementById('empphoto').hidden));
    check('AT07: an ordinary IN created no file input and asked for no camera', (await page.locator('input[type=file]').count()) === 0);

    // Capture & Flag
    await page.locator('#recent .item', { hasText: 'E004' }).first().locator('.flagbtn').click();
    await page.waitForSelector('#flag-sheet');
    await page.click('#flag-save');
    check('a flag with no reason chosen is refused on the phone', /Choose why/.test(await page.textContent('#flag-err')) && await page.isVisible('#flag-sheet'));
    await page.selectOption('#flag-reason', 'OTHER');
    await page.click('#flag-save');
    check('"Other" needs a note', /note/i.test(await page.textContent('#flag-err')));
    await page.selectOption('#flag-reason', 'FACE_MISMATCH');
    check('the camera input exists only inside the flag sheet, and asks for the back camera', (await page.locator('#flag-sheet input[type=file][capture=environment]').count()) === 1);
    await page.setInputFiles('#flag-file', DIR + '/still.jpg');
    await page.waitForSelector('#flag-preview img');
    if (SHOT) await page.screenshot({ path: SHOT + '/att-m5-flag.png' });
    await page.click('#flag-save');
    await page.waitForSelector('#recent .sub.flag[data-flag="acked"][data-photo="sent"]', { timeout: 30000 });
    check('a flag with a photo goes up, and says so in plain words', /recorded by the server, with its photo/.test(await page.textContent('#recent .sub.flag')));
    const kept = await page.evaluate(() => AttDB.getAll('flags').then(f => f.map(x => ({ state: x.state, ps: x.photo_state, has: !!x.photo }))));
    check('ONCE SENT, THE STILL IS REMOVED FROM THE PHONE', kept.length === 1 && kept[0].ps === 'sent' && kept[0].has === false, JSON.stringify(kept));
    check('a flagged entry no longer offers "Flag…" a second time', (await page.locator('#recent .item', { hasText: 'E004' }).first().locator('.flagbtn').count()) === 0);

    // offline
    await ctx.setOffline(true);
    await page.fill('#code', 'E001');
    await page.waitForSelector('#empphoto[data-state="offline"]');
    check('OFFLINE: the name still shows and the photo box says it needs a connection', /needs a connection/.test(await page.textContent('#empphoto')) && /Imran/.test(await page.textContent('#empname')));
    await page.fill('#code', 'E004');
    await page.click('#btn-out');
    await page.waitForSelector('#recent .item[data-state="pending"]');
    await page.locator('#recent .item[data-state="pending"]').locator('.flagbtn').click();
    await page.selectOption('#flag-reason', 'NO_CARD');
    await page.setInputFiles('#flag-file', DIR + '/still.jpg');
    await page.waitForSelector('#flag-preview img');
    await page.click('#flag-save');
    await page.waitForSelector('#recent .sub.flag[data-flag="pending"]');
    check('offline, the scan and its flag are both counted as waiting', (await waiting(page)) === '2', await waiting(page));
    check('  and the flag says it is on this phone, not sent', /on this phone, not sent yet/.test(await page.textContent('#recent .sub.flag[data-flag="pending"]')));
    await page.reload();
    await page.waitForSelector('#pin');
    await page.fill('#pin', '1234');
    await page.click('#pingo');
    await page.waitForSelector('#status');
    const held = await page.evaluate(() => AttDB.getAll('flags').then(f => f.filter(x => x.state === 'pending').map(x => x.photo ? x.photo.size : 0)));
    check('after closing and reopening the app offline, the flag and its still are both still there', held.length === 1 && held[0] > 1000, JSON.stringify(held));
    check('  and the still was made small on the phone before being kept', held[0] < 600000, held[0]);
    await ctx.setOffline(false);
    await page.evaluate(() => AttApp.syncNow());
    await page.waitForFunction(() => document.getElementById('waiting').getAttribute('data-count') === '0', null, { timeout: 30000 });
    await page.evaluate(() => AttApp.refreshLists());
    await page.waitForSelector('#recent .sub.flag[data-flag="acked"][data-photo="sent"]');
    check('back online, the scan goes first, then its flag, then the still — and nothing is left waiting', true);

    const stored = await page.evaluate(async () => {
      const keys = await caches.keys(); const cached = [];
      for (const k of keys) { for (const r of await (await caches.open(k)).keys()) cached.push(r.url); }
      const emps = await AttDB.getAll('employees');
      return { cached, fields: Object.keys(emps[0] || {}), blobs: emps.filter(e => Object.values(e).some(v => v instanceof Blob)).length };
    });
    check('NO EMPLOYEE PHOTO IS KEPT ON THE PHONE: none in the app cache', !stored.cached.some(u => /photo|\/api\//.test(decodeURIComponent(u))), stored.cached.join(' '));
    check('  and none in its employee list, which holds a yes/no only', stored.blobs === 0 && stored.fields.includes('has_photo') && !stored.fields.some(f => /key|url|etag/.test(f)), stored.fields.join(','));
    check('no script error on the gate phone', errors.length === 0, errors.join(' | '));
    await ctx.close();

    // ============================================================= HOD phone
    const hctx = await browser.newContext({ viewport: { width: 390, height: 800 } });
    const hp = await hctx.newPage();
    hp.on('dialog', d => d.accept());
    await phone(hp, 'hod@zas.test');
    check('the HOD has no gate tab and so nothing to flag from', !(await hp.isVisible('#tab-gate')) && (await hp.locator('.flagbtn').count()) === 0);
    await hp.fill('#hodcode', 'E001');
    await hp.waitForSelector('#hodphoto[data-state="shown"]', { timeout: 20000 });
    check('THE HOD SEES THE PHOTO TOO when a code is typed, with the name', /Imran/.test(await hp.textContent('#hodname')));
    await hp.waitForSelector('#pendinglist .seephoto', { timeout: 20000 });
    await hp.locator('#pendinglist .seephoto').first().click();
    await hp.waitForSelector('#photo-sheet img');
    await hp.waitForFunction(() => { const i = document.querySelector('#photo-sheet img'); return i.complete && i.naturalWidth === 480; }, null, { timeout: 20000 });
    check('  and from the waiting list, before ticking an entry', true);
    if (SHOT) await hp.screenshot({ path: SHOT + '/att-m5-hod-photo.png' });
    await hctx.close();

    // ========================================================== Setup screen
    const dctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const dp = await dctx.newPage();
    const derr = [];
    dp.on('pageerror', e => derr.push(e.message));
    dp.on('dialog', d => d.accept('retaking it'));
    await desk(dp, 'admin@zas.test');
    await dp.goto(BASE + '/att_setup.php#emp');
    await dp.waitForSelector('#atts-body table');
    const row = () => dp.locator('#atts-body tr', { hasText: 'E003' });
    check('the employee list shows who has a photo', /None/.test(await row().textContent()) && /On file/.test(await dp.locator('#atts-body tr', { hasText: 'E001' }).textContent()));
    await row().locator('button:has-text("Photo")').click();
    await dp.waitForSelector('#attsdlg[open]');
    await dp.click('#attsdlg-ok');
    check('saving with no picture chosen is refused', /Choose a picture/.test(await dp.textContent('#attsdlg-err')));
    await dp.setInputFiles('#ph-file', DIR + '/notes.txt');
    await dp.check('#ph-notice');
    await dp.click('#attsdlg-ok');
    await dp.waitForFunction(() => /not a JPG/.test(document.getElementById('attsdlg-err').textContent));
    check('a text file chosen as the photo is refused, with the reason', true);
    await dp.setInputFiles('#ph-file', DIR + '/face.png');
    await dp.uncheck('#ph-notice');
    await dp.click('#attsdlg-ok');
    await dp.waitForFunction(() => /told/.test(document.getElementById('attsdlg-err').textContent));
    check('a real picture without the notice ticked is refused', true);
    await dp.check('#ph-notice');
    await dp.click('#attsdlg-ok');
    await dp.waitForSelector('#ph-done:not(:empty)', { timeout: 20000 });
    const saved = await dp.textContent('#ph-done');
    check('with the notice ticked it saves, and says the size it was stored at', /480 × 600, \d+ KB/.test(saved), saved);
    await dp.waitForFunction(() => { const i = document.querySelector('img#ph-current'); return i && i.complete && i.naturalWidth === 480 && i.naturalHeight === 600; }, null, { timeout: 20000 });
    check('  and shows the stored picture back: 480 × 600 from a 900 × 1300 upload', true);
    if (SHOT) await dp.screenshot({ path: SHOT + '/att-m5-setup-photo.png' });
    await dp.click('#attsdlg-cancel');
    await dp.waitForFunction(() => /On file/.test([...document.querySelectorAll('#atts-body tr')].find(r => /E003/.test(r.textContent)).textContent));
    check('the list now shows E003 with a photo on file', true);

    await dp.click('#atts-tabs button:has-text("Photos")');
    await dp.waitForSelector('#atts-body .facts');
    const facts = await dp.textContent('#atts-body .facts');
    check('the Photos tab says how many have a photo and that storage is connected', /4 of 5/.test(facts) && /Connected/.test(facts) && /Ready/.test(facts), facts);
    await dp.click('button:has-text("Test the connection")');
    await dp.waitForSelector('#test-steps li');
    check('the connection test shows four steps, all passing', (await dp.locator('#test-steps li.okmsg').count()) === 4);
    await dp.click('button:has-text("Bring in photos")');
    await dp.waitForSelector('#atts-body .err:has-text("Tick the box")');
    check('bringing photos in without the notice ticked is refused', true);
    await dp.check('#imp-notice');
    await dp.click('button:has-text("Bring in photos")');
    await dp.waitForSelector('#imp-result', { timeout: 60000 });
    const res = await dp.textContent('#atts-body');
    check('bringing in from the bucket folder adds the one new photo and leaves the rest', /1 photo brought in\. 3 were already up to date/.test(res), (await dp.textContent('#imp-result')));
    check('  it names the file that was not a picture and the ones matching nobody', /E003\.jpg/.test(res) && /X999\.jpg/.test(res));
    if (SHOT) await dp.screenshot({ path: SHOT + '/att-m5-setup-photos.png', fullPage: true });
    const pageText = await dp.content();
    check('nowhere on the Setup screen is a storage key, an access key or a secret', !/attendance\/ref|TESTKEY|test-secret/.test(pageText));
    check('no script error on the Setup screen', derr.length === 0, derr.join(' | '));

    // ============================================================= HR screen
    const rctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const rp = await rctx.newPage();
    const rerr = [];
    rp.on('pageerror', e => rerr.push(e.message));
    await desk(rp, 'hr@zas.test');
    await rp.goto(BASE + '/att_hr_reconciliation.php');
    await rp.waitForSelector('#attq h2');
    check('HR sees "Flagged entries" first on the reconciliation screen', /^Flagged entries/.test((await rp.textContent('#attq h2')).trim()));
    const frow = rp.locator('#attq tr', { hasText: 'Nadia Noor' }).filter({ hasText: 'Face does not match' });
    await frow.locator('button:has-text("See photos")').click();
    await rp.waitForSelector('#attphoto[open]');
    await rp.waitForFunction(() => { const im = [...document.querySelectorAll('#attphoto img')]; return im.length === 2 && im.every(i => i.complete && i.naturalWidth > 0); }, null, { timeout: 20000 });
    const sizes = await rp.evaluate(() => [...document.querySelectorAll('#attphoto img')].map(i => i.naturalWidth + 'x' + i.naturalHeight));
    check('"See photos" puts the photo on file beside the one taken at the gate', sizes[0] === '480x600' && sizes[1] === '960x1280', sizes.join(' '));
    if (SHOT) await rp.screenshot({ path: SHOT + '/att-m5-hr-flag.png' });
    await rp.click('#ph-close');
    await frow.locator('button:has-text("Mark reviewed")').click();
    await rp.waitForSelector('#attdlg[open]');
    await rp.click('#dlg-ok');
    check('marking reviewed without a finding is refused', await rp.isVisible('#attdlg[open]'));
    await rp.fill('#dlg-reason', 'Supervisor confirmed identity. Photo on file is old; retake arranged.');
    await rp.click('#dlg-ok');
    await rp.waitForFunction(() => !document.querySelector('#attdlg[open]') && /reviewed in the last 7 days/.test(document.getElementById('attq').textContent), null, { timeout: 20000 });
    check('with a finding it moves to the reviewed list', true);
    check('no script error on the HR screen', rerr.length === 0, rerr.join(' | '));
    await rctx.close();
    await dctx.close();
  } catch (e) { check('the browser run completed', false, e && e.message); }
  finally { await browser.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
$views0 = $n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'photo.view'");
$raw = (string) shell_exec('cd ' . escapeshellarg($work) . ' && timeout 400 node run.js ' . escapeshellarg("http://127.0.0.1:$port") . ' ' . escapeshellarg($shot) . ' 2>&1');
$results = [];
if (preg_match('/RESULT(\[.*\])\s*$/s', $raw, $m)) { $results = json_decode($m[1], true) ?: []; }
ok(count($results) >= 40, 'the browser run reported its checks (' . count($results) . ')' . (count($results) ? '' : "\n$raw"));
foreach ($results as $r) { ok($r['pass'], $r['name'] . ($r['pass'] ? '' : ' — ' . $r['extra'])); }

echo "17. What the browser run left behind\n";
$e4 = Db::all('SELECT f.reason_code, f.photo_key, f.photo_bytes, f.entry_cycle_id, f.status FROM att_flags f WHERE f.employee_id = ? ORDER BY f.id', [$emp['E004']]);
ok(count($e4) === 2 && $e4[0]['reason_code'] === 'FACE_MISMATCH' && $e4[1]['reason_code'] === 'NO_CARD', 'two flags for E004 on the server: one made online, one made offline');
ok($e4[0]['photo_key'] !== null && $e4[1]['photo_key'] !== null && is_file("$r2dir/objects/{$e4[1]['photo_key']}"), 'each with its still in the bucket');
ok(dims(file_get_contents("$r2dir/objects/{$e4[1]['photo_key']}")) === [960, 1280, 'image/webp'] && (int) $e4[1]['photo_bytes'] < 400000, 'stored as 960 × 1280 WebP');
ok((int) $e4[0]['entry_cycle_id'] > 0 && (int) $e4[0]['entry_cycle_id'] === (int) $e4[1]['entry_cycle_id'], 'both tied to the same entry: the IN that opened it and the OUT that closed it');
ok($e4[0]['status'] === 'reviewed' && $e4[1]['status'] === 'open', 'the one HR reviewed is reviewed; the other is still open');
ok(Db::value('SELECT reference_photo_key FROM att_employees WHERE id = ?', [$emp['E003']]) !== null
   && Db::value('SELECT reference_photo_key FROM att_employees WHERE id = ?', [$emp['E005']]) !== null, 'E003 (from the Setup dialog) and E005 (from the bucket folder) now have photos');
$shown = $n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'photo.view'") - $views0;
/* Gate phone 2+, HOD phone 2, Setup 1, HR 2. */
ok($shown >= 7, "every picture shown on any of the three screens added a line to the audit log ($shown)");
$gateViews = $n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'photo.view' AND actor_id = ? AND JSON_EXTRACT(new_data, '$.purpose') = 'code entered on a phone'", [$gk]);
ok($gateViews >= 3, "including each time the gatekeeper's phone showed one ($gateViews)");

/* AT07, read off the app's own source: the camera can TAKE a picture in one
   function only. */
$app = (string) file_get_contents($B . 'att/app.js');
$fn = function (string $name) use ($app): string {
    $start = strpos($app, "function $name(");
    if ($start === false) { return ''; }
    $next = strpos($app, "\n  function ", $start + 10);
    return substr($app, $start, ($next === false ? strlen($app) : $next) - $start);
};
ok(substr_count($app, "type: 'file'") === 1 && str_contains($fn('openFlag'), "type: 'file'") && substr_count($app, 'capture:') === 1 && str_contains($fn('openFlag'), 'capture:'),
   'AT07: the app has ONE picture input, and it is inside openFlag()');
$ordinary = $fn('gateAction') . $fn('saveEvent') . $fn('hodByCode') . $fn('hodConfirmSelected');
ok(strlen($ordinary) > 1500 && !preg_match('/openFlag|shrink\(|upload\(|toBlob|capture|type: \'file\'|FormData/', $ordinary),
   'AT07: recording IN, OUT or a HOD confirmation reaches none of the picture code');
$scanFn = $fn('scanInto');
ok(str_contains($scanFn, 'getUserMedia') && !preg_match('/toBlob|canvas|upload\(|AttDB\.put|FormData/', $scanFn), 'the code scanner uses the camera to read, and has no way to keep or send a frame');
$sw = (string) file_get_contents($B . 'att/sw.js');
ok(str_contains($sw, "indexOf('/api/') !== -1) return") && str_contains($sw, 'zas-att-shell-v2'), 'the service worker still never touches /api/ (so never a photo), and its version was raised for the new app files');

echo "18. Rolling back\n";
$mig->down(12);
ok(!Db::tableExists('att_flags') && !Db::columnExists('att_employees', 'reference_photo_etag') && Db::columnExists('att_employees', 'reference_photo_key'),
   'down to 12 removes the flag table and the two bookkeeping columns, and nothing older');
$mig->up();
ok(Db::tableExists('att_flags'), 'and up restores them');

echo "\n$P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
