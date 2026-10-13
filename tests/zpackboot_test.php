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

/* THE OLD WEIGHT SCREEN IS RETIRED. Weight is its own module now
   (weight.php, tested in zweight_test.php); an old link goes there. */
[$oldW] = render($work, ['id' => 1, 't' => 'weight', 'g' => 102]);
ok(str_contains($oldW, '__REDIRECT__ weight.php?id=1#link'), 'an old link to the weight screen goes to the Weight module', substr($oldW, 0, 200));

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
ok(str_contains($ser, 'is compared with the invoice'), 'and the screen says it is compared with the invoice (V2.5: checked, not enforced)');

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
    }
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
    str_replace('function mob_card_error', 'function mob_card_error_OLD', $goodMobile));
[$stale] = render($work, ['id' => 1]);
file_put_contents($work . '/includes/mobile.php', $goodMobile);

ok(str_contains($stale, 'One file is out of date'),
   'an out-of-date file is named, instead of the page going blank',
   substr($stale, 0, 200));
ok(str_contains($stale, 'includes/mobile.php') && str_contains($stale, 'mob_card_error'),
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

echo "\n" . ($F ? "FAILED  $F" : 'ALL PASS') . "   ($P checks)\n";
exit($F ? 1 : 0);
