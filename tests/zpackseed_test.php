<?php
/* ASKED FOR ONCE, NOT EVERY TIME.
 *
 * "dont ask again and again if u have weight information by size or
 *  color already so calculated yourself and dont ask it serial changing
 *  and making more and more so only ask weight list for always new
 *  product line"
 *
 * A weight belongs to the product and the size. It does not belong to a
 * serial range — splitting 1–100 into 1–99 and a short carton 100 is one
 * run of cartons described twice, and being asked to weigh the same
 * duvet again because the serial changed is the app failing to remember
 * what it was already told.
 *
 * So a range arrives with every size it already knows filled in, from
 * the nearest place that knows it, and the screen asks only about what
 * is genuinely new.
 *
 * This runs against a fake database that really writes, because every
 * question here is about what happens the second time.
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

/* ------------------------------------------------- a store that writes */
$T = [
    'groups' => [
        /* two ranges of ONE product in one shipment — 1–99 and the short
           carton 100, which is the case he described */
        101 => ['id'=>101,'shipment_id'=>1,'product_name'=>'Duvet Cover Set King',
                'product_key'=>'duvet cover set king','unit_title'=>'Carton',
                'serial_from'=>1,'serial_to'=>99,'qty_mode'=>'per','assorted'=>1,
                'pkg_gross'=>0,'pkg_tare'=>0,'weight_by_colour'=>0],
        102 => ['id'=>102,'shipment_id'=>1,'product_name'=>'Duvet Cover Set King',
                'product_key'=>'duvet cover set king','unit_title'=>'Carton',
                'serial_from'=>100,'serial_to'=>100,'qty_mode'=>'per','assorted'=>1,
                'pkg_gross'=>0,'pkg_tare'=>0,'weight_by_colour'=>0],
        /* a different product, same shipment — must never be borrowed from */
        103 => ['id'=>103,'shipment_id'=>1,'product_name'=>'Bath Towel 500 gsm',
                'product_key'=>'bath towel 500 gsm','unit_title'=>'Bale',
                'serial_from'=>200,'serial_to'=>210,'qty_mode'=>'per','assorted'=>0,
                'pkg_gross'=>0,'pkg_tare'=>0,'weight_by_colour'=>0],
        /* the same product in ANOTHER shipment — only the standard may cross */
        201 => ['id'=>201,'shipment_id'=>2,'product_name'=>'Duvet Cover Set King',
                'product_key'=>'duvet cover set king','unit_title'=>'Carton',
                'serial_from'=>1,'serial_to'=>50,'qty_mode'=>'per','assorted'=>0,
                'pkg_gross'=>0,'pkg_tare'=>0,'weight_by_colour'=>0],
    ],
    /* A row is a size AND a colour now. These ranges carry no colour,
       which is the ordinary case and must keep working untouched. */
    'sizes' => [
        101 => ['Single', 'Double'],
        102 => ['Single', 'Double', 'Queen'],   /* Queen is new to everybody */
        103 => ['70x140'],
        201 => ['Single'],
    ],
    'wlines' => [
        /* range 101 has been weighed on this screen already */
        '101|Single|' => [['w_type'=>'Fabric','w_name'=>'Fleece','grams'=>500],
                         ['w_type'=>'Accessories','w_name'=>'Label','grams'=>40]],
        /* another shipment's range, which must NOT be read directly */
        '201|Single|' => [['w_type'=>'Fabric','w_name'=>'Old fleece','grams'=>999]],
    ],
    'std' => [
        /* Double was standardised on some earlier shipment */
        'duvet cover set king|Double|' => [['w_type'=>'Fabric','w_name'=>'Fleece wide','grams'=>700]],
    ],
];
$GLOBALS['T'] = $T;
$GLOBALS['DEPTH'] = 0;

final class SStmt {
    private array $rows = [];
    public function __construct(private string $sql) {}
    public function execute(array $a = []): bool { $this->rows = SPdo::route($this->sql, $a); return true; }
    public function fetchAll(): array { return $this->rows; }
    public function fetch() { return $this->rows[0] ?? false; }
    public function fetchColumn() { $r = $this->rows[0] ?? null; return $r === null ? false : reset($r); }
}
final class SPdo {
    public function prepare(string $s) { return new SStmt($s); }
    public function query(string $s) { $st = new SStmt($s); $st->execute(); return $st; }
    public function exec(string $s) { return 0; }
    public function lastInsertId() { return '999'; }
    /* A nested begin is a real PDO error, so the fake makes it one. That
       is how "the seeding runs after the commit, not inside it" is
       actually proved rather than described. */
    public function beginTransaction() {
        if ($GLOBALS['DEPTH'] > 0) throw new RuntimeException('nested transaction');
        $GLOBALS['DEPTH']++; return true;
    }
    public function commit()   { $GLOBALS['DEPTH'] = max(0, $GLOBALS['DEPTH'] - 1); return true; }
    public function rollBack() { $GLOBALS['DEPTH'] = max(0, $GLOBALS['DEPTH'] - 1); return true; }
    public function inTransaction() { return $GLOBALS['DEPTH'] > 0; }

