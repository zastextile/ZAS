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
 * SMART PACKING (afnan's demo) replaced the serial and approve tabs with
 * one page drawn in the browser; zsmartpack_test.php drives that against a
 * real database. What is left here is the weight screen, which is kept and
 * linked from Summary until the weight module exists, the desktop screen,
 * and the state the new page is drawn from — read off this fixture.
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
copy($B . 'pack_palette.php',      $work . '/pack_palette.php');
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
/* BREAKTOKEN: the packing screen reads its token before printing
   anything; this proves a throw there gives a card, not a blank page */
function csrf_token(){ if (getenv('BREAKTOKEN')) throw new Error('Column not found: test breakage'); return 'testtoken'; }
/* BREAKCSRF=N throws on the Nth form drawn. Nearly every database read in
   packing.php swallows its own errors, so a throw from the fake database
   never reaches a card; this one does, from the middle of a form, which
   is the worst place for it. Each existing range draws two forms (save,
   remove), so N=3 is the first form of the second range. */
function csrf_field(){
  static $n = 0; $n++;
  if ((int)getenv('BREAKCSRF') === $n) throw new Error('Column not found: test breakage');
  return '<input type="hidden" name="_csrf" value="testtoken">';
}
function current_user(): ?array { return ['id'=>1,'name'=>'Afnan','email'=>'a@z','role'=>'admin','can_see_rates'=>1]; }
/* The role is switchable, because "read-only" is a question about the
   role as much as the status: an admin MAY still correct a completed
   packing list, and a run that is always admin can never see the
   read-only screen at all. Admin stays the default. */
$GLOBALS['ROLE'] = getenv('ROLE') ?: 'admin';
function is_admin(){ return $GLOBALS['ROLE'] === 'admin'; }
function is_colleague(){ return $GLOBALS['ROLE'] === 'colleague'; }
function is_staff(){ return $GLOBALS['ROLE'] === 'staff'; }
function is_production_staff(){ return $GLOBALS['ROLE'] === 'production_staff'; }
function assigned_shipment_ids(){ return ['ALL']; }
function redirect($u){ echo "\n__REDIRECT__ $u || " . ($_SESSION['error'] ?? $_SESSION['flash'] ?? '') . "\n";
  /* what a refused save kept for the New range card, so a test can see it */
  if (!empty($_SESSION['pack_draft'])) echo "__DRAFT__ " . json_encode($_SESSION['pack_draft']) . "\n";
  exit; }
function audit_log(){}
/* the desktop chrome, which the office screen sits inside */
function page_header($t=''){ echo "<!doctype html><html><head><title>" . e($t) . "</title></head><body>"; }
function page_footer(){ echo "</body></html>"; }
function flash(){ foreach (['flash','error'] as $k) {
  if (!empty($_SESSION[$k])) { echo '<div class="flash">' . e($_SESSION[$k]) . '</div>'; unset($_SESSION[$k]); } } }

/* ---- the rows the live tables would give back ---------------------- */
$GLOBALS['ROWS'] = [
  'shipment' => ['id'=>1,'invoice_no'=>'ZAS/5191','buyer_name'=>'Gulf Textiles LLC',
                 'status'=>'draft','packing_status'=>'open'],
  'items' => [
    ['id'=>11,'line_no'=>1,'product_name'=>'Duvet Cover Set King','des_col'=>'Fleece + micro, printed','optional_value'=>'HS 6302.31','qty'=>900],
    ['id'=>12,'line_no'=>2,'product_name'=>'Hotel Flat Sheet 300TC','des_col'=>'Percale, white','optional_value'=>'HS 6302.21','qty'=>5200],
    ['id'=>13,'line_no'=>3,'product_name'=>'Bath Towel 500GSM','des_col'=>'Combed, dobby','optional_value'=>'HS 6302.60','qty'=>3800],
  ],
  /* What the office typed for line 11: three sizes and two colours.
     Line 12 is deliberately left unset, so the fallback to the product's
     own list is exercised too. */
  'palette' => [
    '1|11' => [
      ['kind'=>'size','label'=>'Small'], ['kind'=>'size','label'=>'Medium'],
      ['kind'=>'size','label'=>'Large'],
      ['kind'=>'colour','label'=>'White'], ['kind'=>'colour','label'=>'Navy'],
    ],
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
  /* TWO RANGES OF ONE SIZE IN TWO COLOURS, each its own invoice line
     (14 and 15 — the weight is the line's now, so sharing line 11 would
     make them one weight with range 101), and the only difference
     between them is the switch.

     104 says the colours weigh differently, so it is two things to
     weigh, kept apart. 105 says they do not, so it is ONE thing —
     asked once — and the ten in the carton are ten Singles, not six
     and four. That is the rule he asked for, in a fixture. */
  'cgroups' => [
    ['id'=>104,'shipment_id'=>1,'line_no'=>4,'invoice_item_id'=>14,'product_name'=>'Duvet Cover Set King',
     'des_col'=>'Fleece + micro, printed','optional_value'=>'HS 6302.31','unit_title'=>'Carton',
     'serial_from'=>300,'serial_to'=>310,'packages'=>0,'qty_mode'=>'per','qty_per_pkg'=>10,'total_qty'=>110,
     'assorted'=>1,'size_label'=>'','pkg_gross'=>10.400,'pkg_tare'=>1.000,'weight_by_colour'=>1],
    ['id'=>105,'shipment_id'=>1,'line_no'=>5,'invoice_item_id'=>15,'product_name'=>'Duvet Cover Set King',
     'des_col'=>'Fleece + micro, printed','optional_value'=>'HS 6302.31','unit_title'=>'Carton',
     'serial_from'=>400,'serial_to'=>410,'packages'=>0,'qty_mode'=>'per','qty_per_pkg'=>10,'total_qty'=>110,
     'assorted'=>1,'size_label'=>'','pkg_gross'=>10.400,'pkg_tare'=>1.000,'weight_by_colour'=>0],
  ],
  'sizes' => [
    104 => [['id'=>6,'group_id'=>104,'size_label'=>'Single','colour_label'=>'White','qty_per_pkg'=>6,'total_qty'=>66],
            ['id'=>7,'group_id'=>104,'size_label'=>'Single','colour_label'=>'Navy','qty_per_pkg'=>4,'total_qty'=>44]],
    105 => [['id'=>8,'group_id'=>105,'size_label'=>'Single','colour_label'=>'White','qty_per_pkg'=>6,'total_qty'=>66],
            ['id'=>9,'group_id'=>105,'size_label'=>'Single','colour_label'=>'Navy','qty_per_pkg'=>4,'total_qty'=>44]],
    101 => [['id'=>1,'group_id'=>101,'size_label'=>'Small','colour_label'=>'','qty_per_pkg'=>2,'total_qty'=>200],
            ['id'=>2,'group_id'=>101,'size_label'=>'Medium','colour_label'=>'','qty_per_pkg'=>4,'total_qty'=>400],
            ['id'=>3,'group_id'=>101,'size_label'=>'Large','colour_label'=>'','qty_per_pkg'=>4,'total_qty'=>400]],
    102 => [['id'=>4,'group_id'=>102,'size_label'=>'152x200','colour_label'=>'','qty_per_pkg'=>40,'total_qty'=>5000]],
    103 => [['id'=>5,'group_id'=>103,'size_label'=>'Single','colour_label'=>'','qty_per_pkg'=>200,'total_qty'=>4000]],
  ],
  /* 2x850 + 4x1030 + 4x1150 = 10 420 g, against 11.420 - 1.000 = 10.420 kg */
  'wlines' => [
    /* 104: filed per colour. 6 x 900 + 4 x 1000 = 9 400 g, against
       10.400 - 1.000 = 9.400 kg. */
    '104|Single|White' => [['w_type'=>'Fabric','w_name'=>'Fleece','grams'=>840],
                           ['w_type'=>'Accessories','w_name'=>'Label','grams'=>60]],
    '104|Single|Navy'  => [['w_type'=>'Fabric','w_name'=>'Navy heavy','grams'=>940],
                           ['w_type'=>'Accessories','w_name'=>'Label','grams'=>60]],
    /* 105: filed once, under the size and no colour. 10 x 940 = 9 400 g,
       the same carton weight by a different route. */
    '105|Single|'      => [['w_type'=>'Fabric','w_name'=>'Fleece','grams'=>880],
                           ['w_type'=>'Accessories','w_name'=>'Label','grams'=>60]],
    '101|Small|'   => [['w_type'=>'Fabric','w_name'=>'Fleece 220gsm','grams'=>400],
                      ['w_type'=>'Fabric','w_name'=>'Micro peach','grams'=>160],
                      ['w_type'=>'Fiber','w_name'=>'Polyester','grams'=>90],
                      ['w_type'=>'PVC','w_name'=>'','grams'=>80],
                      ['w_type'=>'Cardboard','w_name'=>'Board','grams'=>60],
                      ['w_type'=>'Accessories','w_name'=>'Label','grams'=>60]],
    '101|Medium|'  => [['w_type'=>'Fabric','w_name'=>'Fleece 220gsm','grams'=>500],
                      ['w_type'=>'Fabric','w_name'=>'Micro peach','grams'=>190],
                      ['w_type'=>'Fiber','w_name'=>'Polyester','grams'=>110],
                      ['w_type'=>'PVC','w_name'=>'','grams'=>90],
                      ['w_type'=>'Cardboard','w_name'=>'Board','grams'=>70],
                      ['w_type'=>'Accessories','w_name'=>'Label','grams'=>70]],
    '101|Large|'   => [['w_type'=>'Fabric','w_name'=>'Fleece 220gsm','grams'=>560],
                      ['w_type'=>'Fabric','w_name'=>'Micro peach','grams'=>210],
                      ['w_type'=>'Fiber','w_name'=>'Polyester','grams'=>130],
                      ['w_type'=>'PVC','w_name'=>'','grams'=>100],
                      ['w_type'=>'Cardboard','w_name'=>'Board','grams'=>75],
                      ['w_type'=>'Accessories','w_name'=>'Label','grams'=>75]],
    /* 495 g x 40 = 19.800 kg, against 21.000 - 1.200 */
    '102|152x200|' => [['w_type'=>'Fabric','w_name'=>'Percale 300TC','grams'=>420],
                      ['w_type'=>'PVC','w_name'=>'','grams'=>30],
                      ['w_type'=>'Cardboard','w_name'=>'Insert','grams'=>40],
                      ['w_type'=>'Accessories','w_name'=>'Label','grams'=>5]],
    /* 450 g x 200 = 90.000 kg, against 92.000 - 2.000 */
    '103|Single|'  => [['w_type'=>'Fabric','w_name'=>'Terry 500gsm','grams'=>400],
                      ['w_type'=>'PVC','w_name'=>'','grams'=>20],
                      ['w_type'=>'Cardboard','w_name'=>'Band','grams'=>25],
                      ['w_type'=>'Accessories','w_name'=>'Label','grams'=>5]],
  ],
  /* something remembered for one product and size, and nothing for the rest */
  'std' => [
    /* Navy has been standardised on some earlier order; White has not.
       Without that difference the remembered breakdown is empty for both
       colours and reading it by the bare size changes nothing anybody
       can see. */
    'duvet cover set king|Single|Navy' => [
      ['w_type'=>'Fabric','w_name'=>'Navy heavy 2025','grams'=>930],
      ['w_type'=>'Accessories','w_name'=>'Label','grams'=>60]],
    'bath towel 500 gsm|Single|' => [
      ['w_type'=>'Fabric','w_name'=>'Terry 500gsm','grams'=>400],
      ['w_type'=>'PVC','w_name'=>'','grams'=>20]]],
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
        if (str_contains($q, 'FROM shipments WHERE id=?')) {
            /* so one run can close the list and see the screen go
               read-only, without a second copy of this whole fixture */
            $sh = $R['shipment'];
            if (getenv('PACKSTATUS')) $sh['packing_status'] = (string)getenv('PACKSTATUS');
            return [$sh];
        }
        if (str_contains($q, 'FROM shipment_items'))        return $R['items'];
        if (str_contains($q, 'FROM packing_groups WHERE shipment_id=?')) {
            /* The colour ranges are opt-in. Adding them to every run
               would change the serial page's card count and the approve
               totals, and then a dozen assertions would be about this
               fixture rather than about the app. */
            /* CLASHDATA: an old overlap already sitting in the data, the
               way one saved before the rule was tight would. */
            /* EXTRA2: Bale 400–410 (the highest number on the shipment),
               then Carton 300–310 saved LAST. The next Carton is 311; the
               highest number of any kind would wrongly give 411. */
            if (getenv('EXTRA2')) {
                $x = $R['groups'];
                $x[] = ['id'=>109,'shipment_id'=>1,'line_no'=>9,'invoice_item_id'=>13,'product_name'=>'Bath Towel 500GSM',
                        'des_col'=>'','optional_value'=>'','unit_title'=>'Bale','serial_from'=>400,'serial_to'=>410,
                        'packages'=>0,'qty_mode'=>'per','qty_per_pkg'=>1,'total_qty'=>11,'assorted'=>0,
                        'size_label'=>'Single','pkg_gross'=>0,'pkg_tare'=>0];
                $x[] = ['id'=>110,'shipment_id'=>1,'line_no'=>10,'invoice_item_id'=>12,'product_name'=>'Hotel Flat Sheet 300TC',
                        'des_col'=>'','optional_value'=>'','unit_title'=>'Carton','serial_from'=>300,'serial_to'=>310,
                        'packages'=>0,'qty_mode'=>'per','qty_per_pkg'=>1,'total_qty'=>11,'assorted'=>0,
                        'size_label'=>'152x200','pkg_gross'=>0,'pkg_tare'=>0];
                return $x;
            }
            /* SHARELINE: range 102 is a second range of invoice line 11,
               so one line has two ranges and one weight */
            if (getenv('SHARELINE')) {
                $g2 = $R['groups']; $g2[1]['invoice_item_id'] = 11; return $g2;
            }
            if (getenv('CLASHDATA')) {
                $g2 = $R['groups']; $g2[1]['serial_from'] = 90; return $g2;
            }
            return getenv('COLOURRANGES') ? array_merge($R['groups'], $R['cgroups']) : $R['groups'];
        }
        if (str_contains($q, 'FROM packing_groups WHERE id=?')) {
            foreach ($R['groups'] as $g) if ((int)$g['id'] === (int)($a[0] ?? 0)) {
                if (getenv('SHARELINE') && (int)$g['id'] === 102) $g['invoice_item_id'] = 11;
                return [$g];
            }
            return [];
        }
        if (str_contains($q, 'FROM packing_group_sizes WHERE group_id=?')) {
            return $R['sizes'][(int)($a[0] ?? 0)] ?? [];
        }
        if (str_contains($q, 'SELECT DISTINCT size_label FROM packing_group_sizes'))
            return [['size_label' => 'Small'], ['size_label' => 'Medium'],
                    ['size_label' => 'Large'], ['size_label' => '152x200'], ['size_label' => 'Single']];
        if (str_contains($q, 'COALESCE(SUM(grams),0) g')) {
            $out = [];
            foreach ($R['wlines'] as $k => $lines) {
                [$gid, $size, $col] = array_pad(explode('|', $k), 3, '');
                if ((int)$gid !== (int)($a[0] ?? 0)) continue;
                $out[] = ['size_label' => $size, 'colour_label' => $col,
                          'g' => array_sum(array_column($lines, 'grams'))];
            }
            return $out;
        }
        /* THREE PARTS, because the real query names colour_label. A
           two-part key answered a colour-keyed read with the size's
           rows, so every colour looked already weighed and the whole
           per-colour path could not fail.

           BRACES. This if had none, so adding the guard below made it
           the whole body and the return ran for every query — every
           route after this one was dead and a dozen assertions failed
           somewhere else entirely. */
        if (str_contains($q, 'FROM packing_weight_lines WHERE group_id=? AND size_label=?')) {
            if (!str_contains($q, 'colour_label=?')) return [];
            return $R['wlines'][((int)($a[0] ?? 0)) . '|' . ($a[1] ?? '') . '|' . ($a[2] ?? '')] ?? [];
        }
        /* The order's own sizes and colours. Item 11 is set up by the
           office; item 12 is deliberately not, so the fallback to the
           product's own list is exercised too. */
        if (str_contains($q, 'FROM packing_palette')) {
            $rows = $R['palette'][((int)($a[0] ?? 0)) . '|' . ((int)($a[1] ?? 0))] ?? [];
            if (isset($a[2])) {
                $out = [];
                foreach ($rows as $r) if ($r['kind'] === $a[2]) $out[] = ['label' => $r['label']];
                return $out;
            }
            return $rows;
        }
        if (str_contains($q, 'FROM packing_weight_std')) {
            if (!str_contains($q, 'colour_label=?')) return [];
            return $R['std'][($a[0] ?? '') . '|' . ($a[1] ?? '') . '|' . ($a[2] ?? '')] ?? [];
        }
        if (str_contains($q, 'FROM product_sizes ps JOIN products'))
            return [['size_label' => 'King'], ['size_label' => 'Queen']];
        /* WHAT THIS PRODUCT HAS BEEN PACKED IN BEFORE, which is not the
           same as what this order uses. Without a colour here that the
           order did NOT choose, "offered" and "ticked" look identical on
           the screen and a mutant that ticks everything survives. */
        if (str_contains($q, 'FROM packing_group_sizes gs JOIN packing_groups g'))
            return str_contains($q, 'gs.colour_label v')
                 ? [['v' => 'Grey'], ['v' => 'Navy'], ['v' => 'White']]
                 : [['v' => 'Large'], ['v' => 'Medium'], ['v' => 'Small']];
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
/* A real POST through the page's own handler — the GET renders above
   cannot see where a save sends you, and that is half of "don't make me
   press Next again and again". */
function post_to(string $work, array $get, array $post, string $env = ''): string {
    $cmd = 'cd ' . escapeshellarg($work) . ' && ' . $env . ' QS=' . escapeshellarg(http_build_query($get))
         . ' PQ=' . escapeshellarg(http_build_query($post)) . ' '
         . escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=E_ALL'
         . ' -r ' . escapeshellarg('$_GET=[];parse_str((string)getenv("QS"),$_GET);$_POST=[];'
                . 'parse_str((string)getenv("PQ"),$_POST);$_SERVER["REQUEST_METHOD"]="POST";require "m_pack.php";')
         . ' 2>&1';
    return (string)shell_exec($cmd);
}

function render(string $work, array $get, string $page = 'm_pack.php',
                string $env = ''): array {
    $php = PHP_BINARY;
    $q = http_build_query($get);
    /* display_errors on, so a notice or a warning shows up in the output
       and is caught below instead of being swallowed. */
    /* The env assignment goes AFTER the &&, or it applies to cd and the
       page is run without its query string — which looks exactly like a
       page that renders nothing. */
    $cmd = 'cd ' . escapeshellarg($work) . ' && ' . $env . ' QS=' . escapeshellarg($q) . ' '
         . escapeshellarg($php) . ' -d display_errors=1 -d error_reporting=E_ALL'
         . ' -r ' . escapeshellarg('$_GET=[];parse_str((string)getenv("QS"),$_GET);$_SERVER["REQUEST_METHOD"]="GET";require ' . var_export($page, true) . ';')
         . ' 2>&1';
    $out = shell_exec($cmd);
    return [(string)$out, $q];
}

head('1. Every tab renders, with nothing complaining');

$pages = [
    'list'    => [],
    'serial'  => ['id' => 1],
    'weight'  => ['id' => 1, 't' => 'weight'],
    'weight2' => ['id' => 1, 't' => 'weight', 'g' => 102],
    'weight3' => ['id' => 1, 't' => 'weight', 'g' => 103],
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

/* The two colour ranges, rendered here rather than further down so the
   browser section below loads them too — their chips are drawn by
   script, and a markup check cannot tell a chip that shows "Navy
   Single" from one that shows the raw key "Single|Navy". */
[$w4] = render($work, ['id' => 1, 't' => 'weight', 'g' => 104], 'm_pack.php', 'COLOURRANGES=1');
[$w5] = render($work, ['id' => 1, 't' => 'weight', 'g' => 105], 'm_pack.php', 'COLOURRANGES=1');
file_put_contents($work . '/weight4.html', $w4);
file_put_contents($work . '/weight5.html', $w5);

head('2. The packing screen is drawn from the saved ranges');

/* The new screen is drawn in the browser from one embedded state. What
   that state says about this fixture is checked here; what the screen
   then does with it is driven in zsmartpack_test.php. */
$ser = $html['serial'];
$st2 = preg_match('~var STATE = (\{.*?\});\nvar CAN_EDIT~s', $ser, $sm2) ? json_decode($sm2[1], true) : null;
ok(is_array($st2), 'the state is embedded in the page', substr($ser, 0, 200));
$rec2 = [];
foreach ($st2['records'] ?? [] as $r) $rec2[$r['id']] = $r;
ok(array_keys($rec2) === [101, 102, 103], 'every saved range is in it', array_keys($rec2));
/* the stored packages column says 999, 7 and 3 — the serial wins */
ok(($rec2[101]['from'] ?? 0) === 1 && ($rec2[101]['to'] ?? 0) === 100
   && ($rec2[102]['from'] ?? 0) === 101 && ($rec2[102]['to'] ?? 0) === 225
   && ($rec2[103]['from'] ?? 0) === 226 && ($rec2[103]['to'] ?? 0) === 245,
   'with the serials, which is what the package count comes from — not the stored column');
ok(($rec2[101]['target'] ?? 0) == 10 && ($rec2[102]['target'] ?? 0) == 40 && ($rec2[103]['target'] ?? 0) == 200,
   'pieces per package: 10, 40, and 200 for the range typed as a direct 4,000 over 20 bales',
   [$rec2[101]['target'] ?? null, $rec2[102]['target'] ?? null, $rec2[103]['target'] ?? null]);
ok(array_map(fn($c) => $c['size'] . ' ' . $c['qty'], $rec2[101]['combos'] ?? []) === ['Small 2', 'Medium 4', 'Large 4'],
   'the assorted range carries its 2 Small, 4 Medium, 4 Large', $rec2[101]['combos'] ?? null);
ok(($rec2[103]['type'] ?? '') === 'Bale', 'and each range its kind of package');
/* weight is a module of its own now; the packing screen says nothing about it */
ok(!array_key_exists('weighed', $rec2[101] ?? ['weighed' => 1]), 'the packing state carries no weight');
ok(!preg_match('~<input[^>]*name="packages"~', $ser), 'nothing on the page types a package count');
ok(!preg_match('~fix(ed)?\s*qty~i', $ser), 'no fixed-quantity wording anywhere');

head('5. Weight — every size comes down with the page');

$w1 = $html['weight'];
/* GROSS IS WORKED OUT, NOT TYPED. 2x850 + 4x1030 + 4x1150 = 10.420 kg
   of contents, plus the 1.000 kg carton — no scale figure asked. */
ok(str_contains($w1, '10.420 contents + 1.000 package'),
   'one package is its contents by the breakdown plus the empty carton');
ok(str_contains($w1, '11.420 kg each'), 'so 11.420 kg gross each, worked out');
ok(!str_contains($w1, 'name="pkg_gross"'), 'and no gross is typed per range any more');
ok(str_contains($w1, 'name="tare[Carton]"'),
   'the empty carton is asked once for the line');
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
ok(str_contains($w2, '19.800 contents'), 'range 2: 495 g x 40 is 19.800 kg');
ok(str_contains($w2, 'Percale 300TC'), 'its lines come back with their names');
ok(preg_match('~var SIZES\s*=\s*\["152x200"\]~', $w2) === 1,
   'a single-size range carries one size', null);

$w3 = $html['weight3'];
ok(str_contains($w3, '90.000 contents'), 'range 3: 450 g x 200 is 90.000 kg');
ok(preg_match('~var STD\s*=\s*\{"Single":~', $w3) === 1,
   'the remembered breakdown comes down for the product that has one', null);
ok(preg_match('~var STD\s*=\s*\{\}~', $w2) === 1,
   'and not for a product that has none', null);

head('6. Finish packing adds the three ranges up');

/* Finish packing writes the totals pack_totals() works out — the same
   figures the old Approve tab showed. Read straight from it. */
file_put_contents($work . '/totals.php', "<?php require 'includes/bootstrap.php'; require 'includes/packing.php';"
    . " echo json_encode(pack_totals(1));");
$t6 = json_decode((string)shell_exec('cd ' . escapeshellarg($work) . ' && ' . escapeshellarg(PHP_BINARY)
    . ' totals.php 2>&1'), true);
/* 245 packages; 1,000 + 5,000 + 4,000 = 10,000
   net  = 10.420x100 + 19.800x125 + 90.000x20 = 1042 + 2475 + 1800 = 5,317.000
   gross= net + 1.000x100 + 1.200x125 + 2.000x20 = 5317 + 100 + 150 + 40 = 5,607.000 */
ok((int)($t6['packages'] ?? 0) === 245, 'total packages 245', $t6['packages'] ?? $t6);
ok(abs((float)($t6['qty'] ?? 0) - 10000) < 0.001, 'total quantity 10,000', $t6['qty'] ?? null);
ok(abs((float)($t6['net'] ?? 0) - 5317) < 0.001, 'net 5,317.000 kg', $t6['net'] ?? null);
ok(abs((float)($t6['gross'] ?? 0) - 5607) < 0.001, 'gross 5,607.000 kg', $t6['gross'] ?? null);
ok(($t6['unfinished'] ?? ['?']) === [], 'nothing is flagged unfinished', $t6['unfinished'] ?? null);
ok(str_contains($ser, 'It is not compared with the invoice'), 'and the screen says the invoice is not the judge');

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
    r.dev      = await p.locator('#dev').count() ? (await p.locator('#dev').innerText()).replace(/\n/g, ' | ') : '';
    /* The chips a packer actually taps. Drawn by script, so the key and
       the name are indistinguishable in the markup. */
    r.chips    = await p.locator('#szchips .chip').allInnerTexts();
    r.whead    = await p.locator('#wsizehead').count() ? await p.locator('#wsizehead').innerText() : '';
    r.wsub     = await p.locator('#wsizesub').count() ? await p.locator('#wsizesub').innerText() : '';
    r.applies  = await p.locator('#applychips .chip').allInnerTexts();
    r.copyopts = await p.locator('#copyfrom option').allInnerTexts();
    /* COPYING, ACTUALLY DONE. The <option> text is the name and its value
       is the key; if the value were the name too, the copy would look
       right and quietly find nothing. Only doing it shows that. */
    if ((await p.locator('#copyfrom option').count()) > 1) {
      // The controls sit on a later step, and a control on a hidden step
      // cannot be clicked. Reveal them, then use them for real.
      await p.evaluate(() => document.querySelectorAll('.mstep')
                               .forEach(s => s.classList.add('on')));
      r.beforeCopy = await p.locator('#perunit').innerText();
      await p.locator('#copybtn').click();
      await p.locator('#copyfrom').selectOption({ index: 1 });
      await p.waitForTimeout(150);
      r.afterCopy = await p.locator('#perunit').innerText();
    }
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
        ok(($got['weight2']['wlines'] ?? 0) === 4, 'range 2 draws its four weight lines',
           $got['weight2']['wlines'] ?? null);
        /* There is no scale figure per range to run down to any more, so
           no line claims to "balance" against one. */
        ok(trim(implode('', $got['weight2']['balances'] ?? [])) === '',
           'no line claims a balance against a scale figure that is no longer asked',
           $got['weight2']['balances'] ?? null);
        ok(($got['weight2']['perunit'] ?? '') === '495 g', 'one unit comes to 495 g',
           $got['weight2']['perunit'] ?? null);
        /* range 1 is assorted, so the per-line fill is not claimed */
        ok(trim(implode('', $got['weight']['balances'] ?? [])) === '',
           'an assorted range shows no per-line balance, because it cannot know one',
           $got['weight']['balances'] ?? null);
        /* ---- the chips on a range whose colours weigh differently.
           These are drawn by script, so the markup cannot tell the key
           "Single|Navy" from the name "Navy Single" — only a browser can. */
        ok(($got['weight4']['chips'] ?? []) === ['White Single ✓', 'Navy Single ✓'],
           'the chips a packer taps read as a colour and a size, both already weighed',
           $got['weight4']['chips'] ?? null);
        ok(($got['weight4']['applies'] ?? []) === ['Navy Single'],
           'and the copy-to list names the other one the same way',
           $got['weight4']['applies'] ?? null);
        ok(in_array('Navy Single', (array)($got['weight4']['copyopts'] ?? []), true),
           'as does the copy-from list', $got['weight4']['copyopts'] ?? null);
        ok(($got['weight4']['whead'] ?? '') === 'One unit of White Single',
           'the heading over the lines names the colour and the size, not the key',
           $got['weight4']['whead'] ?? null);
        ok(($got['weight4']['wsub'] ?? '') === '6 of these in each package · used in 1 range of this line',
           'and says how many of them are in the carton', $got['weight4']['wsub'] ?? null);
        ok(($got['weight5']['wsub'] ?? '') === '10 of these in each package · used in 1 range of this line',
           'which on the range whose colours match is ten, not six',
           $got['weight5']['wsub'] ?? null);
        ok(($got['weight4']['beforeCopy'] ?? '') === '900 g'
           && ($got['weight4']['afterCopy'] ?? '') === '1,000 g',
           'copying the other colour\'s breakdown really copies it',
           [$got['weight4']['beforeCopy'] ?? null, $got['weight4']['afterCopy'] ?? null]);
        ok(($got['weight5']['chips'] ?? []) === [],
           'a range with one thing to weigh draws no chips at all',
           $got['weight5']['chips'] ?? null);

    }
}

/* ===================================== 8. the toggles, driven for real
   The whole point of this round is that nothing waits for a save. A
   markup check cannot tell a live toggle from a dead one — both have the
   radio — so these are clicked. */
head('8. The weight screen: live size switching, copy and apply-to-many');

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

  let p;
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

  // ---------- the description behind a symbol ----------
  p = await open('weight2.html');            // one size, four lines, names on three
  const rowH = async () => p.locator('#wlines .wrow').evaluateAll(
    els => els.map(e => Math.round(e.getBoundingClientRect().height)));
  out.rowsClosed   = await rowH();
  /* AT REST — before a single box has been opened. The first version of
     this measured after two were open and reported 130 for a layout
     whose tallest row is 108. */
  out.heights = await p.locator('#wlines .wrow').evaluateAll(els => els.map(e => ({
    h: Math.round(e.getBoundingClientRect().height),
    named: !!e.querySelector('.nmshow')
  })));
  out.inputsClosed = await p.locator('#wlines .nmi').count();
  out.shownClosed  = await p.locator('#wlines .nmshow').allInnerTexts();
  out.dotsFilled   = await p.locator('#wlines .nt.has').count();
  out.dotsEmpty    = await p.locator('#wlines .nt:not(.has)').count();
  /* The glyph, not just the class — a dot that always reads "+" looks
     identical to one that never learned there is something behind it. */
  out.glyphs = await p.locator('#wlines .nt').evaluateAll(
    els => els.map(e => ({ g: e.textContent.trim(), has: e.classList.contains('has') })));

  // open the first line's description
  await p.locator('#wlines .wrow').first().locator('.nt').click();
  await p.waitForTimeout(150);
  out.inputsOpen   = await p.locator('#wlines .nmi').count();
  out.focused      = await p.evaluate(() => document.activeElement &&
                       document.activeElement.classList.contains('nmi'));
  out.openRowTaller = (await rowH())[0] > out.rowsClosed[0];

  // tapping the same symbol again puts it away
  await p.locator('#wlines .wrow').first().locator('.nt').click();
  await p.waitForTimeout(150);
  out.closedBySameTap = await p.locator('#wlines .nmi').count();
  await p.locator('#wlines .wrow').first().locator('.nt').click();
  await p.waitForTimeout(150);

  // type into it, then open another: the first must keep what was typed
  await p.locator('#wlines .nmi').fill('Micro 7668');
  await p.locator('#wlines .wrow').nth(1).locator('.nt').click();
  await p.waitForTimeout(150);
  out.onlyOneOpen  = await p.locator('#wlines .nmi').count();
  out.keptTyped    = (await p.locator('#wlines .nmshow').allInnerTexts())
                       .some(t => t.trim() === 'Micro 7668');


  // deleting the row whose description is open must not break the next draw
  await p.locator('#wlines .wrow').nth(1).locator('.x').click();
  await p.waitForTimeout(150);
  out.afterDelete = await p.locator('#wlines .wrow').count();
  out.deleteErrors = out.errors.length;
  /* The deleted row was the open one. If its index is left behind, the
     row that slid up into its place opens a box nobody asked for. */
  out.openAfterDelete = await p.locator('#wlines .nmi').count();
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
        ok(str_contains((string)($d['applyNone'] ?? ''), 'Tick the ones that weigh the same'),
           'applying with nothing ticked says so instead of doing nothing quietly',
           $d['applyNone'] ?? null);

        /* 850 g in all three now: 850x2 + 850x4 + 850x4 = 8.500 kg */
        ok(str_contains((string)($d['totalsAfter'] ?? ''), '8.500 contents'),
           'the package total follows every copy', $d['totalsAfter'] ?? null);

        /* --- what would actually be posted --- */
        $posted = json_decode((string)($d['posted'] ?? ''), true);
        ok(is_array($posted) && count($posted) === 3,
           'one field carries all three sizes on submit', array_keys((array)$posted));
        ok(is_array($posted) && array_sum(array_column($posted['Large'] ?? [], 'g')) === 850,
           'with the copied figures in it', $posted['Large'] ?? null);

        /* --- the description as a symbol --- */
        ok(($d['inputsClosed'] ?? -1) === 0,
           'no line carries an open text box until it is asked for',
           $d['inputsClosed'] ?? null);
        ok(count($d['shownClosed'] ?? []) === 3
           && in_array('Percale 300TC', $d['shownClosed'] ?? [], true),
           'a named quality shows as a thin line of text instead',
           $d['shownClosed'] ?? null);
        ok(($d['dotsFilled'] ?? 0) === 3 && ($d['dotsEmpty'] ?? 0) === 1,
           'the symbol is filled where there is a name and hollow where there is not',
           [$d['dotsFilled'] ?? null, $d['dotsEmpty'] ?? null]);
        /* The point of the whole change. Every line used to carry a
           full-width box whether it was wanted or not; an unnamed line
           is now the control row and nothing else. */
        $hs     = $d['heights'] ?? [];
        $unnamed = array_column(array_filter($hs, fn($r) => !$r['named']), 'h');
        $named   = array_column(array_filter($hs, fn($r) =>  $r['named']), 'h');
        ok($unnamed !== [] && $named !== [] && max($unnamed) < min($named),
           'an unnamed line is shorter than a named one — the box is simply not there',
           ['unnamed' => $unnamed, 'named' => $named]);
        ok($unnamed !== [] && max($unnamed) <= 90,
           'and it is one control row, not two',
           $unnamed);
        /* A named line shows text, not a field: a field is ~37px plus its
           margin, the text line is 24. */
        ok($named !== [] && $unnamed !== [] && (min($named) - max($unnamed)) <= 30,
           'a named line costs a line of text, not a second input',
           ['named' => min($named ?: [0]), 'unnamed' => max($unnamed ?: [0])]);
        ok(($d['inputsOpen'] ?? 0) === 1 && ($d['openRowTaller'] ?? null) === true,
           'tapping the symbol opens one input, on that line only',
           [$d['inputsOpen'] ?? null, $d['openRowTaller'] ?? null]);
        ok(($d['focused'] ?? null) === true,
           'and puts the cursor in it, so the keyboard is already there');
        ok(($d['closedBySameTap'] ?? -1) === 0,
           'tapping the same symbol again puts the box away',
           $d['closedBySameTap'] ?? null);
        $gl = $d['glyphs'] ?? [];
        ok($gl !== [] && count(array_filter($gl, fn($x) => $x['has'] && $x['g'] === '●')) === 3
           && count(array_filter($gl, fn($x) => !$x['has'] && $x['g'] === '+')) === 1,
           'the symbol itself says whether something is behind it, not just its class',
           $gl);
        ok(($d['onlyOneOpen'] ?? 0) === 1,
           'opening another closes the first — one at a time, never a wall of boxes',
           $d['onlyOneOpen'] ?? null);
        ok(($d['keptTyped'] ?? null) === true,
           'what was typed survives the box closing', $d['shownClosed'] ?? null);
        ok(($d['afterDelete'] ?? 0) === 3 && (int)($d['deleteErrors'] ?? 1) === 0,
           'deleting the line that was open does not throw on the next draw',
           [$d['afterDelete'] ?? null, $d['deleteErrors'] ?? null]);
        ok(($d['openAfterDelete'] ?? -1) === 0,
           'and the row that slid up into its place does not open a box of its own',
           $d['openAfterDelete'] ?? null);

        /* --- the remembered breakdown --- */
        ok(($d['stdVisible'] ?? null) === true, 'the last saved breakdown is offered');
        ok((int)($d['beforeStd'] ?? -1) === 4 && (int)($d['afterStd'] ?? 0) === 2,
           'and one tap replaces the lines with it',
           [$d['beforeStd'] ?? null, $d['afterStd'] ?? null]);
        ok(($d['stdNoNav'] ?? null) === true, 'without a round trip');
    }
}

