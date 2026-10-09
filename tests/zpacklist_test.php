<?php
/* THE LIST A PACKER IS OFFERED IS THIS PRODUCT'S, EXACTLY.
 *
 * "Make rule as always list of sizes as per product exactly also
 *  remember history or colors and sizes as used for same product"
 *
 * It used to offer the product's own sizes, then every size label used
 * anywhere in packing, then a made-up list ending in Queen and King. So
 * a bath towel was offered King, and eighty labels from other people's
 * shipments. A long wrong list is slower than typing, and a wrong label
 * picked once is wrong in the database for ever.
 *
 * Two sources now, both about this product:
 *   what the product master has been costed in, and
 *   what this product has actually been packed in before.
 *
 * Nothing is invented. A product nobody has packed yet offers nothing,
 * and the screen lets the first one be typed — which is then history,
 * so the list fills itself from real use rather than from somebody
 * maintaining it.
 *
 * These run against a fake database rather than the source, because
 * "does not offer King to a towel" is a question about rows, not words.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $n" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }
function nocomments(string $s): string {
    $out = '';
    foreach (token_get_all($s) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out .= ' '; continue; }
            $out .= $t[1];
        } else { $out .= $t; }
    }
    return (string)preg_replace_callback('~<(script|style)\b[^>]*>.*?</\1>~is', function ($m) {
        return (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], ' ', $m[0]);
    }, $out);
}

/* ------------------------------------------------------- the fake rows
   Two products. One is costed and has been packed; one has never been
   touched. A third product's history exists purely so it can be shown
   not to leak into the first one's list. */
$DB = [
    'products' => [
        ['id' => 7,  'name' => 'Duvet Cover Set King'],
        ['id' => 9,  'name' => 'Bath Towel 500 gsm'],     /* spelled with a space */
        ['id' => 11, 'name' => 'Brand New Thing'],
    ],
    'product_sizes' => [
        7 => ['Single', 'Double', 'King'],
        /* The towel IS costed, but the master spells it "500 gsm" while
           the invoice line says "500GSM". Finding these is the whole
           job of the normalised fallback. */
        9 => ['70x140', '90x180'],
        11 => [],
    ],
    /* packing history, keyed the way the table is: by product_key */
    'history' => [
        'duvet cover set king' => ['size_label' => ['King', 'Super King'],
                                   'colour_label' => ['White', 'Navy']],
        'bath towel 500 gsm'   => ['size_label' => ['70x140'],
                                   'colour_label' => ['Ivory']],
    ],
];
$GLOBALS['DB'] = $DB;
$GLOBALS['SEEN'] = [];          /* every query, so the scoping can be proved */

final class LStmt {
    private array $rows = [];
    public function __construct(private string $sql) {}
    public function execute(array $a = []): bool {
        $GLOBALS['SEEN'][] = [preg_replace('/\s+/', ' ', $this->sql), $a];
        $this->rows = LPdo::route($this->sql, $a);
        return true;
    }
    public function fetchAll(): array { return $this->rows; }
    public function fetch() { return $this->rows[0] ?? false; }
    public function fetchColumn() { $r = $this->rows[0] ?? null; return $r === null ? false : reset($r); }
}
final class LPdo {
    public function prepare(string $s) { return new LStmt($s); }
    public function query(string $s) { $st = new LStmt($s); $st->execute(); return $st; }
    public function exec(string $s) { return 0; }
    public static function route(string $sql, array $a): array {
        $D = $GLOBALS['DB'];
        $q = preg_replace('/\s+/', ' ', $sql);

        /* the master list, by exact name */
        if (str_contains($q, 'FROM product_sizes ps JOIN products p') && str_contains($q, 'p.name = ?')) {
            foreach ($D['products'] as $p) {
                if ($p['name'] === ($a[0] ?? null)) {
                    return array_map(fn($s) => ['size_label' => $s], $D['product_sizes'][$p['id']] ?? []);
                }
            }
            return [];
        }
        /* every product, for the normalised second try */
        if (str_contains($q, 'SELECT id, name FROM products')) return $D['products'];

        if (str_contains($q, 'FROM product_sizes WHERE product_id IN')) {
            $out = [];
            foreach ($a as $id) foreach ($D['product_sizes'][(int)$id] ?? [] as $s) $out[] = ['size_label' => $s];
            return $out;
        }
        /* history, scoped by product_key */
        if (str_contains($q, 'FROM packing_group_sizes gs JOIN packing_groups g')) {
            $col = str_contains($q, 'gs.colour_label v') ? 'colour_label' : 'size_label';
            $h = $D['history'][$a[0] ?? ''] ?? null;
            if (!$h) return [];
            return array_map(fn($v) => ['v' => $v], $h[$col] ?? []);
        }
        return [];
    }
}
function db() { static $p = null; if ($p === null) $p = new LPdo(); return $p; }
function current_user(): ?array { return ['id' => 1]; }
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function is_admin(): bool { return true; }
function is_colleague(): bool { return false; }
function is_production_staff(): bool { return false; }
function assigned_shipment_ids(): array { return ['ALL']; }

