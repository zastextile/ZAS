<?php
/* COLOUR, AND WHO DECIDES IT.
 *
 * "dont think to match with proforma or invoice so packing list always
 *  some detail"  and  "that color will be part of that whole shipping
 *  process of that product as its vary customer to customer and order
 *  to orders so that basic info first time need enter by user"
 *
 * Colour is not on the invoice and is never checked against it. It is a
 * property of what is in the carton, like the quantity. It arrives by
 * email for one order and is different on the next, so it is typed once
 * per order — at a desk, where the email is — and the packing phone then
 * offers exactly that and nothing else.
 *
 * What this file holds shut:
 *   THE ORDER'S LIST IS THE ORDER'S. Saving one line never touches
 *   another line, another shipment, or another product.
 *   REPLACED, NOT MERGED. A colour the customer dropped has to vanish.
 *   THE OFFER IS THIS PRODUCT'S OWN. Costed sizes plus what this product
 *   has been packed in before. Never another product's, never a global
 *   pool of every colour the factory has ever used.
 *   ASKED ONCE. Four colours of one size is one weight breakdown, unless
 *   the colours genuinely weigh differently — and then it is four, kept
 *   apart, with the size's own breakdown as the starting point.
 *   NOTHING HERE IS COMPARED TO THE INVOICE.
 *
 * This runs against a fake database that really writes, because every
 * question here is about what is there afterwards.
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
    foreach (token_get_all($s) as $tk) {
        if (is_array($tk)) {
            if ($tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT) { $out .= ' '; continue; }
            $out .= $tk[1];
        } else { $out .= $tk; }
    }
    return (string)preg_replace_callback('~<(script|style)\b[^>]*>.*?</\1>~is', function ($m) {
        return (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], ' ', $m[0]);
    }, $out);
}

/* ---------------------------------------------- a store that writes ----- */
$T = [
    /* Two shipments of the same product, and a second product in the
       first shipment. Every "did it leak" question below needs all three
       to exist, or it proves nothing. */
    'groups' => [
        /* "MANY SERIAL RANGES, ONE ITEM": invoice line 31 packed as
           Carton 1–99, Carton 100 (with an extra size) and Roll 1–5 */
        301 => ['id'=>301,'shipment_id'=>3,'invoice_item_id'=>31,
                'product_name'=>'Bath Towel 500 gsm','product_key'=>'bath towel 500 gsm',
                'unit_title'=>'Carton','serial_from'=>1,'serial_to'=>99,
                'qty_mode'=>'per','qty_per_pkg'=>10,'total_qty'=>990,'assorted'=>0,
                'pkg_gross'=>0,'pkg_tare'=>0,'weight_by_colour'=>0],
        302 => ['id'=>302,'shipment_id'=>3,'invoice_item_id'=>31,
                'product_name'=>'Bath Towel 500 gsm','product_key'=>'bath towel 500 gsm',
                'unit_title'=>'Carton','serial_from'=>100,'serial_to'=>100,
                'qty_mode'=>'per','qty_per_pkg'=>12,'total_qty'=>12,'assorted'=>1,
                'pkg_gross'=>0,'pkg_tare'=>0,'weight_by_colour'=>0],
        303 => ['id'=>303,'shipment_id'=>3,'invoice_item_id'=>31,
                'product_name'=>'Bath Towel 500 gsm','product_key'=>'bath towel 500 gsm',
                'unit_title'=>'Roll','serial_from'=>1,'serial_to'=>5,
                'qty_mode'=>'per','qty_per_pkg'=>40,'total_qty'=>200,'assorted'=>0,
                'pkg_gross'=>0,'pkg_tare'=>0,'weight_by_colour'=>0],
        /* the SAME product on another line (a different colour on the
           invoice) — its weight must not move when line 31's does */
        304 => ['id'=>304,'shipment_id'=>3,'invoice_item_id'=>32,
                'product_name'=>'Bath Towel 500 gsm','product_key'=>'bath towel 500 gsm',
                'unit_title'=>'Carton','serial_from'=>200,'serial_to'=>210,
                'qty_mode'=>'per','qty_per_pkg'=>10,'total_qty'=>110,'assorted'=>0,
                'pkg_gross'=>0,'pkg_tare'=>0,'weight_by_colour'=>0],
        101 => ['id'=>101,'shipment_id'=>1,'invoice_item_id'=>11,
                'product_name'=>'Duvet Cover Set King','product_key'=>'duvet cover set king',
                'unit_title'=>'Carton','serial_from'=>1,'serial_to'=>100,
                'qty_mode'=>'per','qty_per_pkg'=>10,'total_qty'=>1000,'assorted'=>1,
                'pkg_gross'=>0,'pkg_tare'=>0,'weight_by_colour'=>0],
        102 => ['id'=>102,'shipment_id'=>1,'invoice_item_id'=>12,
                'product_name'=>'Bath Towel 500 gsm','product_key'=>'bath towel 500 gsm',
                'unit_title'=>'Bale','serial_from'=>101,'serial_to'=>110,
                'qty_mode'=>'per','qty_per_pkg'=>50,'total_qty'=>500,'assorted'=>0,
                'pkg_gross'=>0,'pkg_tare'=>0,'weight_by_colour'=>0],
        /* the same product, next order — where colour genuinely differs */
        201 => ['id'=>201,'shipment_id'=>2,'invoice_item_id'=>21,
                'product_name'=>'Duvet Cover Set King','product_key'=>'duvet cover set king',
                'unit_title'=>'Carton','serial_from'=>1,'serial_to'=>40,
                'qty_mode'=>'per','qty_per_pkg'=>10,'total_qty'=>400,'assorted'=>1,
                'pkg_gross'=>0,'pkg_tare'=>0,'weight_by_colour'=>1],
    ],
    /* size AND colour on every row */
    'sizes' => [
        301 => [['Single', '', 10]],
        302 => [['Single', '', 10], ['Double', '', 2]],
        303 => [['Single', '', 40]],
        304 => [['Single', '', 10]],
        101 => [['Single', 'White', 4], ['Single', 'Navy', 2], ['Double', 'White', 4]],
        102 => [['70x140', '', 50]],
        201 => [['Single', 'White', 6], ['Single', 'Navy', 4]],
    ],
    'palette' => [],            /* shipment|item|kind => [labels] */
    'wlines'  => [],            /* group|size|colour  => [lines]  */
    'std'     => [
        /* The product's standard by size, which is the usual case — the
           same cloth in any dye. */
        'duvet cover set king|Single|' => [['w_type'=>'Fabric','w_name'=>'Fleece','grams'=>480],
                                           ['w_type'=>'Accessories','w_name'=>'Label','grams'=>15]],
        /* AND ONE COLOUR THAT REALLY IS ITS OWN. Without this, the size
           and the colour lookup return the same rows and dropping the
           colour from the WHERE changes nothing anybody can see — which
           is exactly how that mutation first survived. */
        'duvet cover set king|Single|Navy' => [['w_type'=>'Fabric','w_name'=>'Navy heavy','grams'=>530],
                                               ['w_type'=>'Accessories','w_name'=>'Label','grams'=>15]],
    ],
    /* What the product is costed in. Keyed by the NAME, because that is
       what the app looks it up by — keying it by the normalised key made
       the lookup miss and the whole master list silently vanish. */
    'master'  => ['Duvet Cover Set King' => ['King', 'Single', 'Double']],
    'badsql'  => [],
    /* a carton typed by hand on the desktop, in shipment 3 */
    'desk'    => [['shipment_id' => 3, 'pack_unit_title' => 'Carton', 'carton_from' => 500, 'carton_to' => 510]],
];
$GLOBALS['T'] = $T;
$GLOBALS['DEPTH'] = 0;
$GLOBALS['INS'] = 0;            /* every INSERT, counted, so "replaced not
                                   merged" can be told from "written twice" */