head('8b. One size in two colours — asked once, or asked twice');

/* "dont ask again and again if u have weight information by size or
    color already" — against "if any colours weigh differently".
   Both ranges below hold six White Single and four Navy Single. The ONLY
   difference is the range's own switch, and it decides whether that is
   one question or two. */
foreach (['colours differ' => $w4, 'colours are the same' => $w5] as $what => $pg) {
    ok(!preg_match('~(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught)~i', $pg),
       "the weight tab renders without a PHP complaint when the $what",
       preg_match('~^.*(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught).*$~mi', $pg, $em) ? $em[0] : null);
}

/* ---- the range whose colours differ: two things to weigh */
$u4 = preg_match('~var SIZES  = (\[.*?\]);~s', $w4, $m4) ? json_decode($m4[1], true) : null;
ok($u4 === ['Single|White', 'Single|Navy'],
   'two things to weigh, filed under the size AND the colour', $u4);
$n4 = preg_match('~var NAMES  = (\{.*?\});~s', $w4, $m4n) ? json_decode($m4n[1], true) : null;
ok($n4 === ['Single|White' => 'White Single', 'Single|Navy' => 'Navy Single'],
   'and read by a packer as a colour and a size, never as the key', $n4);
ok(str_contains($w4, '6 White Single + 4 Navy Single'),
   'the carton is spelled out colour by colour');