    public static function route(string $sql, array $a): array {
        $q = preg_replace('/\s+/', ' ', $sql);
        $T = &$GLOBALS['T'];

        if (str_contains($q, 'FROM packing_groups WHERE id=?')) {
            $g = $T['groups'][(int)($a[0] ?? 0)] ?? null;
            return $g ? [$g] : [];
        }
        if (str_contains($q, 'FROM packing_group_sizes WHERE group_id=?')) {
            return array_map(fn($l) => ['size_label' => $l, 'colour_label' => '',
                                        'qty_per_pkg' => 1, 'total_qty' => 1],
                             $T['sizes'][(int)($a[0] ?? 0)] ?? []);
        }
        /* SELECT only. "DELETE FROM packing_weight_lines WHERE group_id=?
           AND size_label=?" contains the very same words, so a loose
           match sent every delete down the read path and nothing was
           ever removed — which looked exactly like the app refusing to
           overwrite. Match the verb. */
        if (str_starts_with($q, 'SELECT * FROM packing_weight_lines')) {
            $k = ((int)($a[0] ?? 0)) . '|' . ($a[1] ?? '') . '|' . ($a[2] ?? '');
            return array_map(fn($r, $i) => $r + ['line_no' => $i + 1],
                             $T['wlines'][$k] ?? [], array_keys($T['wlines'][$k] ?? []));
        }
        /* the cross-range look, inside one shipment.

           THE FAKE CHECKS THE QUERY, not just the parameters. It used to
           re-implement the filtering in PHP, so loosening the real SQL —
           dropping the shipment or the product from the WHERE — changed
           nothing here and the mutation went unnoticed. A fake that
           answers a question the app did not ask is worse than no fake. */
        if (str_contains($q, 'FROM packing_weight_lines wl JOIN packing_groups g2')) {
            foreach (['g2.shipment_id = ?', 'g2.id <> ?', 'g2.product_key = ?',
                      'wl.size_label = ?', 'wl.colour_label = ?'] as $need) {
                if (!str_contains($q, $need)) { $GLOBALS['T']['badsql'][] = $need; return []; }
            }
            [$ship, $self, $key, $size, $col] =
                [(int)$a[0], (int)$a[1], (string)$a[2], (string)$a[3], (string)($a[4] ?? '')];
            $best = [];
            foreach ($T['wlines'] as $k => $rows) {
                [$gid, $sz, $cl] = array_pad(explode('|', $k), 3, '');
                $g = $T['groups'][(int)$gid] ?? null;
                if (!$g || (int)$g['shipment_id'] !== $ship || (int)$gid === $self) continue;
                if ((string)$g['product_key'] !== $key || $sz !== $size || $cl !== $col) continue;
                $best = $rows;
            }
            return $best;
        }
        if (str_starts_with($q, 'SELECT w_type, w_name, grams FROM packing_weight_std')) {
            return $T['std'][($a[0] ?? '') . '|' . ($a[1] ?? '') . '|' . ($a[2] ?? '')] ?? [];
        }
        if (str_contains($q, 'DELETE FROM packing_weight_lines')) {
            unset($T['wlines'][((int)$a[0]) . '|' . $a[1] . '|' . ($a[2] ?? '')]); return [];
        }
        if (str_contains($q, 'INSERT INTO packing_weight_lines')) {
            $k = ((int)$a[0]) . '|' . $a[1] . '|' . ($a[2] ?? '');
            $T['wlines'][$k] = $T['wlines'][$k] ?? [];
            $T['wlines'][$k][] = ['w_type' => $a[4], 'w_name' => $a[5], 'grams' => (float)$a[6]];
            return [];
        }
        if (str_contains($q, 'DELETE FROM packing_weight_std')) {
            unset($T['std'][($a[0] ?? '') . '|' . ($a[1] ?? '') . '|' . ($a[2] ?? '')]); return [];
        }
        if (str_contains($q, 'INSERT INTO packing_weight_std')) {
            $k = $a[0] . '|' . $a[1] . '|' . $a[2];
            $T['std'][$k] = $T['std'][$k] ?? [];
            $T['std'][$k][] = ['w_type' => $a[4], 'w_name' => $a[5], 'grams' => (float)$a[6]];
            return [];
        }
        return [];
    }
}
function db() { static $p = null; if ($p === null) $p = new SPdo(); return $p; }
function current_user(): ?array { return ['id' => 1]; }
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function is_admin(): bool { return true; }
function is_colleague(): bool { return false; }
function is_production_staff(): bool { return false; }
function assigned_shipment_ids(): array { return ['ALL']; }