require_once $B . 'includes/packing.php';

$pk  = (string)file_get_contents($B . 'includes/packing.php');
$pkN = nocomments($pk);
$mpN = nocomments((string)file_get_contents($B . 'm_pack.php'));

/* ============================================ 1. the product's own sizes */
head('1. The list is this product\'s, and nothing else');

$duvet = pack_size_options('Duvet Cover Set King');
t('the sizes it is costed in are offered',
  in_array('Single', $duvet, true) && in_array('Double', $duvet, true)
  && in_array('King', $duvet, true), $duvet);
t('and the ones it has actually been packed in',
  in_array('Super King', $duvet, true), $duvet);
t('King appears once, not twice, though both sources have it',
  count(array_filter($duvet, fn($v) => $v === 'King')) === 1, $duvet);
t('nothing is invented — four labels, not twelve',
  count($duvet) === 4, $duvet);

$towel = pack_size_options('Bath Towel 500GSM');     /* note: no space */
t('a towel is offered its own towel sizes',
  $towel === ['70x140', '90x180'], $towel);
t('and is NOT offered King, Queen, Small or Medium',
  !array_intersect($towel, ['King', 'Queen', 'Small', 'Medium', 'XLarge', 'Single', 'Double']),
  $towel);
/* The exact-name query cannot match "500GSM" against "500 gsm", so this
   only passes through the normalised fallback — which is the point. */
t('500GSM finds a master product spelled 500 gsm',
  pack_master_sizes('Bath Towel 500GSM') === ['70x140', '90x180'],
  pack_master_sizes('Bath Towel 500GSM'));
t('and the exact-name path is still tried first, for the common case',
  pack_master_sizes('Duvet Cover Set King') === ['Single', 'Double', 'King'],
  pack_master_sizes('Duvet Cover Set King'));

t('one product\'s history never leaks into another\'s',
  !in_array('70x140', $duvet, true) && !in_array('Super King', $towel, true),
  [$duvet, $towel]);

$fresh = pack_size_options('Brand New Thing');
t('a product nobody has packed offers nothing at all',
  $fresh === [], $fresh);
t('and an empty name asks the database nothing',
  pack_size_options('') === []);

head('2. Colours are history alone — nobody costs a product per colour');

t('the colours this product was packed in come back',
  pack_colour_options('Duvet Cover Set King') === ['White', 'Navy'],
  pack_colour_options('Duvet Cover Set King'));
t('another product gets its own',
  pack_colour_options('Bath Towel 500 gsm') === ['Ivory'],
  pack_colour_options('Bath Towel 500 gsm'));
t('a new product gets none, rather than a list of invented colours',
  pack_colour_options('Brand New Thing') === []);
t('and no master table is consulted for colour',
  !preg_match('~function pack_colour_options.*?product_sizes~s', $pkN),
  'colour is being read from a master list that does not exist');

head('3. History is asked for by key, not by scanning');

$hist = array_values(array_filter($GLOBALS['SEEN'],
    fn($r) => str_contains($r[0], 'FROM packing_group_sizes gs JOIN packing_groups g')));
t('the history query was used', count($hist) > 0, count($hist));
t('it is scoped by product_key every time',
  count(array_filter($hist, fn($r) => str_contains($r[0], 'g.product_key = ?'))) === count($hist),
  $hist[0][0] ?? null);
t('and the key it is given is the normalised name',
  ($hist[0][1][0] ?? '') === pack_product_key('Duvet Cover Set King'),
  $hist[0][1] ?? null);
t('blanks are never offered as a choice',
  str_contains($pkN, "gs.\$column <> ''"));
t('the old global pool is gone',
  !preg_match('~SELECT DISTINCT size_label FROM packing_group_sizes\s+WHERE~', $pkN),
  'every size used anywhere is still being offered');
t('and so is the invented list',
  !preg_match("~'Small',\s*'Medium',\s*'Large'~", $pkN),
  'the made-up size list is still there');

head('4. The key that makes history possible is written down');

t('packing_groups carries a product_key',
  str_contains($pkN, "ALTER TABLE packing_groups ADD COLUMN product_key"));
t('it is indexed, because every list on the screen reads it',
  str_contains($pkN, 'ADD INDEX idx_pg_prod (product_key)'));
/* Scoped to the statement. A loose .*? across the whole file matched a
   pack_product_key() call fifty lines further down in another function,
   so deleting the one in the INSERT changed nothing and the assertion
   passed anyway. Cut the statement out and look inside it. */