ok(str_contains($w4, '2 of 2') === false && str_contains($w4, 'Nothing new to weigh here.'),
   'both are already known, so nothing is asked again');
$p4 = preg_match('~var PERPKG = (\{.*?\});~s', $w4, $m4p) ? json_decode($m4p[1], true) : null;
ok($p4 === ['Single|White' => 6, 'Single|Navy' => 4],
   'six of one and four of the other, kept apart', $p4);
/* THE LINES THEMSELVES, not just the keys. A page that carried the right
   keys with nobody's weights under them would look identical in every
   assertion above and leave the packer re-entering both. */
$l4 = preg_match('~var LINES  = (\{.*?\});\n~s', $w4, $m4l) ? json_decode($m4l[1], true) : null;
ok(is_array($l4) && array_column($l4['Single|White'] ?? [], 'g') === [840, 60],
   'White comes down with White\'s own breakdown', $l4['Single|White'] ?? null);
ok(is_array($l4) && array_column($l4['Single|Navy'] ?? [], 'g') === [940, 60],
   'and Navy with Navy\'s, which is a different cloth',
   $l4['Single|Navy'] ?? null);

/* ---- the range whose colours do not: ONE thing to weigh, ten of it */
$u5 = preg_match('~var SIZES  = (\[.*?\]);~s', $w5, $m5) ? json_decode($m5[1], true) : null;
ok($u5 === ['Single'],
   'ONE thing to weigh, because the dye does not change what it weighs', $u5);
