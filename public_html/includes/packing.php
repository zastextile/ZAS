<?php
/*
  PACKING — the serial-first model.

  WHAT CHANGED AND WHY.
  packing_items records a finished packing line: one carton range, one
  quantity, one weight. It is what the print needs and it stays exactly as
  it is. What it cannot hold is how the packing team arrived at those
  figures — which sizes went into a carton, and what the contents are made
  of. Those two things are what the phone screen collects, so they get
  tables of their own and packing_items is written from them at the end.

  THE SERIAL IS THE INPUT.
  Packing starts at a serial and runs to a serial. "Carton 1 to 100" means
  one hundred cartons — the count is derived, never typed, because a typed
  count can disagree with the serials and then nobody knows which is right.
  Everything else hangs off that: quantity per package multiplied by the
  count, or a direct total divided by it.

  QUANTITY IS PRIMARY.
  Nothing here is checked against the invoice. The invoice says what was
  sold; this says what is in the cartons, counted by the people who packed
  them. When the two differ it is the invoice that is wrong, and the
  office deals with that on the desktop. The old desktop guard that
  refused an over-packed line is untouched on its own screen; it simply
  has no say over what the packing team reports here.

  WEIGHT IS BUILT, NOT TYPED.
  Net and gross are not asked for. One package goes on the scale, the team
  lists what is inside one unit — fabric, fibre, bag, board, accessories —
  and the arithmetic produces the rest. The approver may overrule the
  final figures within ten per cent, because a scale and a sum never agree
  exactly and pretending otherwise just teaches people to fudge the lines.
*/

/* Bumped whenever the tables below change. One indexed read on a page load
   that finds the same number does nothing at all. */
const PACK_SCHEMA_VERSION = '3';

/* The material types a weight line can be. The team adds as many lines of
   any type as it needs — two fabrics, three fabrics, two accessories. */
/* His own words, and they fit the column: "Fabric. Fiber. Pvc.
   Accessories". The longer labels were being clipped in a dropdown that
   has to share a row with the grams, the description symbol and the
   delete — and a label you cannot read is not a label. Safe to change
   while nothing is uploaded; once it is, a renamed type would orphan
   every row already stored under the old spelling. */
const PACK_WTYPES = ['Fabric', 'Fiber', 'PVC', 'Cardboard', 'Accessories', 'Other'];

/* Matches the guard the desktop packing screen already uses. A range wider
   than this is a typing accident, not a shipment. */
const PACK_MAX_SPAN = 200000;

/* How far the approver's final weight may sit from the calculated one. */
const PACK_TOLERANCE_PCT = 10.0;