final class CStmt {
    private array $rows = [];
    public function __construct(private string $sql) {}
    public function execute(array $a = []): bool { $this->rows = CPdo::route($this->sql, $a); return true; }
    public function fetchAll(): array { return $this->rows; }
    public function fetch() { return $this->rows[0] ?? false; }
    public function fetchColumn() { $r = $this->rows[0] ?? null; return $r === null ? false : reset($r); }
}
final class CPdo {
    public function prepare(string $s) { return new CStmt($s); }
    public function query(string $s) { $st = new CStmt($s); $st->execute(); return $st; }
    public function exec(string $s) { return 0; }
    public function lastInsertId() { return '999'; }
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

        /* ---- the order's palette.
           THE FAKE CHECKS THE QUERY. If the app stopped naming the
           shipment, the item or the kind in its WHERE, a fake that
           filtered in PHP would carry on answering the right thing and
           the leak would never show up here. */
        if (str_starts_with($q, 'DELETE FROM packing_palette')) {
            foreach (['shipment_id=?', 'invoice_item_id=?', 'kind=?'] as $need) {
                if (!str_contains($q, $need)) { $T['badsql'][] = 'delete palette: ' . $need; return []; }
            }
            unset($T['palette'][(int)$a[0] . '|' . (int)$a[1] . '|' . (string)$a[2]]);
            return [];
        }
        if (str_starts_with($q, 'INSERT INTO packing_palette')) {
            $GLOBALS['INS']++;
            $k = (int)$a[0] . '|' . (int)$a[1] . '|' . (string)$a[2];
            $T['palette'][$k] = $T['palette'][$k] ?? [];
            $T['palette'][$k][] = (string)$a[3];
            return [];
        }
        if (str_contains($q, 'FROM packing_palette')) {
            foreach (['shipment_id=?', 'invoice_item_id=?'] as $need) {
                if (!str_contains($q, $need)) { $T['badsql'][] = 'read palette: ' . $need; return []; }
            }
            $kinds = isset($a[2]) ? [(string)$a[2]] : ['size', 'colour'];
            $out = [];
            foreach ($kinds as $kind) {
                foreach ($T['palette'][(int)$a[0] . '|' . (int)$a[1] . '|' . $kind] ?? [] as $l) {
                    $out[] = ['kind' => $kind, 'label' => $l];
                }
            }
            return $out;
        }