$p5 = preg_match('~var PERPKG = (\{.*?\});~s', $w5, $m5p) ? json_decode($m5p[1], true) : null;
ok($p5 === ['Single' => 10],
   'and TEN of it in the carton — six plus four, not six', $p5);
ok(str_contains($w5, '10 Single') && !str_contains($w5, '6 White Single'),
   'the carton reads as ten Singles');
ok(!preg_match("~mob_step\('Which one'~", $w5)
   && substr_count($w5, 'id="szchips"') === 0,
   'with no step and no chips asking which of the one to pick');

/* THE REMEMBERED BREAKDOWN, per colour. Navy has one from an earlier
   order and White has none, so "Use last saved" must be offered on Navy
   and not on White. */
$s4 = preg_match('~var STD    = (\{.*?\});~s', $w4, $m4s) ? json_decode($m4s[1], true) : null;
ok(is_array($s4) && array_keys($s4) === ['Single|Navy'],
   'the remembered breakdown comes down for the colour that has one, and only that one',
   is_array($s4) ? array_keys($s4) : $m4s[1] ?? null);
ok(is_array($s4) && array_column($s4['Single|Navy'] ?? [], 'g') === [930, 60],
   'and it is Navy\'s own figures, not the size\'s', $s4['Single|Navy'] ?? null);

