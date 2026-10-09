<?php
/* BOOT THE WHOLE PACKING PAGE AND READ WHAT IT DREW.
 *
 * The dashboard went to a 500 over a function the page called and never
 * required, and the fragment tests all passed because the harness had
 * stubbed that function. Reading a file is not running it.
 *
 * So this runs the REAL m_pack.php — the shipped file, with the real
 * includes/packing.php and the real includes/mobile.php beside it — over
 * a fake PDO that answers in the shapes the live tables answer in. Then
 * Chromium loads the output and the arithmetic on screen is read back.
 *
 * The sample data is his own sentence, in three ranges:
 *   Carton 1–100    assorted  2 Small + 4 Medium + 4 Large  = 1,000 sets
 *   Carton 101–225  per       40 of 152x200                 = 5,000 pcs
 *   Bale   226–245  direct    4,000 total                   = 4,000 pcs
 *
 * What it asserts:
 *   every tab renders with no PHP warning and no javascript error
 *   the serial produces the count, on screen, in all three ranges
 *   Direct shows a total and no per-package field
 *   the assorted page adds up and shows the size-by-size table
 *   the weight lines fill down to balanced
 *   the approve tab's totals are the sum of the three ranges
 */

$B    = __DIR__ . '/app_src/public_html/';
$work = __DIR__ . '/.zpackboot';
$P = 0; $F = 0;
function ok(bool $c, string $m, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $m" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }

@mkdir($work . '/includes', 0777, true);
foreach (glob($work . '/*.html') ?: [] as $old) @unlink($old);

/* The real files. Copies, not stubs — a stub is precisely what hides a
   missing require. */
copy($B . 'm_pack.php',            $work . '/m_pack.php');
copy($B . 'includes/packing.php',  $work . '/includes/packing.php');
copy($B . 'includes/mobile.php',   $work . '/includes/mobile.php');

/* ------------------------------------------------------------ the fake DB */
$bootstrap = <<<'PHP'
<?php
session_start();
$_SESSION['user_id'] = 1; $_SESSION['role'] = 'admin'; $_SESSION['_csrf'] = 'testtoken';
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function require_login(){}
function verify_csrf(){}
function csrf_token(){ return 'testtoken'; }
function csrf_field(){ return '<input type="hidden" name="_csrf" value="testtoken">'; }
function current_user(): ?array { return ['id'=>1,'name'=>'Afnan','email'=>'a@z','role'=>'admin','can_see_rates'=>1]; }
function is_admin(){ return true; }
function is_colleague(){ return false; }
function is_staff(){ return false; }
function is_production_staff(){ return false; }
function assigned_shipment_ids(){ return ['ALL']; }
function redirect($u){ echo "\n__REDIRECT__ $u || " . ($_SESSION['error'] ?? $_SESSION['flash'] ?? '') . "\n"; exit; }
function audit_log(){}

/* ---- the rows the live tables would give back ---------------------- */
$GLOBALS['ROWS'] = [
  'shipment' => ['id'=>1,'invoice_no'=>'ZAS/5191','buyer_name'=>'Gulf Textiles LLC',
                 'status'=>'draft','packing_status'=>'open'],
  'items' => [
    ['id'=>11,'product_name'=>'Duvet Cover Set King','des_col'=>'Fleece + micro, printed','optional_value'=>'HS 6302.31','qty'=>900],
    ['id'=>12,'product_name'=>'Hotel Flat Sheet 300TC','des_col'=>'Percale, white','optional_value'=>'HS 6302.21','qty'=>5200],
    ['id'=>13,'product_name'=>'Bath Towel 500GSM','des_col'=>'Combed, dobby','optional_value'=>'HS 6302.60','qty'=>3800],
  ],
  /* The stored packages column is DELIBERATELY WRONG — 999, 7, 3 against
     serials that say 100, 125, 20. The whole model is that the serial
     wins, and a fixture where the two agree cannot tell which one the
     page read. Every count asserted below is the serial's answer. */
  'groups' => [
    ['id'=>101,'shipment_id'=>1,'line_no'=>1,'invoice_item_id'=>11,'product_name'=>'Duvet Cover Set King',
     'des_col'=>'Fleece + micro, printed','optional_value'=>'HS 6302.31','unit_title'=>'Carton',
     'serial_from'=>1,'serial_to'=>100,'packages'=>999,'qty_mode'=>'per','qty_per_pkg'=>10,'total_qty'=>1000,
     'assorted'=>1,'size_label'=>'','pkg_gross'=>11.420,'pkg_tare'=>1.000],
    ['id'=>102,'shipment_id'=>1,'line_no'=>2,'invoice_item_id'=>12,'product_name'=>'Hotel Flat Sheet 300TC',
     'des_col'=>'Percale, white','optional_value'=>'HS 6302.21','unit_title'=>'Carton',
     'serial_from'=>101,'serial_to'=>225,'packages'=>7,'qty_mode'=>'per','qty_per_pkg'=>40,'total_qty'=>5000,
     'assorted'=>0,'size_label'=>'152x200','pkg_gross'=>21.000,'pkg_tare'=>1.200],
    ['id'=>103,'shipment_id'=>1,'line_no'=>3,'invoice_item_id'=>13,'product_name'=>'Bath Towel 500GSM',
     'des_col'=>'Combed, dobby','optional_value'=>'HS 6302.60','unit_title'=>'Bale',
     'serial_from'=>226,'serial_to'=>245,'packages'=>3,'qty_mode'=>'direct','qty_per_pkg'=>200,'total_qty'=>4000,
     'assorted'=>0,'size_label'=>'Single','pkg_gross'=>92.000,'pkg_tare'=>2.000],
  ],
  'sizes' => [
    101 => [['id'=>1,'group_id'=>101,'size_label'=>'Small','qty_per_pkg'=>2,'total_qty'=>200],
            ['id'=>2,'group_id'=>101,'size_label'=>'Medium','qty_per_pkg'=>4,'total_qty'=>400],
            ['id'=>3,'group_id'=>101,'size_label'=>'Large','qty_per_pkg'=>4,'total_qty'=>400]],
    102 => [['id'=>4,'group_id'=>102,'size_label'=>'152x200','qty_per_pkg'=>40,'total_qty'=>5000]],
    103 => [['id'=>5,'group_id'=>103,'size_label'=>'Single','qty_per_pkg'=>200,'total_qty'=>4000]],
  ],
  /* 2x850 + 4x1030 + 4x1150 = 10 420 g, against 11.420 - 1.000 = 10.420 kg */
  'wlines' => [
    '101|Small'   => [['w_type'=>'Fabric','w_name'=>'Fleece 220gsm','grams'=>400],
                      ['w_type'=>'Fabric','w_name'=>'Micro peach','grams'=>160],
                      ['w_type'=>'Fiber / filling','w_name'=>'Polyester','grams'=>90],
                      ['w_type'=>'PVC / poly bag','w_name'=>'','grams'=>80],
                      ['w_type'=>'Cardboard / paper','w_name'=>'Board','grams'=>60],
                      ['w_type'=>'Accessories','w_name'=>'Label','grams'=>60]],
    '101|Medium'  => [['w_type'=>'Fabric','w_name'=>'Fleece 220gsm','grams'=>500],
                      ['w_type'=>'Fabric','w_name'=>'Micro peach','grams'=>190],
                      ['w_type'=>'Fiber / filling','w_name'=>'Polyester','grams'=>110],
                      ['w_type'=>'PVC / poly bag','w_name'=>'','grams'=>90],
                      ['w_type'=>'Cardboard / paper','w_name'=>'Board','grams'=>70],
                      ['w_type'=>'Accessories','w_name'=>'Label','grams'=>70]],
    '101|Large'   => [['w_type'=>'Fabric','w_name'=>'Fleece 220gsm','grams'=>560],
                      ['w_type'=>'Fabric','w_name'=>'Micro peach','grams'=>210],
                      ['w_type'=>'Fiber / filling','w_name'=>'Polyester','grams'=>130],
                      ['w_type'=>'PVC / poly bag','w_name'=>'','grams'=>100],
                      ['w_type'=>'Cardboard / paper','w_name'=>'Board','grams'=>75],
                      ['w_type'=>'Accessories','w_name'=>'Label','grams'=>75]],
    /* 495 g x 40 = 19.800 kg, against 21.000 - 1.200 */
    '102|152x200' => [['w_type'=>'Fabric','w_name'=>'Percale 300TC','grams'=>420],
                      ['w_type'=>'PVC / poly bag','w_name'=>'','grams'=>30],
                      ['w_type'=>'Cardboard / paper','w_name'=>'Insert','grams'=>40],
                      ['w_type'=>'Accessories','w_name'=>'Label','grams'=>5]],
    /* 450 g x 200 = 90.000 kg, against 92.000 - 2.000 */
    '103|Single'  => [['w_type'=>'Fabric','w_name'=>'Terry 500gsm','grams'=>400],
                      ['w_type'=>'PVC / poly bag','w_name'=>'','grams'=>20],
                      ['w_type'=>'Cardboard / paper','w_name'=>'Band','grams'=>25],
                      ['w_type'=>'Accessories','w_name'=>'Label','grams'=>5]],
  ],
  /* something remembered for one product and size, and nothing for the rest */
  'std' => ['bath towel 500 gsm|Single' => [
      ['w_type'=>'Fabric','w_name'=>'Terry 500gsm','grams'=>400],
      ['w_type'=>'PVC / poly bag','w_name'=>'','grams'=>20]]],
];

final class BStmt {
    private array $rows = [];
    public function __construct(private string $sql) {}
    public function execute(array $a = []): bool { $this->rows = BPdo::route($this->sql, $a); return true; }
    public function fetchAll(): array { return $this->rows; }
    public function fetch() { return $this->rows[0] ?? false; }
    public function fetchColumn() {
        $r = $this->rows[0] ?? null;
        return $r === null ? false : reset($r);
    }
}
final class BPdo {
    public function prepare(string $s) { return new BStmt($s); }
    public function query(string $s) { $st = new BStmt($s); $st->execute(); return $st; }
    public function exec(string $s) { return 0; }
    public function beginTransaction() { return true; }
    public function commit() { return true; }
    public function rollBack() { return true; }
    public function inTransaction() { return false; }
    public function lastInsertId() { return '999'; }

    /* One place that knows which canned rows a query wants. Matched on a
       distinctive fragment so a rewritten query fails loudly here rather
       than silently returning nothing. */
    public static function route(string $sql, array $a): array {
        $R = $GLOBALS['ROWS'];
        $q = preg_replace('/\s+/', ' ', $sql);

        if (str_contains($q, "k='pack_schema_version'")) return [['v' => '1']];
        if (str_contains($q, 'FROM shipments WHERE id=?'))  return [$R['shipment']];
        if (str_contains($q, 'FROM shipment_items'))        return $R['items'];
        if (str_contains($q, 'FROM packing_groups WHERE shipment_id=?')) return $R['groups'];
        if (str_contains($q, 'FROM packing_groups WHERE id=?')) {
            foreach ($R['groups'] as $g) if ((int)$g['id'] === (int)($a[0] ?? 0)) return [$g];
            return [];
        }
        if (str_contains($q, 'FROM packing_group_sizes WHERE group_id=?'))
            return $R['sizes'][(int)($a[0] ?? 0)] ?? [];
        if (str_contains($q, 'SELECT DISTINCT size_label FROM packing_group_sizes'))
            return [['size_label' => 'Small'], ['size_label' => 'Medium'],
                    ['size_label' => 'Large'], ['size_label' => '152x200'], ['size_label' => 'Single']];
        if (str_contains($q, 'COALESCE(SUM(grams),0) g')) {
            $out = [];
            foreach ($R['wlines'] as $k => $lines) {
                [$gid, $size] = explode('|', $k);
                if ((int)$gid !== (int)($a[0] ?? 0)) continue;
                $out[] = ['size_label' => $size, 'g' => array_sum(array_column($lines, 'grams'))];
            }
            return $out;
        }
        if (str_contains($q, 'FROM packing_weight_lines WHERE group_id=? AND size_label=?'))
            return $R['wlines'][((int)($a[0] ?? 0)) . '|' . ($a[1] ?? '')] ?? [];
        if (str_contains($q, 'FROM packing_weight_std'))
            return $R['std'][($a[0] ?? '') . '|' . ($a[1] ?? '')] ?? [];
        if (str_contains($q, 'FROM product_sizes ps JOIN products'))
            return [['size_label' => 'King'], ['size_label' => 'Queen']];
        if (str_contains($q, 'FROM shipments ORDER BY id DESC'))
            return [$R['shipment']];
        return [];
    }
}
function db() { static $p = null; if ($p === null) $p = new BPdo(); return $p; }
PHP;
file_put_contents($work . '/includes/bootstrap.php', $bootstrap);
file_put_contents($work . '/includes/export.php', "<?php\nfunction exp_ensure_schema(){}\n");

/* --------------------------------------------------------------- run it */
function render(string $work, array $get): array {
    $php = PHP_BINARY;
    $q = http_build_query($get);
    /* display_errors on, so a notice or a warning shows up in the output
       and is caught below instead of being swallowed. */
    /* The env assignment goes AFTER the &&, or it applies to cd and the
       page is run without its query string — which looks exactly like a
       page that renders nothing. */
    $cmd = 'cd ' . escapeshellarg($work) . ' && QS=' . escapeshellarg($q) . ' '
         . escapeshellarg($php) . ' -d display_errors=1 -d error_reporting=E_ALL'
         . ' -r ' . escapeshellarg('$_GET=[];parse_str((string)getenv("QS"),$_GET);$_SERVER["REQUEST_METHOD"]="GET";require "m_pack.php";')
         . ' 2>&1';
    $out = shell_exec($cmd);
    return [(string)$out, $q];
}

head('1. Every tab renders, with nothing complaining');

$pages = [
    'list'    => [],
    'serial'  => ['id' => 1, 't' => 'serial'],
    'weight'  => ['id' => 1, 't' => 'weight'],
    'weight2' => ['id' => 1, 't' => 'weight', 'g' => 102],
    'weight3' => ['id' => 1, 't' => 'weight', 'g' => 103],
    'mix'     => ['id' => 1, 't' => 'mix', 'g' => 101],
    'approve' => ['id' => 1, 't' => 'approve'],
];
$html = [];
foreach ($pages as $name => $get) {
    [$out] = render($work, $get);
    $html[$name] = $out;
    file_put_contents($work . '/' . $name . '.html', $out);

    ok(!preg_match('~(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught)~i', $out),
       "$name renders without a PHP complaint",
       preg_match('~^.*(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught).*$~mi', $out, $mm) ? $mm[0] : null);
    ok(str_contains($out, '</html>'), "$name reaches the end of the page");
    ok(!str_contains($out, '__REDIRECT__'), "$name does not bail out to a redirect");
}

head('2. The serial produces the count — in the markup, and on screen');

/* THESE MOVED INTO THE BROWSER, and the reason matters: the derived
   lines are no longer written by PHP. The toggles are live now, so the
   package count, the quantity label and the total are produced by the
   script the moment anything changes. Asserting on the HTML string
   would only prove the placeholders exist. Section 7 drives them. */
$ser = $html['serial'];
/* An <input> tag, not the string — the page's own script contains
   querySelector('[name="serial_from"]') and a plain count caught that
   too, which is how this assertion first went wrong. */
ok(preg_match_all('~<input[^>]*name="serial_from"~', $ser) === 4,
   'three ranges plus the add-another card, each asking for a serial',
   preg_match_all('~<input[^>]*name="serial_from"~', $ser));
ok(!preg_match('~<input[^>]*name="packages"~', $ser),
   'nothing on the page types a package count');
ok(preg_match('~<input[^>]*name="serial_from"[^>]*value="246"~', $ser) === 1,
   'the next range is offered starting at 246',
   preg_match_all('~name="serial_from"[^>]*value="(\d*)"~', $ser, $mm) ? $mm[1] : null);
ok(!preg_match('~fix(ed)?\s*qty~i', $ser), 'no fixed-quantity wording anywhere');

head('3. Both toggles are present on every range, and carry the choice');

ok(preg_match_all('~<input[^>]*name="qty_mode"~', $ser) === 8,
   'per package / direct qty on all four cards',
   preg_match_all('~<input[^>]*name="qty_mode"~', $ser));
ok(preg_match_all('~<input[^>]*name="size_mode"~', $ser) === 8,
   'one size / assorted on all four cards',
   preg_match_all('~<input[^>]*name="size_mode"~', $ser));
/* The tag, not the word — the script's own querySelector('[data-qtylabel]')
   is in this page too, and counting the word found five on four cards. */
ok(preg_match_all('~<span[^>]*data-qtylabel~', $ser) === 4,
   'and a label for the script to rewrite on each',
   preg_match_all('~<span[^>]*data-qtylabel~', $ser));
/* Without a script every field is still there and still posts. */
ok(preg_match_all('~<input[^>]*name="single_qty"~', $ser) === 4
   && preg_match_all('~<select[^>]*name="single_size"~', $ser) === 4,
   'the quantity and size fields exist on every card, script or no script');
/* A required field that a toggle hides cannot be filled in, and the
   browser then refuses to submit without saying why. */
ok(!preg_match('~<input[^>]*name="single_qty"[^>]*required~', $ser)
   && !preg_match('~<select[^>]*name="single_size"[^>]*required~', $ser),
   'neither is marked required, because a toggle can hide them',
   'a hidden required field makes the form look dead');

head('4. Assorted is a page of its own, and it adds up');

$mix = $html['mix'];
ok(str_contains($mix, 'Assorted'), 'the assorted page is titled for its serial');
ok(str_contains($mix, 'Carton 1') && str_contains($mix, '100'), 'and names the range it belongs to');
ok(substr_count($mix, 'name="size_label[]"') === 3, 'three size rows',
   substr_count($mix, 'name="size_label[]"'));
ok(str_contains($mix, 'value="2"') && str_contains($mix, 'value="4"'),
   'holding 2 and 4 from the saved set');
ok(str_contains($ser, '2 Small, 4 Medium, 4 Large'),
   'and the serial tab shows the mix without opening it');

head('5. Weight — every size comes down with the page');

$w1 = $html['weight'];
ok(str_contains($w1, '2 Small + 4 Medium + 4 Large'), 'what is inside one package is spelled out');
ok(str_contains($w1, '10.420 kg'), 'the contents must come to 10.420 kg');
ok(str_contains($w1, 'balanced'), 'and the package balances');
/* The chips are built by the script from this, so the data is what
   matters in the markup. */
ok(preg_match('~var SIZES\s*=\s*\["Small","Medium","Large"\]~', $w1) === 1,
   'all three sizes are carried down', null);
ok(str_contains($w1, 'Fleece 220gsm') && str_contains($w1, 'Micro peach'),
   'with every size\'s lines, not just the one being looked at');
ok(substr_count($w1, '"Small":') >= 1 && substr_count($w1, '"Large":') >= 1,
   'keyed by size, ready to switch without a page load');
ok(str_contains($w1, 'name="weights_json"'),
   'and one field carries them all back');
ok(!preg_match('~name="recall"~', $w1),
   'the recall button no longer posts — it fills from what is already here');

$w2 = $html['weight2'];
ok(str_contains($w2, '19.800 kg'), 'range 2: 495 g x 40 is 19.800 kg');
ok(str_contains($w2, 'Percale 300TC'), 'its lines come back with their names');
ok(preg_match('~var SIZES\s*=\s*\["152x200"\]~', $w2) === 1,
   'a single-size range carries one size', null);

$w3 = $html['weight3'];
ok(str_contains($w3, '90.000 kg'), 'range 3: 450 g x 200 is 90.000 kg');
ok(preg_match('~var STD\s*=\s*\{"Single":~', $w3) === 1,
   'the remembered breakdown comes down for the product that has one', null);
ok(preg_match('~var STD\s*=\s*\{\}~', $w2) === 1,
   'and not for a product that has none', null);

head('6. Approve adds the three ranges up');

$ap = $html['approve'];
/* 245 packages; 1,000 + 5,000 + 4,000 = 10,000
   net  = 10.420x100 + 19.800x125 + 90.000x20 = 1042 + 2475 + 1800 = 5,317.000
   gross= net + 1.000x100 + 1.200x125 + 2.000x20 = 5317 + 100 + 150 + 40 = 5,607.000 */
ok(str_contains($ap, '>245<'), 'total packages 245', null);
ok(str_contains($ap, '10,000.00'), 'total quantity 10,000');
ok(str_contains($ap, '5,317.000 kg'), 'net 5,317.000 kg');
ok(str_contains($ap, '5,607.000 kg'), 'gross 5,607.000 kg');
ok(str_contains($ap, 'Every package balances'), 'nothing is flagged unfinished');
ok(str_contains($ap, 'It is not compared with the invoice'), 'and it says the invoice is not the judge');
ok(substr_count($ap, '<tr>') >= 6, 'the size-by-size table has a row per size',
   substr_count($ap, '<tr>'));
ok(str_contains($ap, 'value="5317.000"'), 'the final net is pre-filled with the calculated figure');

/* ------------------------------------------------------------- Chromium */
head('7. Chromium loads every page with no javascript error');

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
$root = trim((string)shell_exec('cd ' . escapeshellarg(__DIR__) . ' && npm root -g 2>/dev/null'));
if ($node === '' || !is_dir($root . '/playwright')) {
    echo "  (skipped — playwright not available)\n";
} else {
    $js = <<<'JS'
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
(async () => {
  const dir = process.argv[2];
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const f of fs.readdirSync(dir).filter(x => x.endsWith('.html'))) {
    const p = await b.newPage({ viewport: { width: 390, height: 844 } });
    const errs = [];
    p.on('pageerror', e => errs.push(String(e)));
    p.on('console', m => { if (m.type() === 'error') errs.push('console: ' + m.text()); });
    await p.goto('file://' + path.join(dir, f));
    await p.waitForTimeout(250);
    const r = { errors: errs };
    r.wlines   = await p.locator('#wlines .wrow').count();
    r.balances = await p.locator('#wlines .wbal').allInnerTexts();
    r.perunit  = await p.locator('#perunit').count() ? await p.locator('#perunit').innerText() : '';
    r.mixtot   = await p.locator('#tot').count() ? await p.locator('#tot').innerText() : '';
    r.mixfoot  = await p.locator('#foot').count() ? await p.locator('#foot').innerText() : '';
    r.dev      = await p.locator('#dev').count() ? (await p.locator('#dev').innerText()).replace(/\n/g, ' | ') : '';
    out[f.replace('.html', '')] = r;
    await p.close();
  }
  await b.close();
  console.log(JSON.stringify(out));
})();
JS;
    file_put_contents($work . '/check.js', $js);
    $raw = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && NODE_PATH=' . escapeshellarg($root)
                    . ' node ' . escapeshellarg($work . '/check.js') . ' ' . escapeshellarg($work) . ' 2>&1');
    $got = json_decode((string)$raw, true);

    if (!is_array($got)) {
        ok(false, 'chromium returned something readable', substr((string)$raw, 0, 400));
    } else {
        foreach ($got as $name => $r) {
            ok($r['errors'] === [], "$name has no javascript error", $r['errors']);
        }
        /* range 2 is one size, so the fill runs line by line down to zero */
        ok(($got['weight2']['wlines'] ?? 0) === 4, 'range 2 draws its four weight lines',
           $got['weight2']['wlines'] ?? null);
        ok(str_contains(implode(' ', $got['weight2']['balances'] ?? []), 'balanced'),
           'and the last line reads balanced', $got['weight2']['balances'] ?? null);
        ok(($got['weight2']['perunit'] ?? '') === '495 g', 'one unit comes to 495 g',
           $got['weight2']['perunit'] ?? null);
        /* range 1 is assorted, so the per-line fill is not claimed */
        ok(trim(implode('', $got['weight']['balances'] ?? [])) === '',
           'an assorted range shows no per-line balance, because it cannot know one',
           $got['weight']['balances'] ?? null);
        ok(($got['mix']['mixtot'] ?? '') === '1,000',
           'the assorted page totals 1,000', $got['mix']['mixtot'] ?? null);
        ok(str_contains($got['mix']['mixfoot'] ?? '', '10 per carton'),
           'from 10 per carton', $got['mix']['mixfoot'] ?? null);
        ok(str_contains($got['approve']['dev'] ?? '', 'same as calculated'),
           'approve opens with no deviation', $got['approve']['dev'] ?? null);
    }
}

