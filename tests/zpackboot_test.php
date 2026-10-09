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
function csrf_token(){ return 'testtoken'; }
function csrf_field(){ return '<input type="hidden" name="_csrf" value="testtoken">'; }
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
function redirect($u){ echo "\n__REDIRECT__ $u || " . ($_SESSION['error'] ?? $_SESSION['flash'] ?? '') . "\n"; exit; }
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
    ['id'=>11,'product_name'=>'Duvet Cover Set King','des_col'=>'Fleece + micro, printed','optional_value'=>'HS 6302.31','qty'=>900],
    ['id'=>12,'product_name'=>'Hotel Flat Sheet 300TC','des_col'=>'Percale, white','optional_value'=>'HS 6302.21','qty'=>5200],
    ['id'=>13,'product_name'=>'Bath Towel 500GSM','des_col'=>'Combed, dobby','optional_value'=>'HS 6302.60','qty'=>3800],
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
  /* TWO RANGES OF ONE SIZE IN TWO COLOURS, and the only difference
     between them is the switch.

     104 says the colours weigh differently, so it is two things to
     weigh, kept apart. 105 says they do not, so it is ONE thing —
     asked once — and the ten in the carton are ten Singles, not six
     and four. That is the rule he asked for, in a fixture. */
  'cgroups' => [
    ['id'=>104,'shipment_id'=>1,'line_no'=>4,'invoice_item_id'=>11,'product_name'=>'Duvet Cover Set King',
     'des_col'=>'Fleece + micro, printed','optional_value'=>'HS 6302.31','unit_title'=>'Carton',
     'serial_from'=>300,'serial_to'=>310,'packages'=>0,'qty_mode'=>'per','qty_per_pkg'=>10,'total_qty'=>110,
     'assorted'=>1,'size_label'=>'','pkg_gross'=>10.400,'pkg_tare'=>1.000,'weight_by_colour'=>1],
    ['id'=>105,'shipment_id'=>1,'line_no'=>5,'invoice_item_id'=>11,'product_name'=>'Duvet Cover Set King',
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
            return getenv('COLOURRANGES') ? array_merge($R['groups'], $R['cgroups']) : $R['groups'];
        }
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
    'serial'  => ['id' => 1, 't' => 'serial'],
    'weight'  => ['id' => 1, 't' => 'weight'],
    'weight2' => ['id' => 1, 't' => 'weight', 'g' => 102],
    'weight3' => ['id' => 1, 't' => 'weight', 'g' => 103],
    'mix'     => ['id' => 1, 't' => 'mix', 'g' => 101],
    'mix2'    => ['id' => 1, 't' => 'mix', 'g' => 102],
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

/* The two colour ranges, rendered here rather than further down so the
   browser section below loads them too — their chips are drawn by
   script, and a markup check cannot tell a chip that shows "Navy
   Single" from one that shows the raw key "Single|Navy". */
[$w4] = render($work, ['id' => 1, 't' => 'weight', 'g' => 104], 'm_pack.php', 'COLOURRANGES=1');
[$w5] = render($work, ['id' => 1, 't' => 'weight', 'g' => 105], 'm_pack.php', 'COLOURRANGES=1');
file_put_contents($work . '/weight4.html', $w4);
file_put_contents($work . '/weight5.html', $w5);

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

/* A RANGE THAT IS NOT ASSORTED STILL HAS A COLOUR. 40 white, 30 navy and
   30 grey is three ordinary ranges and no tapping at all — he was right
   about that — so the plain card needs a colour of its own. */
ok(preg_match('~<select class="in" name="single_colour">(.*?)</select>~s', $ser, $cm) === 1,
   'a plain range can be given a colour without becoming assorted');
ok(isset($cm[1]) && substr_count($cm[1], '<option') === 3
   && str_contains($cm[1], '— no colour —')
   && str_contains($cm[1], '>White<') && str_contains($cm[1], '>Navy<'),
   'offering this order\'s two colours, plus none at all',
   $cm[1] ?? null);
ok(!str_contains((string)($cm[1] ?? ''), 'Grey'),
   'and not a colour the product was merely packed in once, which is the office\'s to choose');
/* Line 12 has no palette, so its card must not show an empty dropdown. */
ok(substr_count($ser, 'name="single_colour"') === 4
   && substr_count($ser, '<select class="in" name="single_colour">') === 1,
   'the lines with no colours set carry it hidden instead of showing an empty list',
   [substr_count($ser, 'name="single_colour"'),
    substr_count($ser, '<select class="in" name="single_colour">')]);

head('4. Assorted is a page of its own, and it adds up');

$mix = $html['mix'];
ok(str_contains($mix, 'Assorted — Carton 1–100'),
   'the assorted page is titled for the serial it belongs to');
ok(str_contains($mix, 'Carton 1') && str_contains($mix, '100'), 'and names the range it belongs to');
/* The rows are not in the markup any more — the pads build them as they
   are tapped, and section 7 taps them. What the markup can prove is that
   the pads arrived and that the saved mix came down with the page. */
ok(str_contains($mix, 'id="szPad"'), 'a size pad to tap');
ok(str_contains($mix, 'id="colPad"'), 'and a colour pad, because this order has colours');
/* The empty div, not the absence of the string — the script below it
   contains name="size_label[]" as the text it builds rows from, and a
   plain search finds that instead. */
ok(str_contains($mix, '<div id="rows"></div>'),
   'the posted rows start empty and are built from the tally, never typed');
$boot = preg_match('~var MIX\s*=\s*(\[.*?\]);~s', $mix, $bm) ? json_decode($bm[1], true) : null;
ok(is_array($boot) && count($boot) === 3, 'the saved set of three comes down with the page',
   is_array($boot) ? count($boot) : substr((string)($bm[1] ?? ''), 0, 120));
ok(is_array($boot) && array_map('floatval', array_column($boot, 'q')) === [2.0, 4.0, 4.0],
   'holding 2, 4 and 4 from the saved set',
   is_array($boot) ? array_column($boot, 'q') : null);
ok(is_array($boot) && preg_match('~var TARGET\s*=\s*10;~', $mix) === 1,
   'and a target of 10, which is what one carton holds');

/* The line the office never set up still has to work: no colour pad,
   and the size list falls back to the product master. */
$mix2 = $html['mix2'];
ok(!str_contains($mix2, 'id="colPad"') && str_contains($mix2, 'id="szPad"'),
   'a line with no colours set gets the size pad only, not an empty colour pad');
ok(preg_match('~var SIZES\s*=\s*(\[.*?\]);~s', $mix2, $m2)
   && in_array('King', (array)json_decode($m2[1], true), true),
   'and its sizes fall back to the product master rather than nothing',
   $m2[1] ?? null);
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
    /* THE PADS, TAPPED. A count of the hidden inputs would pass against
       a pad that draws but never adds up, so this reads the meter, taps
       a colour and a size, and reads what would actually be posted. */
    /* count() on the pad, not on the div: a pad that drew no button at
       all is a real state (an order with no sizes set), and clicking into
       it should report an empty pad, not hang the whole run. */
    if (await p.locator('#szPad button').count()) {
      r.mCount = await p.locator('#mCount').innerText();
      r.mMsg   = await p.locator('#mMsg').innerText();
      r.mState = await p.locator('#meter').getAttribute('class');
      const posted = async () => p.evaluate(() => Array.from(
        document.querySelectorAll('#rows input')).map(i => i.name + '=' + i.value).join(','));
      r.posted0 = await posted();
      if (await p.locator('#colPad button').count()) {
        await p.locator('#colPad button', { hasText: 'Navy' }).click();
        r.colOn = await p.locator('#colPad button.on').innerText();
      }
      await p.locator('#szPad button').first().click();
      await p.locator('#szPad button').first().click();
      r.mCount2 = await p.locator('#mCount').innerText();
      r.mMsg2   = await p.locator('#mMsg').innerText();
      r.mState2 = await p.locator('#meter').getAttribute('class');
      r.posted2 = await posted();
      r.tally   = await p.locator('#tally .tl .who').allInnerTexts();
      /* and taken back out again, because a pad that only counts up is
         no use to a packer who taps one too many */
      await p.locator('#tally .tl').last().locator('.minus').click();
      r.mCount3 = await p.locator('#mCount').innerText();
      r.posted3 = await posted();
      if (await p.locator('#wbcSeg').count()) {
        await p.locator('#wbcSeg button').last().click();
        r.wbc = await p.locator('#wbc').inputValue();
        r.wbcOn = await p.locator('#wbcSeg button.on').innerText();
      }
    }
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
        ok(($got['weight4']['wsub'] ?? '') === '6 of these in each package',
           'and says how many of them are in the carton', $got['weight4']['wsub'] ?? null);
        ok(($got['weight5']['wsub'] ?? '') === '10 of these in each package',
           'which on the range whose colours match is ten, not six',
           $got['weight5']['wsub'] ?? null);
        ok(($got['weight4']['beforeCopy'] ?? '') === '900 g'
           && ($got['weight4']['afterCopy'] ?? '') === '1,000 g',
           'copying the other colour\'s breakdown really copies it',
           [$got['weight4']['beforeCopy'] ?? null, $got['weight4']['afterCopy'] ?? null]);
        ok(($got['weight5']['chips'] ?? []) === [],
           'a range with one thing to weigh draws no chips at all',
           $got['weight5']['chips'] ?? null);

        /* ---- the tap pads, as a packer meets them */
        $M = $got['mix'] ?? [];
        ok(($M['mCount'] ?? '') === '10 / 10', 'the saved mix opens complete at 10 of 10',
           $M['mCount'] ?? null);
        ok(($M['mMsg'] ?? '') === 'Complete.' && str_contains((string)($M['mState'] ?? ''), 'done'),
           'and says so, in the colour of a finished carton',
           [$M['mMsg'] ?? null, $M['mState'] ?? null]);
        ok(substr_count((string)($M['posted0'] ?? ''), 'size_label[]') === 3,
           'what would post is the saved three, rebuilt from the tally',
           $M['posted0'] ?? null);
        ok(($M['colOn'] ?? '') === 'Navy', 'tapping a colour selects it and nothing else',
           $M['colOn'] ?? null);
        ok(($M['mCount2'] ?? '') === '12 / 10', 'two taps on a size add two pieces',
           $M['mCount2'] ?? null);
        ok(($M['mMsg2'] ?? '') === '2 too many — take some out.'
           && str_contains((string)($M['mState2'] ?? ''), 'over'),
           'and an over-filled carton says how many to take out, before it can be saved',
           [$M['mMsg2'] ?? null, $M['mState2'] ?? null]);
        ok(str_contains((string)($M['posted2'] ?? ''), 'colour_label[]=Navy')
           && str_contains((string)($M['posted2'] ?? ''), 'size_qty[]=2'),
           'the new piece posts under the colour that was tapped',
           $M['posted2'] ?? null);
        ok(in_array('Navy Small', array_map(static fn($t) => trim(preg_replace('~\s+~', ' ', $t)),
                                            (array)($M['tally'] ?? [])), true),
           'and shows up in the tally named by colour and size',
           $M['tally'] ?? null);
        ok(($M['mCount3'] ?? '') === '11 / 10', 'minus takes one back out',
           $M['mCount3'] ?? null);
        ok(substr_count((string)($M['posted3'] ?? ''), 'size_label[]') === 4,
           'and what posts follows it down', $M['posted3'] ?? null);
        ok(($M['wbc'] ?? '') === '1' && str_contains((string)($M['wbcOn'] ?? ''), 'differs'),
           'the per-colour weight switch moves and is what posts',
           [$M['wbc'] ?? null, $M['wbcOn'] ?? null]);
        /* Range 2 is a plain single-size range being turned assorted.
           What it already holds is the starting point — the packer splits
           it up, he does not re-enter it. */
        $M2 = $got['mix2'] ?? [];
        ok(($M2['mCount'] ?? '') === '40 / 40',
           'a single-size range opens assorted at the quantity it already had',
           $M2['mCount'] ?? null);
        ok(substr_count((string)($M2['posted0'] ?? ''), 'size_label[]') === 1
           && str_contains((string)($M2['posted0'] ?? ''), 'size_qty[]=40'),
           'carried in as one row, not lost', $M2['posted0'] ?? null);
        ok(($M2['mCount2'] ?? '') === '42 / 40',
           'and counts on against its own target, which is 40 here not 10',
           $M2['mCount2'] ?? null);
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
        ok(str_contains((string)($d['applyNone'] ?? ''), 'Tick the ones that weigh the same'),
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

/* ---- and the approver sees it the same way the packer filed it */
[$ap] = render($work, ['id' => 1, 't' => 'approve'], 'm_pack.php', 'COLOURRANGES=1');
ok(!preg_match('~(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught)~i', $ap),
   'approve renders the colour ranges without a PHP complaint',
   preg_match('~^.*(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught).*$~mi', $ap, $am) ? $am[0] : null);
ok(str_contains($ap, '<td>White Single</td>') && str_contains($ap, '<td>Navy Single</td>'),
   'the approve table names the colour with the size');
/* 900 and 1,000 — read by size alone both rows would show 0 g, because
   nothing was ever filed under the bare size for that range. */
ok(preg_match('~<td>White Single</td>.*?<td class="n">900</td>~s', $ap) === 1,
   'and shows White at 900 g a unit, from where White was actually filed');
ok(preg_match('~<td>Navy Single</td>.*?<td class="n">1,000</td>~s', $ap) === 1,
   'and Navy at 1,000 g, which is a different number');

/* The same carton weight by both routes, which is the point: the switch
   changes how often you are asked, not what the carton weighs. */
foreach (['differ' => $w4, 'same' => $w5] as $what => $pg) {
    ok(preg_match('~The lines add up to</span><b>([\d.,]+) kg~', $pg, $km) === 1
       && $km[1] === '9.400',
       "the carton comes to 9.400 kg whether the colours $what", $km[1] ?? null);
    ok(str_contains($pg, 'pill p">balanced'),
       "and balances against the scale either way ($what)");
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

/* The line nobody has set up must say so, not look saved. */
[$pp2] = render($work, ['id' => 1, 'item' => 12], 'pack_palette.php');
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

echo "\n" . ($F ? "FAILED  $F" : 'ALL PASS') . "   ($P checks)\n";
exit($F ? 1 : 0);