$l5 = preg_match('~var LINES  = (\{.*?\});\n~s', $w5, $m5l) ? json_decode($m5l[1], true) : null;
ok(is_array($l5) && array_column($l5['Single'] ?? [], 'g') === [880, 60],
   'the one unit comes down with the one breakdown', $l5['Single'] ?? null);

/* ---- and the totals read it the same way the packer filed it */
$t8 = json_decode((string)shell_exec('cd ' . escapeshellarg($work) . ' && COLOURRANGES=1 ' . escapeshellarg(PHP_BINARY)
    . ' totals.php 2>&1'), true);
ok(is_array($t8) && ($t8['unfinished'] ?? ['?']) === [], 'the colour ranges total with nothing unfinished', $t8['unfinished'] ?? $t8);
/* 9.400 kg of contents in each of 11 cartons, for both ranges */
ok(abs((float)($t8['net'] ?? 0) - (5317 + 9.4 * 11 * 2)) < 0.001,
   'and each adds 11 x 9.400 kg, by its own route', $t8['net'] ?? null);

/* The same carton weight by both routes, which is the point: the switch
   changes how often you are asked, not what the carton weighs. */
foreach (['differ' => $w4, 'same' => $w5] as $what => $pg) {
    ok(str_contains($pg, '9.400 contents + 1.000 package'),
       "the carton holds 9.400 kg whether the colours $what");
    ok(str_contains($pg, '10.400 kg each'),
       "and comes to 10.400 kg gross either way ($what), worked out rather than typed");
}