        /* the package-number check: the fake answers with the range that
           owns the numbers, or throws on demand to prove a failed check
           refuses the save instead of waving it through */
        if (str_contains($q, 'SELECT unit_title, serial_from, serial_to FROM packing_groups')) {
            if (!empty($GLOBALS['BREAKCHECK'])) throw new RuntimeException('lost connection');
            /* THE KIND IS IN THE QUERY, or the fake would answer a
               question the app did not ask */
            if (!str_contains($q, 'LOWER(TRIM(unit_title))=?')) { $T['badsql'][] = 'serial: kind'; return []; }
            foreach ($T['groups'] as $g) {
                if ((int)$g['shipment_id'] !== (int)$a[0] || (int)$g['id'] === (int)$a[1]) continue;
                if (mb_strtolower(trim((string)$g['unit_title'])) !== (string)$a[4]) continue;
                if ((int)$g['serial_from'] <= (int)$a[2] && (int)$g['serial_to'] >= (int)$a[3]) return [$g];
            }
            return [];
        }
        /* rows typed by hand on the desktop packing list */
        if (str_contains($q, 'FROM packing_items') && str_contains($q, 'packing_group_id IS NULL')) {
            foreach ($T['desk'] ?? [] as $d) {
                if ((int)$d['shipment_id'] !== (int)$a[0]) continue;
                if (mb_strtolower(trim($d['pack_unit_title'] ?: 'Carton')) !== (string)$a[3]) continue;
                if ((int)$d['carton_from'] <= (int)$a[1] && (int)$d['carton_to'] >= (int)$a[2])
                    return [['unit_title' => $d['pack_unit_title'], 'serial_from' => $d['carton_from'],
                             'serial_to' => $d['carton_to']]];
            }
            return [];
        }
        /* every range of one invoice line */
        if (str_contains($q, 'FROM packing_groups WHERE shipment_id=? AND invoice_item_id=?')) {
            $out = [];
            foreach ($T['groups'] as $g) {
                if ((int)$g['shipment_id'] === (int)$a[0] && (int)$g['invoice_item_id'] === (int)$a[1]) $out[] = $g;
            }
            usort($out, fn($x, $y) => $x['serial_from'] <=> $y['serial_from'] ?: $x['id'] <=> $y['id']);
            return $out;
        }
        if (str_starts_with($q, 'UPDATE packing_groups SET pkg_tare=?')) {
            $id = (int)end($a);
            if (isset($T['groups'][$id])) $T['groups'][$id]['pkg_tare'] = (float)$a[0];
            return [];
        }
        if (str_starts_with($q, 'UPDATE packing_groups SET pkg_gross=?')) {
            if (isset($T['groups'][(int)$a[1]])) $T['groups'][(int)$a[1]]['pkg_gross'] = (float)$a[0];
            return [];
        }
        if (str_contains($q, 'FROM packing_groups WHERE id=?')) {
            $g = $T['groups'][(int)($a[0] ?? 0)] ?? null;
            return $g ? [$g] : [];
        }
        if (str_contains($q, 'FROM packing_group_sizes WHERE group_id=?')) {
            return array_map(fn($r) => ['size_label' => $r[0], 'colour_label' => $r[1],
                                        'qty_per_pkg' => $r[2], 'total_qty' => $r[2] * 100],
                             $T['sizes'][(int)($a[0] ?? 0)] ?? []);
        }
        /* what this product has been packed in before — size or colour */
        if (str_contains($q, 'FROM packing_group_sizes gs JOIN packing_groups g')) {
            if (!str_contains($q, 'g.product_key = ?')) {
                $T['badsql'][] = 'history: g.product_key = ?'; return [];
            }
            $col = str_contains($q, 'gs.colour_label v') ? 1 : 0;
            $out = [];
            foreach ($T['sizes'] as $gid => $rows) {
                $g = $T['groups'][$gid] ?? null;
                if (!$g || (string)$g['product_key'] !== (string)$a[0]) continue;
                foreach ($rows as $r) if ($r[$col] !== '') $out[$r[$col]] = ['v' => $r[$col]];
            }
            ksort($out);
            return array_values($out);
        }
        if (str_contains($q, 'FROM product_sizes ps JOIN products')) {
            $rows = $T['master'][(string)($a[0] ?? '')] ?? [];
            sort($rows);                     /* the real query says ORDER BY */
            return array_map(fn($s) => ['size_label' => $s], $rows);
        }