require_once $B . 'includes/packing.php';

/* int 700 from a fixture and float 700.0 from an INSERT are the same
   weight. Comparing them with === asked a question about PHP types,
   not about the app. */
function same(float $a, float $b): bool { return abs($a - $b) < 0.0001; }

$grams = function (int $gid, string $size, string $colour = ''): float {
    return array_sum(array_column(pack_weight_lines($gid, $size, $colour), 'grams'));
};

/* ============================================= 1. the short last carton */
head('1. Carton 100 is not a new question');

t('before saving, carton 100 knows nothing',
  $grams(102, 'Single') === 0.0 && $grams(102, 'Double') === 0.0);

$filled = pack_weight_seed(102);

t('Single comes straight from carton 1–99, in the same shipment',
  same($grams(102, 'Single'), 540), $grams(102, 'Single'));
t('Double comes from the saved standard, from an earlier shipment',
  same($grams(102, 'Double'), 700), $grams(102, 'Double'));
t('Queen, which nobody has ever weighed, is left empty',
  same($grams(102, 'Queen'), 0), $grams(102, 'Queen'));
t('and it reports the two it filled',
  $filled === 2, $filled);
/* Seeding copies what is already known; it decides nothing. If it also
   wrote the standard, the 540 g it copied from the carton next door
   would become the figure for this product everywhere — a guess
   promoted to a rule without anybody agreeing to it. Nothing had
   standardised Single before this, so the standard must still be empty. */
t('and seeding wrote no standard of its own',
  pack_std_get('Duvet Cover Set King', 'Single') === [],
  pack_std_get('Duvet Cover Set King', 'Single'));
t('so the screen asks about Queen and nothing else',
  pack_weight_missing(102) === ['Queen'], pack_weight_missing(102));
t('while the range it copied from is untouched',
  same($grams(101, 'Single'), 540), $grams(101, 'Single'));

head('2. It copies from the nearest place that knows, and no further');

t('the cross-range query asks for one shipment, one product and one size',
  ($GLOBALS['T']['badsql'] ?? []) === [], $GLOBALS['T']['badsql'] ?? []);
t('another product in the same shipment is never borrowed from',
  pack_weight_seed(103) === 0 && same($grams(103, '70x140'), 0),
  $grams(103, '70x140'));
t('and another shipment\'s range is not read directly — only its standard is',
  !same($grams(102, 'Single'), 999),
  'the 999 g line from shipment 2 was copied across');

head('3. Seeding never overwrites, and never asks twice');

/* Someone corrects carton 100's Single by hand. */
pack_weight_save(102, 'Single', [['w_type' => 'Fabric', 'w_name' => 'Fleece', 'grams' => 610]]);
t('a hand-typed figure sticks', same($grams(102, 'Single'), 610), $grams(102, 'Single'));

$again = pack_weight_seed(102);
t('seeding again leaves it alone', same($grams(102, 'Single'), 610), $grams(102, 'Single'));
t('and fills nothing, because nothing is missing but Queen',
  $again === 0, $again);
t('Queen is still the only thing asked for',
  pack_weight_missing(102) === ['Queen']);

head('4. A weight used is a weight remembered — without waiting for approval');

t('saving by hand wrote the standard there and then',
  same(array_sum(array_column(pack_std_get('Duvet Cover Set King', 'Single'), 'grams')), 610),
  pack_std_get('Duvet Cover Set King', 'Single'));
t('so a brand new range of the same product opens filled in',
  (function () use ($grams) {
      $GLOBALS['T']['groups'][104] = $GLOBALS['T']['groups'][102];
      $GLOBALS['T']['groups'][104]['id'] = 104;
      $GLOBALS['T']['groups'][104]['shipment_id'] = 9;      /* a different shipment */
      $GLOBALS['T']['sizes'][104] = ['Single'];
      pack_weight_seed(104);
      return same($grams(104, 'Single'), 610);
  })(), 'a new shipment still asks for a weight it already knows');