head('9. The desktop screen the office types the order on');

/* "do u prefer enter in mobile version or desktop version?" — desktop,
   because the customer's colour list arrives by email at a desk, is
   typed once, and is then read on every carton for the whole order. */
[$pp] = render($work, ['id' => 1, 'item' => 11], 'pack_palette.php');
ok(!preg_match('~(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught)~i', $pp),
   'the office screen renders without a PHP complaint',
   preg_match('~^.*(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught).*$~mi', $pp, $pm) ? $pm[0] : null);
ok(str_contains($pp, '</html>') && !str_contains($pp, '__REDIRECT__'),
   'and reaches the end of the page');

/* Ticked is what the office already chose; offered-and-unticked is what
   it MAY choose. A screen that ticked everything it offered would put
   the product's whole history into one order without anybody saying so. */
ok(preg_match('~name="pick_colour\[\]" value="White"[^>]*checked~', $pp) === 1,
   'the colour the office chose comes back ticked');
ok(preg_match('~name="pick_size\[\]" value="King"(?![^>]*checked)~', $pp) === 1,
   'a size the product is costed in is offered, but not ticked for this order');
ok(substr_count($pp, 'name="pick_size[]"') === 5,
   'five sizes on offer — the two costed plus the three it has been packed in',
   substr_count($pp, 'name="pick_size[]"'));