function pack_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $v = db()->query("SELECT v FROM exp_meta WHERE k='pack_schema_version'")->fetchColumn();
        if ($v !== false && (string)$v === PACK_SCHEMA_VERSION) return;
    } catch (Throwable $e) {
        /* exp_meta not built yet — export.php makes it, and we run after. */
    }

    $x = function (string $sql): void { try { db()->exec($sql); } catch (Throwable $e) {} };

    $x("CREATE TABLE IF NOT EXISTS exp_meta (
        k VARCHAR(60) NOT NULL PRIMARY KEY, v TEXT NULL, updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* One row per serial range. The range IS the identity of the group:
       two groups may not claim the same carton. */
    $x("CREATE TABLE IF NOT EXISTS packing_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_id INT NOT NULL,
        line_no INT NOT NULL DEFAULT 0,
        invoice_item_id INT NULL,
        product_name VARCHAR(255) NOT NULL DEFAULT '',
        des_col VARCHAR(255) NOT NULL DEFAULT '',
        optional_value VARCHAR(255) NOT NULL DEFAULT '',
        unit_title VARCHAR(60) NOT NULL DEFAULT 'Carton',
        serial_from INT NOT NULL DEFAULT 0,
        serial_to INT NOT NULL DEFAULT 0,
        packages INT NOT NULL DEFAULT 0,
        qty_mode VARCHAR(10) NOT NULL DEFAULT 'per',
        qty_per_pkg DECIMAL(16,3) NOT NULL DEFAULT 0,
        total_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
        assorted TINYINT(1) NOT NULL DEFAULT 0,
        size_label VARCHAR(120) NOT NULL DEFAULT '',
        pkg_gross DECIMAL(14,3) NOT NULL DEFAULT 0,
        pkg_tare DECIMAL(14,3) NOT NULL DEFAULT 0,
        created_by INT NULL, created_at DATETIME NULL,
        updated_by INT NULL, updated_at DATETIME NULL,
        KEY idx_pg_ship (shipment_id, serial_from)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* The size breakdown. A single-size group gets one row here too, so
       everything downstream reads one shape instead of two. */
    $x("CREATE TABLE IF NOT EXISTS packing_group_sizes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        size_label VARCHAR(120) NOT NULL DEFAULT '',
        qty_per_pkg DECIMAL(16,3) NOT NULL DEFAULT 0,
        total_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
        KEY idx_pgs_grp (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* What one unit of one size is made of. */
    $x("CREATE TABLE IF NOT EXISTS packing_weight_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        size_label VARCHAR(120) NOT NULL DEFAULT '',
        line_no INT NOT NULL DEFAULT 0,
        w_type VARCHAR(40) NOT NULL DEFAULT 'Fabric',
        w_name VARCHAR(120) NOT NULL DEFAULT '',
        grams DECIMAL(14,3) NOT NULL DEFAULT 0,
        KEY idx_pwl_grp (group_id, size_label)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* The same breakdown remembered against the product and size, so the
       next shipment of the same thing starts from last time's figures
       instead of an empty list. Keyed on a normalised product name rather
       than an id, because the same product is re-typed on every invoice. */
    $x("CREATE TABLE IF NOT EXISTS packing_weight_std (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_key VARCHAR(160) NOT NULL,
        size_label VARCHAR(120) NOT NULL DEFAULT '',
        line_no INT NOT NULL DEFAULT 0,
        w_type VARCHAR(40) NOT NULL DEFAULT 'Fabric',
        w_name VARCHAR(120) NOT NULL DEFAULT '',
        grams DECIMAL(14,3) NOT NULL DEFAULT 0,
        updated_by INT NULL, updated_at DATETIME NULL,
        KEY idx_pws (product_key, size_label)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Two columns on the finished line so the print can show the size and
       the desktop can find its way back to the group it came from. */
    $x("ALTER TABLE packing_items ADD COLUMN size_text VARCHAR(190) NULL");
    $x("ALTER TABLE packing_items ADD COLUMN packing_group_id INT NULL");

    /* v2 — THE PER-PRODUCT RULE NEEDS SOMEWHERE TO LOOK.
       product_key is the normalised product name, written on every save,
       so "what has this product been packed in before" is one indexed
       read rather than a scan with normalising done in PHP afterwards.
       colour_label sits beside size_label because a colour is the same
       kind of thing: a property of what is in the package, recorded
       here and never checked against the invoice. */
    $x("ALTER TABLE packing_groups ADD COLUMN product_key VARCHAR(160) NOT NULL DEFAULT ''");
    $x("ALTER TABLE packing_groups ADD INDEX idx_pg_prod (product_key)");
    $x("ALTER TABLE packing_group_sizes ADD COLUMN colour_label VARCHAR(80) NOT NULL DEFAULT ''");
    $x("ALTER TABLE packing_weight_lines ADD COLUMN colour_label VARCHAR(80) NOT NULL DEFAULT ''");
    $x("ALTER TABLE packing_weight_std ADD COLUMN colour_label VARCHAR(80) NOT NULL DEFAULT ''");

    /* Ranges saved before this version have no key. Filling it here
       rather than on the next save means their history is available
       straight away, which is the whole point of history. */
    try {
        $rows = db()->query("SELECT id, product_name FROM packing_groups WHERE product_key = ''")->fetchAll();
        if ($rows) {
            $up = db()->prepare("UPDATE packing_groups SET product_key = ? WHERE id = ?");
            foreach ($rows as $r) $up->execute([pack_product_key((string)$r['product_name']), (int)$r['id']]);
        }
    } catch (Throwable $e) {}

    /* v3 — THE ORDER'S OWN PALETTE.

       The customer's sizes and colours arrive by email, at a desk, and
       vary customer to customer and order to order. The office types
       them once per invoice line; the phone then offers exactly those
       and nothing else, so a packer in the hall never scrolls a list of
       twenty colours looking for the two this order uses.

       It is a list, not a set of columns, because an order may use two
       sizes or nine and nobody should have to alter a table to find out. */
    $x("CREATE TABLE IF NOT EXISTS packing_palette (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_id INT NOT NULL,
        invoice_item_id INT NOT NULL,
        kind VARCHAR(8) NOT NULL,              /* size | colour */
        label VARCHAR(120) NOT NULL,
        sort_no INT NOT NULL DEFAULT 0,
        created_by INT NULL, created_at DATETIME NULL,
        UNIQUE KEY uq_pal (shipment_id, invoice_item_id, kind, label),
        KEY idx_pal (shipment_id, invoice_item_id, kind, sort_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Whether this range's colours really weigh differently. Usually
       they do not — the same cloth with a different dye — so one
       breakdown per size serves every colour of it, and this stays off. */
    $x("ALTER TABLE packing_groups ADD COLUMN weight_by_colour TINYINT(1) NOT NULL DEFAULT 0");

    try {
        db()->prepare("INSERT INTO exp_meta (k, v, updated_at) VALUES ('pack_schema_version', ?, NOW())
                       ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=NOW()")
            ->execute([PACK_SCHEMA_VERSION]);
    } catch (Throwable $e) {}
}

/* ------------------------------------------------------------ arithmetic
   These four are the whole model. Everything the screen shows is one of
   them, so there is one place to be right. */

/* The count comes from the serial. Never the other way round. */
function pack_packages(array $g): int
{
    $n = (int)$g['serial_to'] - (int)$g['serial_from'] + 1;
    return $n > 0 ? $n : 0;
}

/* Quantity of one size inside one package.
   per    — the size rows are already per package
   direct — the size rows are totals, so divide by the count */
function pack_size_per_pkg(array $g, array $size): float
{
    if (($g['qty_mode'] ?? 'per') === 'per') return (float)$size['qty_per_pkg'];
    $p = pack_packages($g);
    return $p > 0 ? (float)$size['total_qty'] / $p : 0.0;
}

function pack_group_qty(array $g, array $sizes): float
{
    $p = pack_packages($g);
    $sum = 0.0;
    foreach ($sizes as $s) $sum += pack_size_per_pkg($g, $s);
    return $sum * $p;
}

/* What one package holds, in kilograms, from the weight lines up.
   $perUnit is [size_label => grams for one unit of that size]. */
function pack_contents_kg(array $g, array $sizes, array $perUnit): float
{
    $t = 0.0;
    foreach ($sizes as $s) {
        $t += ((float)($perUnit[pack_unit_key($g, $s)] ?? 0)) * pack_size_per_pkg($g, $s);
    }
    return $t / 1000;
}

/* ------------------------------------------------------------------ read */

function pack_groups(int $shipmentId): array
{
    try {
        $s = db()->prepare("SELECT * FROM packing_groups WHERE shipment_id=? ORDER BY serial_from, id");
        $s->execute([$shipmentId]);
        return $s->fetchAll();
    } catch (Throwable $e) { return []; }
}

function pack_group(int $groupId): ?array
{
    try {
        $s = db()->prepare("SELECT * FROM packing_groups WHERE id=?");
        $s->execute([$groupId]);
        $r = $s->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

function pack_sizes(int $groupId): array
{
    try {
        $s = db()->prepare("SELECT * FROM packing_group_sizes WHERE group_id=? ORDER BY id");
        $s->execute([$groupId]);
        return $s->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* THE KEY A BREAKDOWN IS FILED UNDER.

   By size alone, or by size and colour when this range says its colours
   really do weigh differently. One function so the writing, the reading
   and the totals cannot disagree about it — which is exactly the kind of
   disagreement that shows up as a package that will not balance. */
function pack_wkey(array $g, array $size): array
{
    return [(string)$size['size_label'],
            empty($g['weight_by_colour']) ? '' : (string)($size['colour_label'] ?? '')];
}

function pack_weight_lines(int $groupId, string $size, string $colour = ''): array
{
    try {
        $s = db()->prepare("SELECT * FROM packing_weight_lines
                            WHERE group_id=? AND size_label=? AND colour_label=?
                            ORDER BY line_no, id");
        $s->execute([$groupId, $size, $colour]);
        return $s->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* Grams for one unit of each size in a group. */
/* Grams for one unit of each size, or of each size and colour. Keyed
   "size" or "size|colour" so one array serves both cases. */
function pack_per_unit(int $groupId): array
{
    try {
        $s = db()->prepare("SELECT size_label, colour_label, COALESCE(SUM(grams),0) g
                            FROM packing_weight_lines WHERE group_id=?
                            GROUP BY size_label, colour_label");
        $s->execute([$groupId]);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            /* ?? '' because the column arrives with the schema build, and
               a page load between the upload and that build would read
               rows that do not have it yet. */
            $c = (string)($r['colour_label'] ?? '');
            $out[(string)$r['size_label'] . ($c !== '' ? '|' . $c : '')] = (float)$r['g'];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/* The one place that turns a size row into the key its grams sit under. */
function pack_unit_key(array $g, array $size): string
{
    [$sz, $col] = pack_wkey($g, $size);
    return $sz . ($col !== '' ? '|' . $col : '');
}

/* ------------------------------------------------------- the remembered list

   Normalised so "Bath Towel 500GSM" and "bath  towel 500 gsm" are the same
   product. Punctuation becomes a space rather than vanishing, or
   "Bath-Towel" and "Bath Towel" would never meet. */
function pack_product_key(string $name): string
{
    $k = strtolower(trim($name));
    $k = (string)preg_replace('/[^a-z0-9]+/', ' ', $k);
    /* A number running into a word is the same thing written tighter:
       "500GSM" and "500 gsm", "300TC" and "300 TC". Without this the same
       product typed two ways on two invoices never finds its own saved
       breakdown. */
    $k = (string)preg_replace('/(\d)([a-z])/', '$1 $2', $k);
    $k = (string)preg_replace('/([a-z])(\d)/', '$1 $2', $k);
    $k = (string)preg_replace('/\s+/', ' ', $k);
    return mb_substr(trim($k), 0, 160);
}

function pack_std_get(string $productName, string $size, string $colour = ''): array
{
    $key = pack_product_key($productName);
    if ($key === '') return [];
    try {
        $s = db()->prepare("SELECT w_type, w_name, grams FROM packing_weight_std
                            WHERE product_key=? AND size_label=? AND colour_label=?
                            ORDER BY line_no, id");
        $s->execute([$key, $size, $colour]);
        $rows = $s->fetchAll();
        if ($rows || $colour === '') return $rows;
        /* A colour nobody has weighed falls back to the size on its own,
           because the usual answer is "the same cloth, a different dye". */
        return pack_std_get($productName, $size, '');
    } catch (Throwable $e) { return []; }
}

/* Replace, not merge: the last breakdown used IS the standard. Deleting a
   line and saving must drop it, which a merge would never do. */
function pack_std_save(string $productName, string $size, array $lines, string $colour = ''): void
{
    $key = pack_product_key($productName);
    if ($key === '' || $size === '' || !$lines) return;
    try {
        db()->prepare("DELETE FROM packing_weight_std
                       WHERE product_key=? AND size_label=? AND colour_label=?")
            ->execute([$key, $size, $colour]);
        $ins = db()->prepare("INSERT INTO packing_weight_std
                 (product_key, size_label, colour_label, line_no, w_type, w_name, grams, updated_by, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW())");
        $n = 0;
        foreach ($lines as $l) {
            $g = (float)($l['grams'] ?? 0);
            if ($g <= 0) continue;
            $ins->execute([$key, $size, $colour, ++$n,
                mb_substr((string)($l['w_type'] ?? 'Fabric'), 0, 40),
                mb_substr((string)($l['w_name'] ?? ''), 0, 120), $g,
                (int)(current_user()['id'] ?? 0)]);
        }
    } catch (Throwable $e) { /* a standard that will not save must not lose the packing */ }
}

/* WHICH INVOICE LINE, SAID SO NOBODY CAN MIX THEM UP.

   An invoice routinely carries the same product on several lines — four
   "Bath Towel" lines that differ only by colour or rate. A list that
   shows the product name alone shows four identical choices, and the
   wrong one gets picked with nothing on screen to say so.

   So every place that names an invoice line names it the same way: the
   line number, the product, and what the line itself says it is (its
   description or colour). Returned in parts so a screen can set the
   product in its normal type and the rest small and italic.

     ['no' => '#2', 'name' => 'Thermal Blanket', 'hint' => 'White',
      'text' => '#2 Thermal Blanket — White']                         */
function pack_item_label(array $it): array
{
    $no   = (int)($it['line_no'] ?? 0);
    $name = trim((string)($it['product_name'] ?? ''));
    $hint = trim((string)preg_replace('/\s+/', ' ', (string)($it['des_col'] ?? '')));
    if (mb_strlen($hint) > 40) $hint = rtrim(mb_substr($hint, 0, 38)) . '…';
    $tag  = $no > 0 ? '#' . $no : '';
    return ['no' => $tag, 'name' => $name, 'hint' => $hint,
            'text' => trim($tag . ' ' . $name) . ($hint !== '' ? ' — ' . $hint : '')];
}

/* The same, as HTML: the product plain, the line number and hint small
   and italic after it. */
function pack_item_html(array $it): string
{
    $l = pack_item_label($it);
    $small = trim($l['no'] . ($l['hint'] !== '' ? ' · ' . $l['hint'] : ''));
    return htmlspecialchars($l['name'], ENT_QUOTES)
         . ($small !== '' ? ' <i class="ihint">' . htmlspecialchars($small, ENT_QUOTES) . '</i>' : '');
}

/* A DOT THE COLOUR OF THE COLOUR.

   Names are read; a dot is recognised. Known names get their own, and
   anything else gets a neutral — a wrong guess at "Sand" would be worse
   than no guess, so unknown stays grey rather than inventing a shade.
   Matching ignores case and spacing so "off white" finds Off White. */
function pack_colour_swatch(string $name): string
{
    static $map = [
        'white' => '#f4f5f7', 'offwhite' => '#f0ece2', 'ivory' => '#efe7d4',
        'cream' => '#f2e8d5', 'beige' => '#ddceb4', 'sand' => '#d9c7a3',
        'grey' => '#9aa3ad', 'gray' => '#9aa3ad', 'silver' => '#c3c9d1',
        'black' => '#23262b', 'charcoal' => '#3c4147',
        'navy' => '#1f3356', 'blue' => '#2f6fd0', 'skyblue' => '#7fb9e8',
        'teal' => '#0f766e', 'green' => '#2f8f4e', 'olive' => '#6b7a3a',
        'red' => '#b8283f', 'maroon' => '#73202f', 'rust' => '#b05423',
        'pink' => '#e39ab0', 'blush' => '#e2a3ab', 'peach' => '#f0b79a',
        'purple' => '#6d5bd0', 'lilac' => '#b3a4e0',
        'yellow' => '#e6c34a', 'gold' => '#c8a249', 'orange' => '#e07b39',
        'brown' => '#7a5438', 'taupe' => '#a3927f',
    ];
    $k = strtolower((string)preg_replace('/[^a-z]+/i', '', $name));
    return $map[$k] ?? '#cbd5e3';
}

/* ========================================= the order's own palette

   What this invoice line is actually ordered in. The office types it
   once from the customer's email; the phone offers exactly this and
   nothing else. Empty means nobody has set the order up yet, and the
   phone says so rather than offering the whole world.

   It is deliberately NOT the product's history. History is what this
   product has ever been; the palette is what this order is. */
function pack_palette(int $shipmentId, int $itemId, string $kind = ''): array
{
    try {
        if ($kind !== '') {
            $s = db()->prepare("SELECT label FROM packing_palette
                                WHERE shipment_id=? AND invoice_item_id=? AND kind=?
                                ORDER BY sort_no, id");
            $s->execute([$shipmentId, $itemId, $kind]);
            return array_column($s->fetchAll(), 'label');
        }
        $s = db()->prepare("SELECT kind, label FROM packing_palette
                            WHERE shipment_id=? AND invoice_item_id=? ORDER BY kind, sort_no, id");
        $s->execute([$shipmentId, $itemId]);
        $out = ['size' => [], 'colour' => []];
        foreach ($s->fetchAll() as $r) $out[(string)$r['kind']][] = (string)$r['label'];
        return $out;
    } catch (Throwable $e) { return $kind !== '' ? [] : ['size' => [], 'colour' => []]; }
}

/* Replaced wholesale for one kind at a time: a colour the customer
   dropped has to disappear, which a merge would never do. */
function pack_palette_save(int $shipmentId, int $itemId, string $kind, array $labels): array
{
    if (!in_array($kind, ['size', 'colour'], true)) return [false, 'Not a size or a colour.'];
    $clean = pack_dedupe($labels);
    try {
        db()->beginTransaction();
        db()->prepare("DELETE FROM packing_palette
                       WHERE shipment_id=? AND invoice_item_id=? AND kind=?")
            ->execute([$shipmentId, $itemId, $kind]);
        $ins = db()->prepare("INSERT INTO packing_palette
                   (shipment_id, invoice_item_id, kind, label, sort_no, created_by, created_at)
                   VALUES (?,?,?,?,?,?,NOW())");
        $n = 0;
        foreach ($clean as $l) {
            $ins->execute([$shipmentId, $itemId, $kind, mb_substr($l, 0, 120), ++$n,
                           (int)(current_user()['id'] ?? 0)]);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return [false, 'That could not be saved. ' . $e->getMessage()];
    }
    return [true, count($clean) . ' ' . $kind . ($kind === 'size' ? 's' : 's') . ' saved.'];
}

/* What the office is offered to tick: this product's own sizes and
   colours, from the master and from its history — the per-product rule,
   unchanged. A label already in the palette is included even if it is
   in neither, so a one-off never vanishes from its own order. */
function pack_palette_choices(string $productName, int $shipmentId, int $itemId): array
{
    $pal = pack_palette($shipmentId, $itemId);
    return [
        'size'   => pack_dedupe(array_merge(pack_size_options($productName),   $pal['size'])),
        'colour' => pack_dedupe(array_merge(pack_colour_options($productName), $pal['colour'])),
    ];
}

/* ============================= IT IS ASKED FOR ONCE, NOT EVERY TIME

   "dont ask again and again if u have weight information by size or
    color already so calculated yourself and dont ask it serial changing
    and making more and more so only ask weight list for always new
    product line"

   A weight belongs to the product and the size, not to a serial range.
   Splitting 1–100 into 1–99 and a short carton 100 is one run of cartons
   described twice; being asked to weigh the same duvet again because the
   serial changed is the app failing to remember what it was already told.

   So the moment a range has its sizes, every one of them that is already
   known is filled in silently. Two places are looked at, nearer first:

     another range in THIS shipment holding the same product and size —
     weighed ten minutes ago on the screen before, and not yet a saved
     standard because standards are written when weights are;

     the saved standard for that product and size, from any shipment.

   Only what neither place knows is left empty, and the screen then asks
   for exactly that and nothing else. */
function pack_weight_seed(int $groupId): int
{
    $g = pack_group($groupId);
    if (!$g) return 0;
    $filled = 0;
    $done = [];

    foreach (pack_sizes($groupId) as $row) {
        [$size, $colour] = pack_wkey($g, $row);
        if ($size === '') continue;
        /* Two rows of the same size in different colours share one
           breakdown when colour does not change the weight — so the
           second one is already answered by the first. */
        if (isset($done[$size . '|' . $colour])) continue;
        $done[$size . '|' . $colour] = true;
        if (pack_weight_lines($groupId, $size, $colour)) continue;

        $lines = [];
        /* 1. the same product, size and colour elsewhere in this shipment */
        try {
            $q = db()->prepare("SELECT wl.w_type, wl.w_name, wl.grams
                                FROM packing_weight_lines wl
                                JOIN packing_groups g2 ON g2.id = wl.group_id
                                WHERE g2.shipment_id = ? AND g2.id <> ?
                                  AND g2.product_key = ? AND wl.size_label = ?
                                  AND wl.colour_label = ?
                                ORDER BY g2.id DESC, wl.line_no");
            $q->execute([(int)$g['shipment_id'], $groupId,
                         (string)$g['product_key'], $size, $colour]);
            $lines = $q->fetchAll();
        } catch (Throwable $e) {}

        /* 2. the standard, which falls back from a colour to its size */
        if (!$lines) $lines = pack_std_get((string)$g['product_name'], $size, $colour);

        if ($lines) { pack_weight_save($groupId, $size, $lines, false, $colour); $filled++; }
    }
    return $filled;
}

/* What still has to be asked for, as "Size" or "Size · Colour". */
function pack_weight_missing(int $groupId): array
{
    $g = pack_group($groupId);
    if (!$g) return [];
    $out = []; $seen = [];
    foreach (pack_sizes($groupId) as $row) {
        [$size, $colour] = pack_wkey($g, $row);
        if ($size === '') continue;
        $k = $size . '|' . $colour;
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        if (!pack_weight_lines($groupId, $size, $colour)) {
            $out[] = $size . ($colour !== '' ? ' · ' . $colour : '');
        }
    }
    return $out;
}

function pack_serial_problem(int $shipmentId, int $from, int $to, int $exceptGroupId = 0): string
{
    if ($from < 1)        return 'The first serial must be 1 or more.';
    if ($to < $from)      return 'The last serial cannot be before the first one.';
    if ($to - $from > PACK_MAX_SPAN) return 'That range covers more than ' . number_format(PACK_MAX_SPAN) . ' packages.';

    try {
        $s = db()->prepare("SELECT unit_title, serial_from, serial_to FROM packing_groups
                            WHERE shipment_id=? AND id<>? AND serial_from<=? AND serial_to>=?
                            ORDER BY serial_from LIMIT 1");
        $s->execute([$shipmentId, $exceptGroupId, $to, $from]);
        $hit = $s->fetch();
        if ($hit) {
            return 'Those serials are already used by ' . $hit['unit_title'] . ' '
                 . (int)$hit['serial_from'] . '–' . (int)$hit['serial_to']
                 . '. Every package number can be used once.';
        }
    } catch (Throwable $e) {
        /* FAIL CLOSED. This used to swallow the error and return "no
           problem", so a failed check let a duplicate carton through
           without a word. A save that cannot be checked is refused. */
        return 'The package numbers could not be checked just now, so nothing was saved. Try again.';
    }
    return '';
}

/* Saves one range and its sizes. $in is already-validated scalars from the
   screen; $sizes is a list of ['size_label'=>, 'qty'=>] where qty means
   per package in 'per' mode and a total in 'direct' mode.

   Returns [ok, message, groupId]. */
function pack_group_save(int $shipmentId, array $in, array $sizes, int $groupId = 0): array
{
    $from = (int)($in['serial_from'] ?? 0);
    $to   = (int)($in['serial_to'] ?? 0);
    $bad  = pack_serial_problem($shipmentId, $from, $to, $groupId);
    if ($bad !== '') return [false, $bad, 0];

    $mode = ($in['qty_mode'] ?? 'per') === 'direct' ? 'direct' : 'per';
    $unit = trim((string)($in['unit_title'] ?? 'Carton')) ?: 'Carton';
    $assorted = !empty($in['assorted']);
    $packages = max(0, $to - $from + 1);

    /* Tidy the rows: drop the blanks, add up what is left. A row is a
       size AND a colour now — either may be empty, and both empty with
       a quantity is still nothing, so it goes. */
    $clean = [];
    foreach ($sizes as $s) {
        $lbl = trim((string)($s['size_label'] ?? ''));
        $col = trim((string)($s['colour_label'] ?? ''));
        $q   = (float)($s['qty'] ?? 0);
        if (($lbl === '' && $col === '') || $q <= 0) continue;
        $clean[] = ['size_label'   => mb_substr($lbl, 0, 120),
                    'colour_label' => mb_substr($col, 0, 80),
                    'qty'          => $q];
    }
    if (!$clean) {
        return [false, $assorted
            ? 'Set up the assorted sizes before saving this range.'
            : 'Pick a size and put the quantity in.', 0];
    }
    if (!$assorted && count($clean) > 1) $clean = [$clean[0]];

    $sum = 0.0;
    foreach ($clean as $c) $sum += $c['qty'];
    $totalQty = $mode === 'per' ? $sum * $packages : $sum;
    $perPkg   = $packages > 0 ? $totalQty / $packages : 0.0;

    $uid = (int)(current_user()['id'] ?? 0);
    try {
        db()->beginTransaction();

        if ($groupId > 0) {
            db()->prepare("UPDATE packing_groups SET invoice_item_id=?, product_name=?, product_key=?, des_col=?,
                    optional_value=?, unit_title=?, serial_from=?, serial_to=?, packages=?,
                    qty_mode=?, qty_per_pkg=?, total_qty=?, assorted=?, size_label=?,
                    weight_by_colour=?, updated_by=?, updated_at=NOW()
                   WHERE id=? AND shipment_id=?")
                ->execute([
                    (int)($in['invoice_item_id'] ?? 0) ?: null,
                    mb_substr((string)($in['product_name'] ?? ''), 0, 255),
                    pack_product_key((string)($in['product_name'] ?? '')),
                    mb_substr((string)($in['des_col'] ?? ''), 0, 255),
                    mb_substr((string)($in['optional_value'] ?? ''), 0, 255),
                    $unit, $from, $to, $packages, $mode, $perPkg, $totalQty,
                    $assorted ? 1 : 0, $assorted ? '' : $clean[0]['size_label'],
                    !empty($in['weight_by_colour']) ? 1 : 0,
                    $uid, $groupId, $shipmentId,
                ]);
        } else {
            $ln = db()->prepare("SELECT COALESCE(MAX(line_no),0)+1 FROM packing_groups WHERE shipment_id=?");
            $ln->execute([$shipmentId]);
            db()->prepare("INSERT INTO packing_groups
                    (shipment_id, line_no, invoice_item_id, product_name, product_key, des_col, optional_value,
                     unit_title, serial_from, serial_to, packages, qty_mode, qty_per_pkg, total_qty,
                     assorted, size_label, weight_by_colour, created_by, created_at, updated_by, updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,NOW())")
                ->execute([
                    $shipmentId, (int)$ln->fetchColumn(),
                    (int)($in['invoice_item_id'] ?? 0) ?: null,
                    mb_substr((string)($in['product_name'] ?? ''), 0, 255),
                    pack_product_key((string)($in['product_name'] ?? '')),
                    mb_substr((string)($in['des_col'] ?? ''), 0, 255),
                    mb_substr((string)($in['optional_value'] ?? ''), 0, 255),
                    $unit, $from, $to, $packages, $mode, $perPkg, $totalQty,
                    $assorted ? 1 : 0, $assorted ? '' : $clean[0]['size_label'],
                    !empty($in['weight_by_colour']) ? 1 : 0, $uid, $uid,
                ]);
            $groupId = (int)db()->lastInsertId();
        }

        /* The size rows are replaced wholesale — a removed size must go. */
        db()->prepare("DELETE FROM packing_group_sizes WHERE group_id=?")->execute([$groupId]);
        $ins = db()->prepare("INSERT INTO packing_group_sizes
                   (group_id, size_label, colour_label, qty_per_pkg, total_qty) VALUES (?,?,?,?,?)");
        foreach ($clean as $c) {
            $pp = $mode === 'per' ? $c['qty'] : ($packages > 0 ? $c['qty'] / $packages : 0);
            $tt = $mode === 'per' ? $c['qty'] * $packages : $c['qty'];
            $ins->execute([$groupId, $c['size_label'], $c['colour_label'], $pp, $tt]);
        }

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return [false, 'That range could not be saved. ' . $e->getMessage(), 0];
    }

    /* AFTER the commit, never inside it: pack_weight_save opens its own
       transaction and a nested begin would throw. Every size already
       known is filled in here, so the weight screen only ever asks about
       something genuinely new. */
    $seeded = pack_weight_seed($groupId);

    return [true, $seeded > 0
        ? 'Saved. ' . $seeded . ' ' . ($seeded === 1 ? 'size was' : 'sizes were')
          . ' weighed already — those are filled in.'
        : 'Saved.', $groupId];
}

function pack_group_delete(int $shipmentId, int $groupId): bool
{
    try {
        db()->beginTransaction();
        db()->prepare("DELETE FROM packing_weight_lines WHERE group_id=?")->execute([$groupId]);
        db()->prepare("DELETE FROM packing_group_sizes WHERE group_id=?")->execute([$groupId]);
        db()->prepare("DELETE FROM packing_groups WHERE id=? AND shipment_id=?")
            ->execute([$groupId, $shipmentId]);
        db()->commit();
        return true;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return false;
    }
}

function pack_weigh_save(int $groupId, float $gross, float $tare): void
{
    try {
        db()->prepare("UPDATE packing_groups SET pkg_gross=?, pkg_tare=?, updated_by=?, updated_at=NOW()
                       WHERE id=?")
            ->execute([$gross, $tare, (int)(current_user()['id'] ?? 0), $groupId]);
    } catch (Throwable $e) {}
}

/* Replaces every line for one group and one size.

   $remember is what makes "do not ask again" true. A breakdown used is
   a breakdown known, so it becomes the standard the moment it is saved
   rather than waiting for the list to be approved — otherwise a
   shipment that is weighed today and approved next week asks for the
   same figures again in between. Seeding passes false, because copying
   a standard onto a range is not news. */
function pack_weight_save(int $groupId, string $size, array $lines,
                          bool $remember = true, string $colour = ''): array
{
    if ($size === '') return [false, 'No size to save against.'];
    try {
        db()->beginTransaction();
        db()->prepare("DELETE FROM packing_weight_lines
                       WHERE group_id=? AND size_label=? AND colour_label=?")
            ->execute([$groupId, $size, $colour]);
        $ins = db()->prepare("INSERT INTO packing_weight_lines
                   (group_id, size_label, colour_label, line_no, w_type, w_name, grams)
                   VALUES (?,?,?,?,?,?,?)");
        $n = 0;
        foreach ($lines as $l) {
            $g = (float)($l['grams'] ?? 0);
            if ($g <= 0) continue;
            $type = (string)($l['w_type'] ?? 'Fabric');
            if (!in_array($type, PACK_WTYPES, true)) $type = 'Other';
            $ins->execute([$groupId, $size, $colour, ++$n, $type,
                           mb_substr(trim((string)($l['w_name'] ?? '')), 0, 120), $g]);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return [false, 'The breakdown could not be saved.'];
    }

    if ($remember) {
        $g = pack_group($groupId);
        if ($g) pack_std_save((string)$g['product_name'], $size, $lines, $colour);
    }
    return [true, 'Saved.'];
}

/* -------------------------------------------------------------- the totals */

/* Everything the approve screen needs, worked out once.
   'unfinished' names each range that is not ready, in plain words. It does
   not block anything — it is there to be read before approving. */
function pack_totals(int $shipmentId): array
{
    $net = 0.0; $gross = 0.0; $pkgs = 0; $qty = 0.0; $un = [];

    foreach (pack_groups($shipmentId) as $g) {
        $sizes   = pack_sizes((int)$g['id']);
        $perUnit = pack_per_unit((int)$g['id']);
        $p       = pack_packages($g);
        $c       = pack_contents_kg($g, $sizes, $perUnit);
        $where   = $g['unit_title'] . ' ' . (int)$g['serial_from'] . '–' . (int)$g['serial_to'];

        $net   += $c * $p;
        $gross += $c * $p + (float)$g['pkg_tare'] * $p;
        $pkgs  += $p;
        $qty   += pack_group_qty($g, $sizes);

        if (!$sizes)                      $un[] = $where . ' · no size yet';
        if ((float)$g['pkg_gross'] <= 0)  $un[] = $where . ' · not weighed yet';
        else {
            $must = (float)$g['pkg_gross'] - (float)$g['pkg_tare'];
            if (abs($c - $must) >= 0.0005) {
                $un[] = $where . ' · the lines come to ' . number_format($c, 3)
                      . ' kg against ' . number_format($must, 3) . ' kg';
            }
        }
    }
    return ['net' => $net, 'gross' => $gross, 'packages' => $pkgs,
            'qty' => $qty, 'unfinished' => $un];
}

function pack_deviation_pct(float $calculated, float $given): float
{
    if ($calculated <= 0) return 0.0;
    return abs($given - $calculated) / $calculated * 100;
}

/* ------------------------------------------------------------- the approve

   Writes the finished packing_items rows from the ranges. Rows this
   shipment's ranges own are replaced; any row the desktop entered by hand
   is left exactly where it is, because deleting someone else's work is not
   this screen's business.

   When the approver overrules the weights, each row is scaled so the rows
   still add up to what was approved. Leaving the rows at their calculated
   figures while the shipment header said something else would put two
   different answers on the same document. */
function pack_approve(int $shipmentId, float $finalNet, float $finalGross): array
{
    $groups = pack_groups($shipmentId);
    if (!$groups) return [false, 'There is nothing to approve yet.'];

    $t = pack_totals($shipmentId);
    if (pack_deviation_pct($t['net'], $finalNet) > PACK_TOLERANCE_PCT
     || pack_deviation_pct($t['gross'], $finalGross) > PACK_TOLERANCE_PCT) {
        return [false, 'A final weight is more than ' . PACK_TOLERANCE_PCT
                     . '% away from what the lines give. Check the breakdown first.'];
    }

    $netScale   = $t['net']   > 0 ? $finalNet   / $t['net']   : 1.0;
    $grossScale = $t['gross'] > 0 ? $finalGross / $t['gross'] : 1.0;

    try {
        db()->beginTransaction();

        db()->prepare("DELETE FROM packing_items WHERE shipment_id=? AND packing_group_id IS NOT NULL")
            ->execute([$shipmentId]);

        $ln = db()->prepare("SELECT COALESCE(MAX(line_no),0)+1 FROM packing_items WHERE shipment_id=?");
        $ins = db()->prepare("INSERT INTO packing_items
            (shipment_id, invoice_item_id, line_no, product_name, des_col, optional_value,
             pack_unit_title, carton_from, carton_to, packages, qty_per_carton, qty_mode,
             total_qty, net_weight, gross_weight, size_text, packing_group_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        foreach ($groups as $g) {
            $sizes   = pack_sizes((int)$g['id']);
            $perUnit = pack_per_unit((int)$g['id']);
            $p       = pack_packages($g);
            $c       = pack_contents_kg($g, $sizes, $perUnit);

            $ln->execute([$shipmentId]);
            $ins->execute([
                $shipmentId,
                (int)$g['invoice_item_id'] ?: null,
                (int)$ln->fetchColumn(),
                (string)$g['product_name'], (string)$g['des_col'], (string)$g['optional_value'],
                (string)$g['unit_title'],
                (int)$g['serial_from'], (int)$g['serial_to'], $p,
                $p > 0 ? pack_group_qty($g, $sizes) / $p : 0,
                $g['qty_mode'] === 'direct' ? 'direct' : 'auto',
                pack_group_qty($g, $sizes),
                $c * $p * $netScale,
                ($c * $p + (float)$g['pkg_tare'] * $p) * $grossScale,
                mb_substr(pack_size_text($g, $sizes), 0, 190),
                (int)$g['id'],
            ]);

            /* Remember this breakdown for the next shipment of the same
               product and size. */
            foreach ($sizes as $s) {
                $lines = pack_weight_lines((int)$g['id'], (string)$s['size_label']);
                if ($lines) pack_std_save((string)$g['product_name'], (string)$s['size_label'], $lines);
            }
        }

        /* The shipment header gets the approved figures, not the calculated
           ones, and the package count straight off the serials. */
        db()->prepare("UPDATE shipments SET total_packages=?, total_net_weight=?, total_gross_weight=?,
                       updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([$t['packages'], $finalNet, $finalGross,
                       (int)(current_user()['id'] ?? 0), $shipmentId]);

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return [false, 'The packing list could not be approved. ' . $e->getMessage()];
    }

    if (function_exists('audit_log')) {
        try {
            audit_log($shipmentId, 'Packing Approved', 'update', null,
                      'Packing approved from the phone',
                      number_format($t['packages']) . ' packages, '
                      . number_format($t['qty'], 2) . ' qty, '
                      . number_format($finalNet, 3) . ' kg net');
        } catch (Throwable $e) {}
    }

    return [true, 'Packing list approved — ' . number_format($t['packages']) . ' packages, '
                . number_format($t['qty'], 2) . ' pieces.'];
}

/* "King", "White King", or "2 White Small, 4 Navy Large" — what the
   print shows. A colour is part of what is in the carton, so it belongs
   on the line; a range with no colours reads exactly as it did before. */
function pack_size_text(array $g, array $sizes): string
{
    if (!$sizes) return '';
    $one = static function (array $s): string {
        $t = trim((string)$s['size_label']);
        $c = trim((string)($s['colour_label'] ?? ''));
        if ($t === '') return $c;
        return $c === '' ? $t : $c . ' ' . $t;
    };
    if (empty($g['assorted'])) return $one($sizes[0]);
    $bits = [];
    foreach ($sizes as $s) {
        $q = pack_size_per_pkg($g, $s);
        $bits[] = rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.') . ' ' . $one($s);
    }
    return implode(', ', $bits);
}

/* ------------------------------------------------------------- permission

   The same rules the desktop packing screen uses, in one place so the two
   cannot drift apart. */
function pack_may_edit(array $shipment): bool
{
    if (function_exists('is_production_staff') && is_production_staff()) return false;
    $status = (string)($shipment['status'] ?? '');
    $locked = in_array($status, ['approved_locked', 'final'], true);
    if ($locked) return is_admin();
    if (($shipment['packing_status'] ?? 'open') === 'completed') {
        return is_admin() || (function_exists('is_colleague') && is_colleague());
    }
    return true;
}

/* IS THERE ANY POINT SHOWING THIS PERSON THE PACKING SCREEN?

   A different question from pack_may_edit(), which asks whether one
   particular packing list may still be changed. This one asks whether
   the account has anything to open at all, and it exists because the
   mobile home was offering a Packing tile to people who would tap it and
   be told "No shipment is assigned to you" — a button that does nothing
   is worse than no button.

   Production staff cannot open packing at all. Admins and colleagues see
   every shipment, so there is always something there. Everyone else sees
   only what has been assigned to them, so with nothing assigned there is
   nothing to show. */
function pack_may_use(): bool
{
    if (function_exists('is_production_staff') && is_production_staff()) return false;
    if (function_exists('is_admin') && is_admin()) return true;
    if (function_exists('is_colleague') && is_colleague()) return true;
    if (!function_exists('assigned_shipment_ids')) return false;
    $ids = assigned_shipment_ids();
    return $ids === ['ALL'] || $ids !== [];
}

/* ===================================== what this product is packed in

   THE RULE: the sizes offered are this product's sizes, exactly.

   It used to offer the product's own sizes, then every size used
   anywhere in packing, then a made-up list ending in Queen and King.
   So a bath towel was offered King, and a list of eighty labels from
   other people's shipments. A dropdown that long is slower to use than
   typing, and the wrong label picked once is wrong in the database for
   ever.

   Two sources now, both about this product and nothing else:
     what the product master has been costed in, and
     what this product has actually been packed in before.

   HISTORY IS THE SECOND HALF OF THE RULE. A size or a colour typed once
   is offered from then on, so the list fills itself from real use
   rather than from someone maintaining it. Nothing is invented: a
   product nobody has packed yet offers nothing, and the screen lets the
   first one be typed. */

/* The product master's sizes. Matched on the exact name first, because
   that is the common case and it is one indexed read. Only if that
   finds nothing is the normalised key tried, which is what catches
   "Bath Towel 500GSM" against "Bath Towel 500 gsm". */
function pack_master_sizes(string $productName): array
{
    static $memo = [];
    $key = pack_product_key($productName);
    if ($key === '') return [];
    if (isset($memo[$key])) return $memo[$key];

    $out = [];
    try {
        $s = db()->prepare("SELECT DISTINCT ps.size_label
                            FROM product_sizes ps JOIN products p ON p.id = ps.product_id
                            WHERE p.name = ? AND ps.size_label <> '' ORDER BY ps.size_label");
        $s->execute([$productName]);
        $out = array_column($s->fetchAll(), 'size_label');
    } catch (Throwable $e) {}

    if (!$out) {
        /* The master list is a few hundred rows, so normalising in PHP
           is cheaper than teaching MySQL to do it, and it is memoised
           for the request either way. */
        try {
            $ids = [];
            foreach (db()->query("SELECT id, name FROM products LIMIT 5000")->fetchAll() as $r) {
                if (pack_product_key((string)$r['name']) === $key) $ids[] = (int)$r['id'];
            }
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $s = db()->prepare("SELECT DISTINCT size_label FROM product_sizes
                                    WHERE product_id IN ($in) AND size_label <> '' ORDER BY size_label");
                $s->execute($ids);
                $out = array_column($s->fetchAll(), 'size_label');
            }
        } catch (Throwable $e) {}
    }
    return $memo[$key] = $out;
}

/* What this product has been packed in before — the half of the rule
   that needs nobody to maintain it. */
function pack_history(string $productName, string $column): array
{
    $key = pack_product_key($productName);
    if ($key === '' || !in_array($column, ['size_label', 'colour_label'], true)) return [];
    try {
        $s = db()->prepare("SELECT DISTINCT gs.$column v
                            FROM packing_group_sizes gs
                            JOIN packing_groups g ON g.id = gs.group_id
                            WHERE g.product_key = ? AND gs.$column <> ''
                            ORDER BY gs.$column LIMIT 60");
        $s->execute([$key]);
        return array_column($s->fetchAll(), 'v');
    } catch (Throwable $e) { return []; }
}

function pack_dedupe(array $in): array
{
    $seen = []; $out = [];
    foreach ($in as $v) {
        $v = trim((string)$v);
        if ($v === '') continue;
        $k = strtolower($v);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $v;
    }
    return $out;
}

function pack_size_options(string $productName): array
{
    return pack_dedupe(array_merge(
        pack_master_sizes($productName),
        pack_history($productName, 'size_label')
    ));
}

/* Colours have no master list to come from — nobody costs a product per
   colour — so this is history alone. The first one is typed, and from
   then on it is offered. */
function pack_colour_options(string $productName): array
{
    return pack_dedupe(pack_history($productName, 'colour_label'));
}