$between = function (string $src, string $from, string $to): string {
    $a = strpos($src, $from);
    if ($a === false) return '';
    $b = strpos($src, $to, $a);
    return $b === false ? '' : substr($src, $a, $b - $a + strlen($to));
};
$ins = $between($pkN, 'INSERT INTO packing_groups', ']);');
$upd = $between($pkN, 'UPDATE packing_groups SET invoice_item_id', ']);');
t('a new range writes it — in the column list and in the values',
  $ins !== '' && str_contains($ins, 'product_key') && str_contains($ins, 'pack_product_key('),
  $ins === '' ? 'no INSERT found' : substr($ins, 0, 160));
t('an edited range writes it too',
  $upd !== '' && str_contains($upd, 'product_key=?') && str_contains($upd, 'pack_product_key('),
  $upd === '' ? 'no UPDATE found' : substr($upd, 0, 160));
/* A column list and a VALUES list that disagree is a fatal the moment
   somebody saves a range, and this is the cheap place to catch it.
   NOW() takes a column without taking a placeholder, so it is counted
   on its own rather than fudged. */
$group = function (string $src, int $from): string {
    $o = strpos($src, '(', $from);
    if ($o === false) return '';
    $d = 0;
    for ($i = $o, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '(') $d++;
        elseif ($src[$i] === ')') { $d--; if ($d === 0) return substr($src, $o + 1, $i - $o - 1); }
    }
    return '';
};
$colList = $group($ins, strpos($ins, 'packing_groups'));
$valList = $group($ins, strpos($ins, 'VALUES'));
$cols = $colList === '' ? 0 : count(array_filter(array_map('trim', explode(',', $colList)), 'strlen'));
$ph   = substr_count($valList, '?');
$now  = substr_count($valList, 'NOW()');
t('the INSERT binds one value per column',
  $cols > 0 && $cols === $ph + $now, ['columns' => $cols, 'placeholders' => $ph, 'NOW()' => $now]);

t('ranges saved before the column existed are filled in once',
  str_contains($pkN, "WHERE product_key = ''")
  && str_contains($pkN, 'UPDATE packing_groups SET product_key = ? WHERE id = ?'),
  'old ranges would have no history until they were next saved');
t('colour has a column beside size, in all three places',
  substr_count($pkN, "ADD COLUMN colour_label VARCHAR(80)") === 3,
  substr_count($pkN, "ADD COLUMN colour_label VARCHAR(80)"));
/* Pinning the exact number meant every later change broke a test that
   was not about that change. What matters is that the marker moved past
   the version these columns arrived in — otherwise an existing database
   skips the whole build and the columns are simply not there. */
t('the schema version is past the one these columns arrived in',
  (int)PACK_SCHEMA_VERSION >= 2, PACK_SCHEMA_VERSION);
t('and the guard still reads it before doing any work',
  preg_match("~SELECT v FROM exp_meta WHERE k='pack_schema_version'.*?=== PACK_SCHEMA_VERSION\) return;~s", $pkN) === 1,
  'the schema would be rebuilt on every page load');

head('5. The first one can always be typed');

t('the field offers to take a new one',
  str_contains($mpN, 'type a ') && str_contains($mpN, '__new'));
t('a product with nothing on record opens on the text box',
  str_contains($mpN, "— none on record yet —")
  && str_contains($mpN, '$known ? \' hidden\' : \'\''),
  'a product with no sizes would show an empty dropdown and no way forward');
t('a size already saved but no longer in the list still shows as chosen',
  str_contains($mpN, 'array_unshift($opts, $cur)'),
  'editing a range would silently blank its size');
t('the server reads the typed one when the sentinel is sent',
  str_contains($mpN, "if (\$one === '__new') \$one = trim((string)(\$_POST['single_size_new'] ?? ''));"));
t('and does the same for every row of an assorted set',
  str_contains($mpN, "if (\$l === '__new') \$l = trim((string)(\$new[\$i] ?? ''));"));
t('__new is never stored as if it were a size',
  !preg_match("~'size_label' => '__new'~", $mpN));
/* THE ASSORTED SET HAS NO TYPED ROWS ANY MORE. It is tapped — a colour
   pad and a size pad — so there is no row to add and no text box to
   arrive open. What replaced that rule is that the pads are the only way
   in, which is checked here rather than described. */
t('the assorted set is tapped, not typed',
  str_contains($mpN, 'id="szPad"') && str_contains($mpN, 'id="colPad"'));
t('and has no select or input to type a size into',
  !preg_match('~<(input|select)[^>]*name="size_label\[\]"~',
              (string)preg_replace('~<script\b[^>]*>.*?</script>~is', ' ', $mpN)));
/* The single-size picker still has one, and it must still open only
   when the sentinel is chosen — that rule did not go anywhere. */
t('the one remaining typed picker opens only when "type a new one" is chosen',
  str_contains($mpN, "box.hidden = sel.value !== '__new';"));
t('and it is wired on the document, so a card drawn later is covered too',
  str_contains($mpN, "if (!sel.matches || !sel.matches('[data-pick]')) return;"));

echo "\n" . ($F ? "FAILED  $F" : 'ALL PASS') . "   ($P checks)\n";
exit($F ? 1 : 0);