ok(substr_count($pp, 'name="pick_colour[]"') === 3,
   'three colours on offer — the two this order uses plus one it was packed in before',
   substr_count($pp, 'name="pick_colour[]"'));
/* The pair that makes the screen readable at all: chosen is ticked,
   merely-available is not. */
ok(preg_match('~name="pick_colour\[\]" value="Grey"(?![^>]*checked)~', $pp) === 1,
   'a colour this product was packed in before, but not ordered now, is offered unticked');
ok(preg_match('~name="pick_size\[\]" value="Small"[^>]*checked~', $pp) === 1,
   'and a size this order does use comes back ticked');
ok(str_contains($pp, 'costed') && str_contains($pp, 'packed before'),
   'each one says where it came from, so a typo from last year is recognisable');
ok(str_contains($pp, '3 sizes and 2 colours'),
   'and it says in words how much the packing phone will then show');
ok(str_contains($pp, 'name="new_size"') && str_contains($pp, 'name="new_colour"'),
   'something the customer asked for that is not listed can still be typed');

/* Lines that share a product name are told apart by number and wording. */
ok(str_contains($pp, 'Duvet Cover Set King <i class="ihint">#1 · Fleece + micro, printed</i>'),
   'each line chip carries its number and its own wording, small and italic');
ok(str_contains($pp, '<i class="ihint">invoice line #1</i>'),
   'and the line being set up says which invoice line it is');
ok(str_contains($pp, 'The invoice line says: <b>Fleece + micro, printed</b>'),
   'the invoice line\'s own words sit beside the colours, as the hint for choosing them');

/* The line nobody has set up must say so, not look saved. */
[$pp2] = render($work, ['id' => 1, 'item' => 12], 'pack_palette.php');
ok(str_contains($pp2, 'The invoice line says: <b>Percale, white</b>'),
   'and on an unset line too');
/* "white" is in that line's wording, so the White chip is marked — and
   only that one. */
ok(preg_match('~value="White".*?White<i class="src">on invoice</i>~s', $pp2) === 1
   && substr_count($pp2, 'on invoice</i>') === 1,
   'the colour the invoice line names is marked "on invoice", and no other',
   substr_count($pp2, 'on invoice</i>'));
ok(str_contains($pp2, 'Nothing set for this line yet'), 'an unset line says so plainly');
ok(!preg_match('~name="pick_colour\[\]"[^>]*checked~', $pp2), 'and has nothing ticked');

/* Read-only once the list is closed — the same rule the phone obeys. */
/* A completed list, seen by staff rather than by the boss. The admin
   case is the opposite and is checked straight after. */
[$pp3] = render($work, ['id' => 1, 'item' => 11], 'pack_palette.php',
                'PACKSTATUS=completed ROLE=staff');
ok(str_contains($pp3, 'read-only') && !str_contains($pp3, 'Save this line'),
   'a completed packing list is read-only to staff, with no save button',
   str_contains($pp3, 'Save this line') ? 'the save button is still there' : null);
ok(substr_count($pp3, 'disabled') >= 7,
   'and every chip on it is disabled', substr_count($pp3, 'disabled'));
ok(!str_contains($pp3, 'name="new_colour"'),
   'with nothing left to type into either');

/* AND THE OTHER HALF OF THE SAME RULE. Locking it against the boss too
   would mean a wrong colour on a completed list could never be put
   right, which is worse than the mistake. */
[$pp4] = render($work, ['id' => 1, 'item' => 11], 'pack_palette.php',
                'PACKSTATUS=completed ROLE=admin');
ok(str_contains($pp4, 'Save this line') && !str_contains($pp4, 'read-only'),
   'but the boss can still correct it');

/* Production staff have no business on a packing list at all. */
[$pp5] = render($work, ['id' => 1, 'item' => 11], 'pack_palette.php',
                'ROLE=production_staff');
ok(str_contains($pp5, 'cannot open the packing list') && !str_contains($pp5, 'pick_colour'),
   'and production staff are turned away at the door, not just shown it read-only');

head('10. A file that was not uploaded, end to end');

/* The real failure, reproduced: these screens are uploaded by hand, and
   the server keeps whatever was not replaced. A copy of mobile.php that
   predates the steps leaves the page calling a helper that is not there
   — PHP stops mid-page, display_errors is off on the live server, and
   the phone shows a header, a tab bar and then nothing.

   A source check cannot tell a guard that runs from one somebody wrapped
   in if (false), so this one renders the page against a crippled file. */
$goodMobile = (string)file_get_contents($work . '/includes/mobile.php');
file_put_contents($work . '/includes/mobile.php',
    str_replace('function mob_steps_begin', 'function mob_steps_begin_OLD', $goodMobile));
[$stale] = render($work, ['id' => 1]);
file_put_contents($work . '/includes/mobile.php', $goodMobile);

ok(str_contains($stale, 'One file is out of date'),
   'an out-of-date file is named, instead of the page going blank',
   substr($stale, 0, 200));
ok(str_contains($stale, 'includes/mobile.php') && str_contains($stale, 'mob_steps_begin'),
   'it says which file and what it was looking for');
ok(!str_contains($stale, 'id="sp"') && !str_contains($stale, 'var STATE'),
   'and it stops before drawing half a screen, which is what made it look like lost data');
ok(str_contains($stale, '</html>'), 'the page it does send is a whole one');

/* The page must be no worse off than before when nothing is missing. */
[$fine] = render($work, ['id' => 1]);
ok(!str_contains($fine, 'One file is out of date') && str_contains($fine, 'id="sp"') && str_contains($fine, 'var STATE'),
   'and with every file present the screen draws exactly as it did');

head('11. A failure does not blank the screen');

/* WHAT CAME BACK FROM THE LIVE SERVER once: a header, and then nothing.
   The packing screen now reads everything before it prints anything, so
   a throw there is one plain card on a whole page. */
[$br] = render($work, ['id' => 1], 'm_pack.php', 'BREAKTOKEN=1');
ok(str_contains($br, '</html>'), 'the page still reaches its end');
ok(str_contains($br, 'The packing screen could not be drawn.'),
   'it says plainly that the screen could not be drawn', substr($br, 0, 300));