        /* ---- weights, filed by size and colour together */
        if (str_starts_with($q, 'SELECT * FROM packing_weight_lines')) {
            $k = (int)$a[0] . '|' . ($a[1] ?? '') . '|' . ($a[2] ?? '');
            return array_map(fn($r, $i) => $r + ['line_no' => $i + 1],
                             $T['wlines'][$k] ?? [], array_keys($T['wlines'][$k] ?? []));
        }
        if (str_contains($q, 'FROM packing_weight_lines wl JOIN packing_groups g2')) {
            foreach (['g2.shipment_id = ?', 'g2.id <> ?', 'g2.product_key = ?',
                      'wl.size_label = ?', 'wl.colour_label = ?'] as $need) {
                if (!str_contains($q, $need)) { $T['badsql'][] = 'seed: ' . $need; return []; }
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
        /* what one unit comes to, grouped the way the app groups it */
        if (str_contains($q, 'COALESCE(SUM(grams),0) g')) {
            foreach (['GROUP BY size_label, colour_label'] as $need) {
                if (!str_contains($q, $need)) { $T['badsql'][] = 'per unit: ' . $need; return []; }
            }
            $out = [];
            foreach ($T['wlines'] as $k => $rows) {
                [$gid, $sz, $cl] = array_pad(explode('|', $k), 3, '');
                if ((int)$gid !== (int)($a[0] ?? 0) || !$rows) continue;
                $out[] = ['size_label' => $sz, 'colour_label' => $cl,
                          'g' => array_sum(array_column($rows, 'grams'))];
            }
            return $out;
        }
        if (str_starts_with($q, 'SELECT w_type, w_name, grams FROM packing_weight_std')) {
            /* The clauses, not just the parameters. This route answered
               out of a three-part key no matter what the WHERE said, so
               dropping colour_label from the real query changed nothing
               here and the mutation survived. */
            foreach (['product_key=?', 'size_label=?', 'colour_label=?'] as $need) {
                if (!str_contains($q, $need)) { $T['badsql'][] = 'std: ' . $need; return []; }
            }
            return $T['std'][($a[0] ?? '') . '|' . ($a[1] ?? '') . '|' . ($a[2] ?? '')] ?? [];
        }
        /* The verb, not the words. "DELETE FROM packing_weight_lines
           WHERE group_id=? AND size_label=?" contains every word the
           SELECT does, and a loose match once sent every delete down the
           read path so nothing was ever removed. */
        if (str_contains($q, 'DELETE FROM packing_weight_lines')) {
            unset($T['wlines'][(int)$a[0] . '|' . ($a[1] ?? '') . '|' . ($a[2] ?? '')]); return [];
        }
        if (str_contains($q, 'INSERT INTO packing_weight_lines')) {
            $k = (int)$a[0] . '|' . ($a[1] ?? '') . '|' . ($a[2] ?? '');
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
function db() { static $p = null; if ($p === null) $p = new CPdo(); return $p; }
function current_user(): ?array { return ['id' => 7]; }
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function is_admin(): bool { return true; }
function is_colleague(): bool { return false; }
function is_production_staff(): bool { return false; }
function assigned_shipment_ids(): array { return ['ALL']; }

require_once $B . 'includes/packing.php';

function names(array $lines): array { return array_column($lines, 'w_name'); }

/* ============================================ 1. the order's own list */
head("1. The order's list is typed once, and it belongs to that order");

pack_palette_save(1, 11, 'colour', ['White', 'Navy']);
pack_palette_save(1, 11, 'size',   ['Single', 'Double']);

$pal = pack_palette(1, 11);
t('the two colours come back in the order they were typed',
  $pal['colour'] === ['White', 'Navy'], $pal['colour']);
t('and the two sizes with them', $pal['size'] === ['Single', 'Double'], $pal['size']);
t('asking for one kind gives only that kind',
  pack_palette(1, 11, 'colour') === ['White', 'Navy'], pack_palette(1, 11, 'colour'));

/* The leak that matters: three neighbours, none of them touched. */
t('the other line of the same invoice is untouched',
  pack_palette(1, 12) === ['size' => [], 'colour' => []], pack_palette(1, 12));
t('the next order of the same product is untouched',
  pack_palette(2, 21) === ['size' => [], 'colour' => []], pack_palette(2, 21));
t('and an item number that happens to match in another shipment is not borrowed',
  pack_palette(2, 11) === ['size' => [], 'colour' => []], pack_palette(2, 11));

/* ---- replaced, not merged */
pack_palette_save(1, 11, 'colour', ['White', 'Grey']);
t('a colour the customer dropped is gone, not merged in',
  pack_palette(1, 11, 'colour') === ['White', 'Grey'], pack_palette(1, 11, 'colour'));
t('and saving a colour did not disturb the sizes',
  pack_palette(1, 11, 'size') === ['Single', 'Double'], pack_palette(1, 11, 'size'));

$GLOBALS['INS'] = 0;
pack_palette_save(1, 11, 'colour', ['White', 'white', ' WHITE ', 'Navy', '', '   ']);
t('the same colour typed three ways is stored once',
  pack_palette(1, 11, 'colour') === ['White', 'Navy'], pack_palette(1, 11, 'colour'));
t('and only two rows were ever written', $GLOBALS['INS'] === 2, $GLOBALS['INS']);

[$okk, $msg] = pack_palette_save(1, 11, 'flavour', ['Vanilla']);
t('a kind that is not a size or a colour is refused', $okk === false, [$okk, $msg]);

t('clearing a line empties it rather than leaving the old list behind',
  (function () { pack_palette_save(1, 12, 'colour', ['Temp']);
                 pack_palette_save(1, 12, 'colour', []);
                 return pack_palette(1, 12, 'colour'); })() === [], pack_palette(1, 12, 'colour'));

t('and the app named the shipment, the item and the kind in every statement',
  $GLOBALS['T']['badsql'] === [], $GLOBALS['T']['badsql']);

/* ============================================= 2. what is offered */
head("2. What is offered is this product's own — nothing generic");

$ch = pack_palette_choices('Duvet Cover Set King', 1, 11);
t('the costed sizes are offered', in_array('King', $ch['size'], true), $ch['size']);
t('together with a size this product has been packed in',
  in_array('Single', $ch['size'], true), $ch['size']);
t('the other product\'s size is not offered',
  !in_array('70x140', $ch['size'], true), $ch['size']);
t('the colours are the ones this product has been packed in',
  $ch['colour'] === ['Navy', 'White'], $ch['colour']);
t('a size is listed once even when it is both costed and packed before',
  count(array_filter($ch['size'], fn($s) => strcasecmp($s, 'Single') === 0)) === 1, $ch['size']);

$ch2 = pack_palette_choices('Bath Towel 500 gsm', 1, 12);
t('a product with no colour history offers no colour at all, rather than somebody else\'s',
  $ch2['colour'] === [], $ch2['colour']);

/* A product nobody has ever packed must not fall back to a pool. */
/* On a line with no palette either — asking item 11 would hand back the
   duvet's list and prove nothing about the product. */
$ch3 = pack_palette_choices('Beach Towel Jacquard', 2, 21);
t('a brand new product offers nothing it was never given',
  $ch3['colour'] === [] && $ch3['size'] === [], [$ch3['size'], $ch3['colour']]);

/* ===================================== 3. asked once, unless it matters */
head('3. The weight is asked once per size — four colours of it do not ask four times');

$n = pack_weight_seed(101);
/* ONE, not two. Single has a standard; Double has never been weighed
   anywhere, so it is genuinely new and the screen must still ask. A
   seed that filled it would be inventing a weight. */
t('the size that is already known fills itself', $n === 1, $n);
t('Single is filled', names(pack_weight_lines(101, 'Single')) === ['Fleece', 'Label'],
  names(pack_weight_lines(101, 'Single')));
t('and it is filed under no colour, because colour does not change it here',
  pack_weight_lines(101, 'Single', 'White') === [] && pack_weight_lines(101, 'Single') !== [],
  names(pack_weight_lines(101, 'Single', 'White')));
t('and the one nobody has ever weighed is what is still asked for',
  pack_weight_missing(101) === ['Double'], pack_weight_missing(101));
t('with no colour in that question, because colour does not change it here',
  !str_contains(implode('', pack_weight_missing(101)), '·'), pack_weight_missing(101));
t('and one unit is keyed by size alone',
  array_keys(pack_per_unit(101)) === ['Single'], array_keys(pack_per_unit(101)));

/* ---- and now the order where the colours really do differ */
head('3b. When the colours genuinely weigh differently, they are kept apart');

$n2 = pack_weight_seed(201);
t('both colours of the one size are filled, not one of them', $n2 === 2, $n2);
t('White is filed under White',
  names(pack_weight_lines(201, 'Single', 'White')) === ['Fleece', 'Label'],
  names(pack_weight_lines(201, 'Single', 'White')));
t('Navy under Navy, from Navy\'s own standard and not the size\'s',
  names(pack_weight_lines(201, 'Single', 'Navy')) === ['Navy heavy', 'Label'],
  names(pack_weight_lines(201, 'Single', 'Navy')));
t('and White, which has no standard of its own, falls back to the size\'s',
  names(pack_weight_lines(201, 'Single', 'White')) === ['Fleece', 'Label'],
  names(pack_weight_lines(201, 'Single', 'White')));
t('and nothing was filed with no colour at all',
  pack_weight_lines(201, 'Single') === [], names(pack_weight_lines(201, 'Single')));

/* changing one colour must not change the other — that is the whole
   point of switching the flag on */
pack_weight_save(201, 'Single', [['w_type'=>'Fabric','w_name'=>'Navy fleece','grams'=>540]],
                 false, 'Navy');
t('a change to Navy stays on Navy',
  names(pack_weight_lines(201, 'Single', 'Navy')) === ['Navy fleece'],
  names(pack_weight_lines(201, 'Single', 'Navy')));
t('and White keeps what it had',
  names(pack_weight_lines(201, 'Single', 'White')) === ['Fleece', 'Label'],
  names(pack_weight_lines(201, 'Single', 'White')));
/* Remembered for next time, under the colour it was weighed in — so the
   next order of the same product in the same colour arrives filled. */
pack_weight_save(201, 'Single', [['w_type'=>'Fabric','w_name'=>'Navy 2025','grams'=>545]],
                 true, 'Navy');
t('a remembered breakdown becomes that colour\'s standard',
  names(pack_std_get('Duvet Cover Set King', 'Single', 'Navy')) === ['Navy 2025'],
  names(pack_std_get('Duvet Cover Set King', 'Single', 'Navy')));
t('and the size\'s own standard is left alone, for every other colour',
  names(pack_std_get('Duvet Cover Set King', 'Single')) === ['Fleece', 'Label'],
  names(pack_std_get('Duvet Cover Set King', 'Single')));

t('one unit is keyed by size and colour together',
  array_keys(pack_per_unit(201)) === ['Single|White', 'Single|Navy'],
  array_keys(pack_per_unit(201)));

$GLOBALS['T']['wlines']['201|Single|Navy'] = [];
unset($GLOBALS['T']['wlines']['201|Single|Navy']);
t('an outstanding colour is named by its size and its colour',
  pack_weight_missing(201) === ['Single · Navy'], pack_weight_missing(201));

t('and the seed asked the right questions of the database',
  $GLOBALS['T']['badsql'] === [], $GLOBALS['T']['badsql']);

/* ===================================================== 4. how it reads */
head('4. How a carton reads on the page');

$g101 = pack_group(101);
t('an assorted carton reads as quantity, colour then size',
  pack_size_text($g101, pack_sizes(101)) === '4 White Single, 2 Navy Single, 4 White Double',
  pack_size_text($g101, pack_sizes(101)));
$g102 = pack_group(102);
t('a plain one reads as the size alone, with no empty colour in front of it',
  pack_size_text($g102, pack_sizes(102)) === '70x140',
  pack_size_text($g102, pack_sizes(102)));
t('a range with nothing in it reads as nothing', pack_size_text($g101, []) === '',
  pack_size_text($g101, []));

t('a known colour gets its own swatch',
  pack_colour_swatch('Navy') !== pack_colour_swatch('White'),
  [pack_colour_swatch('Navy'), pack_colour_swatch('White')]);
t('spelling and spacing do not matter',
  pack_colour_swatch('navy blue') === pack_colour_swatch('NavyBlue')
  && pack_colour_swatch('Navy') === pack_colour_swatch(' navy '),
  [pack_colour_swatch('navy blue'), pack_colour_swatch('NavyBlue')]);
t('and a colour nobody anticipated still gets a usable grey rather than nothing',
  preg_match('~^#[0-9a-f]{6}$~i', pack_colour_swatch('Pantone 18-1763')) === 1,
  pack_colour_swatch('Pantone 18-1763'));

/* ============================================ 5. against the source */
head('5. Against the source, not against this process');

$pk  = nocomments((string)file_get_contents($B . 'includes/packing.php'));
$pp  = nocomments((string)file_get_contents($B . 'pack_palette.php'));
$mpk = nocomments((string)file_get_contents($B . 'm_pack.php'));
/* The markup alone, with every <script> taken out. The script contains
   the string name="size_label[]" because that is what it builds the
   hidden rows from, and a search across the whole file finds that and
   reports a typed field that is not there. */
$mpkHtml = (string)preg_replace('~<script\b[^>]*>.*?</script>~is', ' ', $mpk);

/* Cut the function out and look inside it. A loose .*? across the whole
   file has twice now matched a call fifty lines away in some unrelated
   function and passed for nothing. */
function body(string $src, string $fn): string {
    if (!preg_match('~\nfunction ' . preg_quote($fn, '~') . '\s*\(.*?\n\}~s', $src, $m)) return '';
    return $m[0];
}

t('the phone never invents a colour — it reads the order\'s list',
  str_contains($mpk, 'pack_palette($id,'), null);
t('the desktop screen is the one that writes it',
  str_contains($pp, 'pack_palette_save('), null);
t('the phone does not write the order\'s list',
  !str_contains($mpk, 'pack_palette_save('), null);

t('the palette is replaced inside a transaction, so a half-saved list cannot survive',
  str_contains(body($pk, 'pack_palette_save'), 'beginTransaction')
  && str_contains(body($pk, 'pack_palette_save'), 'rollBack'), null);

t('the weight key is decided in one place',
  substr_count($pk, 'function pack_wkey') === 1, substr_count($pk, 'function pack_wkey'));
t('and both the seed and the outstanding list go through it',
  str_contains(body($pk, 'pack_weight_seed'), 'pack_wkey($g')
  && str_contains(body($pk, 'pack_weight_missing'), 'pack_wkey($g'), null);

t('nothing on the colour side compares anything to the invoice',
  !preg_match('~colour[^\n]*(invoice_qty|shipment_items)~i', $pk), null);

/* A WAY IN, AND THE REQUIRE THAT MAKES IT WORK. A link to a screen
   nobody can reach is no feature, and calling pack_palette() from a page
   that never required packing.php is not a missing strip — it is a fatal
   error on the live packing list, which is how this nearly shipped. */
$pl = nocomments((string)file_get_contents($B . 'packing_list.php'));
t('the packing list links to the screen that sets the order up',
  str_contains($pl, 'pack_palette.php?id='), null);
t('and requires the packing helpers it now calls',
  preg_match('~require_once[^\n]+includes/packing\.php~', $pl) === 1, null);
t('the strip that says whether it is set reads the order, not a guess',
  str_contains($pl, 'pack_palette((int)$id,'), null);

t('the desktop screen refuses production staff and checks the assignment',
  str_contains($pp, 'is_production_staff()') && str_contains($pp, 'assigned_shipment_ids()'), null);
t('and will not write once the list is closed',
  str_contains($pp, 'pack_may_edit(') && str_contains($pp, 'verify_csrf()'), null);

/* The pads are the only way the mix is built, so nothing may type into
   it — that was the whole reason for them. */
t('the assorted page has no typed size field left on it',
  !preg_match('~<(input|select)[^>]*name="size_label\[\]"~', $mpkHtml), null);
t('and its rows are built from the tally that is on screen',
  str_contains($mpk, "\$('rows').innerHTML"), null);

/* ============================================ 6. lines and numbers */
head('6. An invoice line is named so it cannot be mixed up, and no package number twice');

$l = pack_item_label(['line_no' => 2, 'product_name' => 'Thermal Blanket', 'des_col' => "White\n"]);
t('a line is named by its number, its product and what the line says',
  $l['text'] === '#2 Thermal Blanket — White', $l['text']);
t('in parts, so a screen can set the hint small',
  $l['no'] === '#2' && $l['name'] === 'Thermal Blanket' && $l['hint'] === 'White', $l);
$l2 = pack_item_label(['line_no' => 9, 'product_name' => 'Bath Towel', 'des_col' => '']);
t('a line with no description has no dangling dash', $l2['text'] === '#9 Bath Towel', $l2['text']);
$long = pack_item_label(['line_no' => 1, 'product_name' => 'X', 'des_col' => str_repeat('very long wording ', 6)]);
t('a long description is cut short so it fits a phone dropdown',
  mb_strlen($long['hint']) <= 40 && str_ends_with($long['hint'], '…'), $long['hint']);
$h = pack_item_html(['line_no' => 3, 'product_name' => 'Bath <Towel>', 'des_col' => 'Maroon']);
t('the HTML form escapes the name and sets the hint italic',
  $h === 'Bath &lt;Towel&gt; <i class="ihint">#3 · Maroon</i>', $h);

t('a range clear of every other is allowed', pack_serial_problem(1, 200, 205) === '',
  pack_serial_problem(1, 200, 205));
t('one that touches another\'s numbers, of the same kind, is refused',
  str_contains(pack_serial_problem(1, 100, 101), 'already used by Carton 1–100'),
  pack_serial_problem(1, 100, 101));
t('editing a range does not clash with itself', pack_serial_problem(1, 1, 100, 101) === '',
  pack_serial_problem(1, 1, 100, 101));
$GLOBALS['BREAKCHECK'] = true;
$fc = pack_serial_problem(1, 900, 905);
$GLOBALS['BREAKCHECK'] = false;
t('a check that cannot run refuses the save instead of letting a duplicate through',
  str_contains($fc, 'could not be checked'), $fc);

/* ======================================= 7. one item, one weight */
head('7. Weight once per invoice line, however many ranges it has');

/* "I need to upload weight only one time for each item in the invoice,
    even if they have many serial ranges, because the weight formula
    does not change." */
pack_weight_save(301, 'Single', [['w_type'=>'Fabric','w_name'=>'Terry','grams'=>450],
                                 ['w_type'=>'Accessories','w_name'=>'Label','grams'=>10]], false);
t('weighed once on Carton 1–99 …', names(pack_weight_lines(301, 'Single')) === ['Terry', 'Label'],
  names(pack_weight_lines(301, 'Single')));
t('… and Carton 100 has it without being asked', names(pack_weight_lines(302, 'Single')) === ['Terry', 'Label'],
  names(pack_weight_lines(302, 'Single')));
t('… and so does Roll 1–5, a different kind of package of the same line',
  names(pack_weight_lines(303, 'Single')) === ['Terry', 'Label'], names(pack_weight_lines(303, 'Single')));
t('the same product on ANOTHER invoice line is left alone — it is another item',
  pack_weight_lines(304, 'Single') === [], names(pack_weight_lines(304, 'Single')));

/* change it from any range of the line, and all of them follow */
pack_weight_save(303, 'Single', [['w_type'=>'Fabric','w_name'=>'Terry heavy','grams'=>470]], false);
t('a correction made on the Roll reaches the cartons too',
  names(pack_weight_lines(301, 'Single')) === ['Terry heavy'] && names(pack_weight_lines(302, 'Single')) === ['Terry heavy'],
  [names(pack_weight_lines(301, 'Single')), names(pack_weight_lines(302, 'Single'))]);
t('and still not the other line', pack_weight_lines(304, 'Single') === []);

/* the empty package: once per line AND kind */
pack_tare_save(301, 'carton ', 1.2);
t('the empty carton is set on every carton range of the line',
  $GLOBALS['T']['groups'][301]['pkg_tare'] == 1.2 && $GLOBALS['T']['groups'][302]['pkg_tare'] == 1.2,
  [$GLOBALS['T']['groups'][301]['pkg_tare'], $GLOBALS['T']['groups'][302]['pkg_tare']]);
t('but not on the roll — an empty roll core is a different thing',
  (float)$GLOBALS['T']['groups'][303]['pkg_tare'] === 0.0, $GLOBALS['T']['groups'][303]['pkg_tare']);
t('nor on another line\'s carton', (float)$GLOBALS['T']['groups'][304]['pkg_tare'] === 0.0);
pack_tare_save(303, 'Roll', 0.3);

/* GROSS IS WORKED OUT. Carton 1–99 holds 10 Singles at 470 g = 4.700 kg,
   plus the 1.2 kg carton. Nobody typed 5.9. */
pack_gross_refresh(301);
t('gross per carton is the contents plus the empty carton, worked out',
  abs((float)$GLOBALS['T']['groups'][301]['pkg_gross'] - 5.9) < 0.0001, $GLOBALS['T']['groups'][301]['pkg_gross']);
t('the roll is worked out from its own quantity: 40 x 470 g + 0.3 kg',
  abs((float)$GLOBALS['T']['groups'][303]['pkg_gross'] - 19.1) < 0.0001, $GLOBALS['T']['groups'][303]['pkg_gross']);
t('Carton 100 has a size nobody has weighed yet, and it is named as missing',
  pack_weight_missing(302) === ['Double'], pack_weight_missing(302));

/* a range added later is filled from its line, not asked — and from ITS
   line, not from another line of the same product, which here weighs
   something else. Without that difference the two sources give the same
   answer and the rule cannot be seen. */
pack_weight_save(304, 'Single', [['w_type'=>'Fabric','w_name'=>'Other line towel','grams'=>520]], false);
$GLOBALS['T']['groups'][305] = ['id'=>305,'shipment_id'=>3,'invoice_item_id'=>31,
    'product_name'=>'Bath Towel 500 gsm','product_key'=>'bath towel 500 gsm',
    'unit_title'=>'Carton','serial_from'=>101,'serial_to'=>120,
    'qty_mode'=>'per','qty_per_pkg'=>10,'total_qty'=>200,'assorted'=>0,
    'pkg_gross'=>0,'pkg_tare'=>0,'weight_by_colour'=>0];
$GLOBALS['T']['sizes'][305] = [['Single', '', 10]];
pack_weight_seed(305);
t('a new range of the line arrives with ITS line\'s weight, not the other line\'s',
  names(pack_weight_lines(305, 'Single')) === ['Terry heavy'], names(pack_weight_lines(305, 'Single')));
t('and the line\'s empty carton', (float)$GLOBALS['T']['groups'][305]['pkg_tare'] === 1.2,
  $GLOBALS['T']['groups'][305]['pkg_tare']);
t('and its gross worked out — nothing asked', abs((float)$GLOBALS['T']['groups'][305]['pkg_gross'] - 5.9) < 0.0001,
  $GLOBALS['T']['groups'][305]['pkg_gross']);

/* ========================================== 8. numbers per package kind */
head('8. A package number once per kind of package');

/* "Do not allow the same serial or the same carton number to repeat in
    the same shipment — but the same number can be used if the package
    type is different, like a roll." */
t('Carton 1–50 is refused where Carton 1–99 already is',
  str_contains(pack_serial_problem(3, 1, 50, 0, 'Carton'), 'already used by Carton 1–99'),
  pack_serial_problem(3, 1, 50, 0, 'Carton'));
t('but Bale 1–50 is fine beside it — a different kind of package',
  pack_serial_problem(3, 1, 50, 0, 'Bale') === '', pack_serial_problem(3, 1, 50, 0, 'Bale'));
t('Roll 3 is refused, because Roll 1–5 has it',
  str_contains(pack_serial_problem(3, 3, 3, 0, 'Roll'), 'Roll 1–5'), pack_serial_problem(3, 3, 3, 0, 'Roll'));
t('the kind is matched without case or spaces — " carton" is a Carton',
  pack_serial_problem(3, 5, 6, 0, ' carton') !== '', pack_serial_problem(3, 5, 6, 0, ' carton'));
t('a carton typed by hand on the desktop counts too',
  str_contains(pack_serial_problem(3, 505, 506, 0, 'Carton'), 'Carton (desktop) 500–510'),
  pack_serial_problem(3, 505, 506, 0, 'Carton'));
t('and the message says a different kind may reuse the number',
  str_contains(pack_serial_problem(3, 1, 2, 0, 'Carton'), 'different kind'));
t('the app named the kind in its query', !in_array('serial: kind', $GLOBALS['T']['badsql'], true),
  $GLOBALS['T']['badsql']);

echo "\n" . ($F === 0 ? "ALL PASS   ($P checks)\n" : "FAILED  $F   (" . ($P + $F) . " checks)\n");
exit($F === 0 ? 0 : 1);