t('seeding itself does NOT rewrite the standard',
  (function () {
      $GLOBALS['T']['std']['duvet cover set king|Double|'] =
          [['w_type' => 'Fabric', 'w_name' => 'Fleece wide', 'grams' => 700]];
      $GLOBALS['T']['groups'][105] = $GLOBALS['T']['groups'][102];
      $GLOBALS['T']['groups'][105]['id'] = 105;
      $GLOBALS['T']['groups'][105]['shipment_id'] = 11;
      $GLOBALS['T']['sizes'][105] = ['Double'];
      pack_weight_seed(105);
      return same(array_sum(array_column(pack_std_get('Duvet Cover Set King', 'Double'), 'grams')), 700);
  })(), 'copying a standard onto a range counted as news and rewrote it');

head('5. A colour nobody weighed falls back to the size');

pack_std_save('Duvet Cover Set King', 'Single', [['w_type' => 'Fabric', 'w_name' => 'F', 'grams' => 610]]);
t('asking for a colour that has no figures of its own gives the size\'s',
  same(array_sum(array_column(pack_std_get('Duvet Cover Set King', 'Single', 'Navy'), 'grams')), 610),
  pack_std_get('Duvet Cover Set King', 'Single', 'Navy'));
pack_std_save('Duvet Cover Set King', 'Single', [['w_type' => 'Fabric', 'w_name' => 'F', 'grams' => 880]], 'Navy');
t('and once that colour really is different, it wins',
  same(array_sum(array_column(pack_std_get('Duvet Cover Set King', 'Single', 'Navy'), 'grams')), 880),
  pack_std_get('Duvet Cover Set King', 'Single', 'Navy'));
t('without disturbing the plain size',
  same(array_sum(array_column(pack_std_get('Duvet Cover Set King', 'Single'), 'grams')), 610));

head('6. Where it is called from, and when');

$pkN = nocomments((string)file_get_contents($B . 'includes/packing.php'));
$mpN = nocomments((string)file_get_contents($B . 'm_pack.php'));

t('saving a range seeds it', str_contains($pkN, '$seeded = pack_weight_seed($groupId);'));
/* A nested begin throws in the fake above, so this is already proved at
   runtime — this keeps it from drifting back inside the transaction. */
/* Inside pack_group_save only. Across the whole file there is always
   another commit further down in another function, so an unscoped
   "is there a commit after the seed" was answered yes and the
   assertion could never fail — the same loose-.*? mistake as before. */
$body = (function (string $src): string {
    $a = strpos($src, 'function pack_group_save');
    if ($a === false) return '';
    $o = strpos($src, '{', $a); $d = 0;
    for ($i = $o, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $d++;
        elseif ($src[$i] === '}') { $d--; if ($d === 0) return substr($src, $a, $i - $a + 1); }
    }
    return '';
})($pkN);
t('the seed is inside pack_group_save', $body !== '' && str_contains($body, 'pack_weight_seed'));
t('after the commit, not inside the transaction',
  $body !== '' && strrpos($body, 'db()->commit();') < strpos($body, 'pack_weight_seed'),
  'a nested transaction would throw and the range would fail to save');
t('the depth counter came back to nothing, so nothing was left open',
  $GLOBALS['DEPTH'] === 0, $GLOBALS['DEPTH']);
/* STILL OPENS ON WHAT IS MISSING. What changed is only what counts as
   one thing to weigh: a size, or a size and a colour when the range says
   its colours differ. The list is therefore keyed by that unit, so the
   screen can land on it. */
t('the weight screen opens on what is missing, not on the first one',
  preg_match('~if \(!pack_weight_lines\(\$unitHome\[\$k\], \$u\[\x27size\x27\], \$u\[\x27colour\x27\]\)\) \$missing\[\$k\]~', $mpN) === 1
  && str_contains($mpN, "\$sz = (string)(array_key_first(\$missing) ?? (\$labels[0] ?? ''));"));
t('and what counts as one thing to weigh is decided in one place',
  str_contains($mpN, 'pack_unit_key($sg, $srow)') && str_contains($mpN, 'pack_wkey($sg, $srow)'));
t('and says so plainly when there is nothing to do',
  str_contains($mpN, 'Nothing new to weigh here.'));
t('the old per-size recall button is gone, because it is automatic now',
  !preg_match('~name="recall"~', $mpN));

echo "\n" . ($F ? "FAILED  $F" : 'ALL PASS') . "   ($P checks)\n";
exit($F ? 1 : 0);