ok(str_contains($br, 'Column not found: test breakage'), 'and, for an admin, why — the very text the server threw');
ok(!str_contains($br, 'var STATE'), 'with no half-drawn screen under it');
[$brs] = render($work, ['id' => 1], 'm_pack.php', 'BREAKTOKEN=1 ROLE=staff');
ok(str_contains($brs, 'could not be drawn.') && !str_contains($brs, 'Column not found'),
   'staff are told it failed, but not the reason — that can name a query');

[$bw] = render($work, ['id' => 1, 't' => 'weight', 'g' => 102], 'm_pack.php', 'BREAKCSRF=1');
ok(str_contains($bw, 'The weight for this range could not be drawn.')
   && str_contains($bw, '</html>')
   && substr_count($bw, '<form') === substr_count($bw, '</form>'),
   'the weight tab fails the same way: one plain card, a whole page');

[$okp] = render($work, ['id' => 1]);
ok(!str_contains($okp, 'could not be drawn'), 'and with nothing broken there is no error card at all');

head('12. Lines told apart, and lists that follow the line');

/* From the live screenshots: four "Bath Towel" lines that could not be
   told apart, and sixteen sizes on the phone when each line on the
   desktop had one or two. */
$pr12 = [];
foreach ($st2['products'] ?? [] as $pp12) $pr12[$pp12['id']] = $pp12;
ok(($pr12['11']['line'] ?? '') === '#1' && ($pr12['11']['hint'] ?? '') === 'Fleece + micro, printed',
   'every invoice line carries its number and its own wording', $pr12['11'] ?? null);
ok(($pr12['11']['sizes'] ?? null) === ['Small', 'Medium', 'Large'] && ($pr12['11']['colors'] ?? null) === ['White', 'Navy'],
   'line #1 offers exactly the three sizes and two colours the office ticked', $pr12['11'] ?? null);
ok(!in_array('King', $pr12['11']['sizes'] ?? ['King'], true),
   'and not a size the product is merely costed in, once the line is set up');
ok(($pr12['12']['setUp'] ?? true) === false && ($pr12['12']['colors'] ?? ['x']) === [],
   'a line not set up is marked as such, with no colours invented for it', $pr12['12'] ?? null);
ok(str_contains($ser, "This line is not set up on Order sizes &amp; colours"),
   'and the screen says so where it is packed');

head('13. One weight per invoice line');

/* The weight tab is about the invoice LINE. */
$w13 = $html['weight'];
ok(preg_match('~<select class="in" onchange="location.href=this.value">(.*?)</select>~s', $w13, $sel13) === 1
   && substr_count($sel13[1], '<option') === 3,
   'the weight tab lists each invoice line once, not each range', substr_count($sel13[1] ?? '', '<option'));
ok(str_contains($sel13[1] ?? '', '#1 Duvet Cover Set King') && str_contains($sel13[1] ?? '', '· 1 range'),
   'named by line, with how many ranges share it');
ok(str_contains($w13, 'Weight is set once per invoice line'),
   'and it says so where the line is picked');
ok(str_contains($w13, 'Shared by') && str_contains($w13, 'Carton 1–100'),
   'and names the ranges that will use it');

/* ONE LINE, TWO RANGES — the case he described. */
[$wsh] = render($work, ['id' => 1, 't' => 'weight', 'g' => 101], 'm_pack.php', 'SHARELINE=1');
ok(preg_match('~<select class="in" onchange="location.href=this.value">(.*?)</select>~s', $wsh, $ssh) === 1
   && substr_count($ssh[1], '<option') === 2,
   'two ranges of one line are one entry in the weight list, not two',
   substr_count($ssh[1] ?? '', '<option'));
ok(str_contains($ssh[1] ?? '', '· 2 ranges'), 'and it says two ranges share it');
ok(preg_match('~Shared by\s+Carton 1–100, Carton 101–225\.~', $wsh) === 1, 'naming both');
$ush = preg_match('~var SIZES  = (\[.*?\]);~s', $wsh, $mush) ? json_decode($mush[1], true) : null;
ok($ush === ['Small', 'Medium', 'Large', '152x200'],
   'every size either range holds is weighed here, once', $ush);
ok(str_contains($wsh, '10.420 contents') && str_contains($wsh, '19.800 contents'),
   'and each range is worked out from its own quantities');
[$wsh2] = render($work, ['id' => 1, 't' => 'weight', 'g' => 102], 'm_pack.php', 'SHARELINE=1');
ok(preg_match('~var SIZES  = (\[.*?\]);~s', $wsh2, $mush2) && json_decode($mush2[1], true) === $ush,
   'opening it from the other range shows the same one weight');

head('14. Typing a weight, key by key');

/* THE BUG FROM THE LIVE SCREEN: he typed a weight and only "3" stayed.
   Every keystroke redrew the whole list, which destroyed the box being
   typed in, so after the first digit the rest went nowhere. No earlier
   test typed more than one character — this one types like a person. */
$js14 = <<<'JS'
const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 390, height: 844 } });
  const errs = []; p.on('pageerror', e => errs.push(String(e)));
  await p.goto('file://' + path.join(process.argv[2], 'weight2.html'));
  await p.waitForTimeout(300);
  const g = p.locator('#wlines .wrow .g').first();
  await g.click();
  await p.keyboard.type('312', { delay: 40 });
  const out = { errs };
  out.value   = await g.inputValue();
  out.focused = await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('g'));
  out.perunit = await p.locator('#perunit').innerText();
  out.type    = await g.getAttribute('type');
  out.width   = Math.round((await g.boundingBox()).width);
  // and a second line, typed straight after, keeps the first
  const g2 = p.locator('#wlines .wrow .g').nth(1);
  await g2.click();
  await p.keyboard.type('25.5', { delay: 40 });
  out.value2 = await g2.inputValue();
  out.value1after = await g.inputValue();
  console.log(JSON.stringify(out));
  await b.close();
})();
JS;
file_put_contents($work . '/s14.js', $js14);
$r14 = json_decode((string)shell_exec('cd ' . escapeshellarg(__DIR__) . ' && node '
        . escapeshellarg($work . '/s14.js') . ' ' . escapeshellarg($work) . ' 2>&1'), true);
ok(is_array($r14) && $r14['errs'] === [], 'no javascript error while typing', $r14);
ok(($r14['value'] ?? '') === '312', 'typing 3, 1, 2 leaves 312 in the box, not 3', $r14['value'] ?? null);
ok(($r14['focused'] ?? false) === true, 'and the cursor is still in it after the last key');
/* range 2's other three lines are 30 + 25 + 20 = 75 g, so 312 + 75 */
ok(($r14['perunit'] ?? '') === '387 g', 'the unit total follows every key', $r14['perunit'] ?? null);
ok(($r14['value2'] ?? '') === '25.5' && ($r14['value1after'] ?? '') === '312',
   'a second line typed straight after keeps the first, decimals and all',
   [$r14['value2'] ?? null, $r14['value1after'] ?? null]);
ok(($r14['type'] ?? '') === 'text' && ($r14['width'] ?? 0) >= 95,
   'the box is wide enough to show four digits, with no arrows eating into it',
   [$r14['type'] ?? null, $r14['width'] ?? null]);

echo "\n" . ($F ? "FAILED  $F" : 'ALL PASS') . "   ($P checks)\n";
exit($F ? 1 : 0);