/* ===================================== 8. the toggles, driven for real
   The whole point of this round is that nothing waits for a save. A
   markup check cannot tell a live toggle from a dead one — both have the
   radio — so these are clicked. */
head('8. Live toggles, live size switching, copy and apply-to-many');

if ($node === '' || !is_dir($root . '/playwright')) {
    echo "  (skipped — playwright not available)\n";
} else {
    $drive = <<<'JS'
const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const dir = process.argv[2];
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = { errors: [] };
  const open = async (f) => {
    const p = await b.newPage({ viewport: { width: 390, height: 844 } });
    p.on('pageerror', e => out.errors.push(f + ': ' + e));
    await p.goto('file://' + path.join(dir, f));
    await p.waitForTimeout(250);
    // Every step shown at once. Walking the steps is zsteps_test's job;
    // what is being driven here is what the toggles do, and a control on
    // a hidden step cannot be clicked.
    await p.evaluate(() => document.querySelectorAll('.mstep')
                             .forEach(s => s.classList.add('on')));
    await p.waitForTimeout(120);
    return p;
  };

  // ---------- the serial cards ----------
  let p = await open('serial.html');
  const cards = p.locator('form.mcard');
  const c2 = cards.nth(1);                       // Carton 101-225, per package, one size
  const read = async (c) => ({
    label:  await c.locator('[data-qtylabel]').innerText(),
    qty:    await c.locator('[data-qty]').inputValue(),
    serial: (await c.locator('[data-serial]').innerText()).replace(/\n/g, ' | '),
    total:  (await c.locator('[data-total]').innerText()).replace(/\n/g, ' | '),
    oneVisible: await c.locator('[data-one]').first().isVisible(),
    mixVisible: await c.locator('[data-mix]').isVisible(),
  });

  out.c2start = await read(c2);
  // flip to Direct qty
  // tap the label, which is what a thumb hits — the radio itself is
  // deliberately invisible inside the segmented control.
  await c2.locator('.seg span', { hasText: 'Direct qty' }).click();
  await p.waitForTimeout(150);
  out.c2direct = await read(c2);
  // and back
  await c2.locator('.seg span', { hasText: 'Per package' }).click();
  await p.waitForTimeout(150);
  out.c2back = await read(c2);
  // widen the serial by one carton
  await c2.locator('[name="serial_to"]').fill('226');
  await p.waitForTimeout(150);
  out.c2wider = await read(c2);
  await c2.locator('[name="serial_to"]').fill('225');
  await p.waitForTimeout(120);
  // flip to Assorted
  await c2.locator('.seg span', { hasText: 'Assorted' }).click();
  await p.waitForTimeout(150);
  out.c2mix = await read(c2);

  // card 1 arrives already assorted
  out.c1start = await read(cards.nth(0));
  await p.close();

  // ---------- the weight screen, three sizes ----------
  p = await open('weight.html');
  const chipTexts = async () => (await p.locator('#szchips .chip').allInnerTexts()).map(t => t.trim());
  out.chips = await chipTexts();
  out.perUnitSmall = await p.locator('#perunit').innerText();
  out.linesSmall   = await p.locator('#wlines .wrow').count();

  await p.locator('#szchips .chip', { hasText: 'Medium' }).click();
  await p.waitForTimeout(200);
  out.perUnitMedium = await p.locator('#perunit').innerText();
  out.headMedium    = await p.locator('#wsizehead').innerText();
  out.stillSamePage = !p.url().includes('?');        // no navigation happened

  // copy Small's breakdown into Medium
  await p.locator('#copybtn').click(); await p.waitForTimeout(120);
  await p.locator('#copyfrom').selectOption('Small'); await p.waitForTimeout(220);
  out.perUnitAfterCopy = await p.locator('#perunit').innerText();

  // tick Large and apply this breakdown to it
  await p.locator('#applychips .chip', { hasText: 'Large' }).click(); await p.waitForTimeout(120);
  await p.locator('#applybtn').click(); await p.waitForTimeout(220);
  out.applyMsg = await p.locator('#applymsg').innerText();
  await p.locator('#szchips .chip', { hasText: 'Large' }).click(); await p.waitForTimeout(200);
  out.perUnitLarge = await p.locator('#perunit').innerText();
  out.totalsAfter  = (await p.locator('#pkgtotals').innerText()).replace(/\n/g, ' | ');

  // applying with nothing ticked must say so rather than do nothing
  await p.locator('#applybtn').click(); await p.waitForTimeout(150);
  out.applyNone = await p.locator('#applymsg').innerText();

  // what the form would post
  out.posted = await p.evaluate(() => {
    const f = document.getElementById('wform');
    f.dispatchEvent(new Event('submit', { cancelable: true }));
    return document.getElementById('wjson').value;
  });
  await p.close();

  // ---------- the remembered breakdown, filled without a round trip ----------
  p = await open('weight3.html');
  out.stdVisible = await p.locator('#stdbtn').isVisible();
  out.beforeStd  = await p.locator('#wlines .wrow').count();
  await p.locator('#stdbtn').click(); await p.waitForTimeout(200);
  out.afterStd   = await p.locator('#wlines .wrow').count();
  out.stdNoNav   = !p.url().includes('?');
  await p.close();

  await b.close();
  console.log(JSON.stringify(out));
})();
JS;
    file_put_contents($work . '/drive.js', $drive);
    $raw = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && NODE_PATH=' . escapeshellarg($root)
                    . ' node ' . escapeshellarg($work . '/drive.js') . ' ' . escapeshellarg($work) . ' 2>&1');
    $d = json_decode((string)$raw, true);

    if (!is_array($d)) {
        ok(false, 'chromium drove the page', substr((string)$raw, 0, 700));
    } else {
        ok(($d['errors'] ?? null) === [], 'no javascript error while driving', $d['errors'] ?? null);

        /* --- the quantity toggle --- */
        /* innerText gives what is on screen, and the label style is
           text-transform:uppercase — so compare without case. */
        ok(strcasecmp((string)($d['c2start']['label'] ?? ''), 'Quantity per carton') === 0,
           'per package: the label names the package', $d['c2start'] ?? null);
        ok(str_contains((string)($d['c2start']['total'] ?? ''), '40 per carton × 125')
           && str_contains((string)($d['c2start']['total'] ?? ''), '5,000'),
           'and the total is worked out on the spot', $d['c2start']['total'] ?? null);

        ok(strcasecmp((string)($d['c2direct']['label'] ?? ''), 'Total quantity') === 0,
           'direct: the label changes the instant it is tapped', $d['c2direct'] ?? null);
        ok(($d['c2direct']['qty'] ?? '') === '5000',
           'and the figure is converted, not left meaning something else',
           $d['c2direct']['qty'] ?? null);
        ok(str_contains((string)($d['c2direct']['total'] ?? ''), 'over 125 carton'),
           'the total line reads the other way round now', $d['c2direct']['total'] ?? null);
        ok(($d['c2back']['qty'] ?? '') === '40',
           'flipping back gives 40 a carton again, not 5,000', $d['c2back']['qty'] ?? null);

        /* --- the serial, live --- */
        ok(str_contains((string)($d['c2wider']['serial'] ?? ''), '126'),
           'one more carton on the serial re-counts at once', $d['c2wider']['serial'] ?? null);
        ok(str_contains((string)($d['c2wider']['total'] ?? ''), '5,040'),
           'and the total follows it', $d['c2wider']['total'] ?? null);

        /* --- the size toggle --- */
        ok(($d['c2start']['oneVisible'] ?? null) === true
           && ($d['c2start']['mixVisible'] ?? null) === false,
           'one size: the size and quantity are on screen', $d['c2start'] ?? null);
        ok(($d['c2mix']['oneVisible'] ?? null) === false
           && ($d['c2mix']['mixVisible'] ?? null) === true,
           'assorted: they vanish and the set-up link appears, with no save',
           $d['c2mix'] ?? null);
        ok(($d['c1start']['mixVisible'] ?? null) === true
           && ($d['c1start']['oneVisible'] ?? null) === false,
           'a range saved as assorted opens that way', $d['c1start'] ?? null);

        /* --- switching size on the weight screen --- */
        ok(($d['chips'] ?? []) === ['Small ✓', 'Medium ✓', 'Large ✓'],
           'a chip per size, ticked where the weights are in', $d['chips'] ?? null);
        ok(($d['perUnitSmall'] ?? '') === '850 g', 'Small comes to 850 g', $d['perUnitSmall'] ?? null);
        ok(($d['perUnitMedium'] ?? '') === '1,030 g',
           'tapping Medium shows 1,030 g — no page load', $d['perUnitMedium'] ?? null);
        ok(str_contains((string)($d['headMedium'] ?? ''), 'Medium'),
           'and the heading follows', $d['headMedium'] ?? null);
        ok(($d['stillSamePage'] ?? null) === true, 'nothing navigated');

        /* --- copy, and apply to many --- */
        ok(($d['perUnitAfterCopy'] ?? '') === '850 g',
           'Copy from Small puts 850 g into Medium', $d['perUnitAfterCopy'] ?? null);
        ok(str_contains((string)($d['applyMsg'] ?? ''), 'Copied to Large')
           && str_contains((string)($d['applyMsg'] ?? ''), 'until you press Save'),
           'applying to a ticked size says what it did, and what it did not',
           $d['applyMsg'] ?? null);
        ok(($d['perUnitLarge'] ?? '') === '850 g',
           'and Large really has it', $d['perUnitLarge'] ?? null);
        ok(str_contains((string)($d['applyNone'] ?? ''), 'Tick the sizes'),
           'applying with nothing ticked says so instead of doing nothing quietly',
           $d['applyNone'] ?? null);

        /* 850 g in all three now: 850x2 + 850x4 + 850x4 = 8.500 kg */
        ok(str_contains((string)($d['totalsAfter'] ?? ''), '8.500 kg'),
           'the package total follows every copy', $d['totalsAfter'] ?? null);

        /* --- what would actually be posted --- */
        $posted = json_decode((string)($d['posted'] ?? ''), true);
        ok(is_array($posted) && count($posted) === 3,
           'one field carries all three sizes on submit', array_keys((array)$posted));
        ok(is_array($posted) && array_sum(array_column($posted['Large'] ?? [], 'g')) === 850,
           'with the copied figures in it', $posted['Large'] ?? null);

        /* --- the remembered breakdown --- */
        ok(($d['stdVisible'] ?? null) === true, 'the last saved breakdown is offered');
        ok((int)($d['beforeStd'] ?? -1) === 4 && (int)($d['afterStd'] ?? 0) === 2,
           'and one tap replaces the lines with it',
           [$d['beforeStd'] ?? null, $d['afterStd'] ?? null]);
        ok(($d['stdNoNav'] ?? null) === true, 'without a round trip');
    }
}

echo "\n" . ($F ? "FAILED  $F" : 'ALL PASS') . "   ($P checks)\n";
exit($F ? 1 : 0);
