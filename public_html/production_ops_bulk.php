<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Product Master access required.'); }
require_once __DIR__ . '/includes/pm_search.php';
require_once __DIR__ . '/includes/production.php';
production_ensure_schema();

function pob_norm($v): string { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string)$v), '_')); }
function pob_num($v): float { $s = preg_replace('/[^0-9.\-]/', '', (string)$v); return is_numeric($s) ? (float)$s : 0.0; }
function pob_pick(array $r, array $keys, $d = '') { foreach ($keys as $k) if (isset($r[$k]) && trim((string)$r[$k]) !== '') return trim((string)$r[$k]); return $d; }
/* Excel-exported CSVs are often Windows-1252 — clean every cell to valid UTF-8 as it's
   read, same fix applied in costing_import.php, so one bad byte can't break the import. */
function pob_utf8($s): string { $s = (string)$s; if ($s === '' || mb_check_encoding($s, 'UTF-8')) return $s; $c = @mb_convert_encoding($s, 'UTF-8', 'Windows-1252'); return $c !== false ? $c : (string)@iconv('UTF-8', 'UTF-8//IGNORE', $s); }


/* ---------- CSV downloads ----------
   One writer for all of them so a quoting bug cannot exist in three
   places. fputcsv handles commas, quotes and newlines inside a product
   name; hand-built CSV does not, and product names contain commas. */
function pob_send_csv(string $filename, array $header, array $rows): never {
    /* A CSV EXPORT MUST NEVER BE CACHED. The download URL is fixed
       (?download=operations), so with no cache headers a browser is entitled
       to hand back the copy it already has — which is exactly why an export
       could come out WITHOUT products added to Product Master since the last
       time you pressed it. The query underneath was always live; the browser
       simply never asked it again.

       Three headers because the old ones are still what some proxies obey. */
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    /* And a stamped filename, so two exports can never be confused for each
       other in your Downloads folder — the newest is obvious by name. */
    if (str_ends_with($filename, '.csv')) {
        $filename = substr($filename, 0, -4) . '_' . date('Y-m-d_Hi') . '.csv';
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM, so Excel opens UTF-8 correctly
    fputcsv($out, $header);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

/* THE OPERATIONS CSV IS GONE, AND IT WAS REMOVED BECAUSE IT WAS WRONG.
 *
 * Its six columns were Operation ID, Product Name, Part, Operation Name,
 * Stage, Rate — and NO SIZE. But an operation can be priced per size
 * (production_operations.product_size_id, shown as "Applies to" in Product
 * Master). So a product with six sizes exported as six rows identical except
 * the Rate, with nothing saying which size any of them belonged to. In Excel
 * that reads as duplicated junk.
 *
 * The import was worse in a quieter way: its INSERT never set
 * product_size_id, so every row added from a file landed on "All sizes". You
 * could not create a size-scoped rate through this page at all — copying a row
 * in Excel to add a King rate produced a second All-sizes row that Product
 * Master then showed as a duplicate.
 *
 * It was also the third way into the same table. Operations now come from the
 * Part Library (defined once, with its own CSV) or from the Product Master
 * grid (which takes an Excel paste AND understands sizes). Two correct routes
 * beat three where one is broken.
 *
 * SET QUANTITIES STAY. That file has Size as a real column, has no such bug,
 * and at thirty products across six sizes it is 180 numbers that Excel really
 * is faster at than clicking.
 */
const POB_QTY_HEAD = ['Product Name', 'Size', 'Part', 'Qty Per Set'];

/* Set quantities are a separate file on purpose — see the note on
   production_export_component_qty(). */
if (($_GET['download'] ?? '') === 'quantities') {
    $rows = [];
    foreach (production_export_component_qty() as $r) {
        $rows[] = [$r['product_name'], $r['size_label'], $r['component_name'],
            rtrim(rtrim(number_format((float)$r['qty_per_set'], 2, '.', ''), '0'), '.')];
    }
    if (!$rows) $rows[] = ['', '', '', ''];
    pob_send_csv('production_set_quantities.csv', POB_QTY_HEAD, $rows);
}

/* ---------- CSV import (preview / commit, page-reload flow — same pattern
   as costing_import.php) ---------- */
$stage = 'upload'; $rows = []; $cntNew = $cntErr = $skippedBlank = 0; $err = ''; $done = '';
$importKind = 'qty';   // only one kind of file left — set quantities

if (($_GET['done'] ?? '') === '1' && !empty($_SESSION['pob_import_done'])) {
    $stage = 'done'; $done = $_SESSION['pob_import_done']; unset($_SESSION['pob_import_done']);
}

/* The csv_preview and csv_commit handlers lived here — the two halves of
   the operations import. Both are gone with the export that fed them; see
   the note above pob_send_csv(). A POST naming either action now simply
   falls through to the page, which no longer offers them. */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act === 'qty_preview') {
    verify_csrf();
    $importKind = 'qty';
    try {
        if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) throw new Exception('Please choose a .csv file.');
        if (strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION)) !== 'csv') throw new Exception('Only .csv files are supported.');
        $fh = fopen($_FILES['csv']['tmp_name'], 'r'); $head = fgetcsv($fh); if (!$head) throw new Exception('CSV is empty.');
        $map = []; foreach ($head as $i => $h) $map[$i] = pob_norm($h);
        $byExact = [];
        foreach (db()->query("SELECT id, name FROM products WHERE is_active=1")->fetchAll() as $p) {
            $byExact[mb_strtolower(trim($p['name']))] = (int)$p['id'];
        }
        /* Valid (product, size) pairs, so a size typo is caught here rather
           than becoming a silently skipped row at commit time. */
        $sizeOk = [];
        foreach (db()->query("SELECT product_id, LOWER(size_label) sl FROM product_sizes")->fetchAll() as $s) {
            $sizeOk[(int)$s['product_id'] . '|' . $s['sl']] = true;
        }
        $n = 0;
        while (($d = fgetcsv($fh)) !== false) {
            if (count(array_filter($d, fn($x) => trim((string)$x) !== '')) === 0) continue;
            $n++;
            if ($n > 500) { $rows[] = ['n' => $n, 'err' => 'Import capped at 500 rows — split into another file.', 'match' => 'error']; $cntErr++; break; }
            $r = []; foreach ($map as $i => $k) $r[$k] = pob_utf8($d[$i] ?? '');
            $productName = pob_pick($r, ['product_name', 'product']);
            $size = pob_pick($r, ['size', 'size_label']);
            $comp = pob_pick($r, ['part', 'component', 'component_name']);
            $qtyRaw = pob_pick($r, ['qty_per_set', 'qty', 'quantity'], '');
            $qty = pob_num($qtyRaw);

            $e = ''; $pid = 0;
            if ($productName === '') $e = 'Product name required';
            elseif ($size === '') $e = 'Size required';
            elseif ($comp === '') $e = 'Part required';
            elseif (trim($qtyRaw) === '') $e = 'Qty Per Set required';
            elseif ($qty < 0) $e = 'Qty cannot be negative';
            else {
                $pid = $byExact[mb_strtolower($productName)] ?? 0;
                if (!$pid) $e = 'No active product with this exact name';
                elseif (!isset($sizeOk[$pid . '|' . mb_strtolower($size)])) $e = 'This product has no size called "' . $size . '"';
            }
            if ($e) $cntErr++; else $cntNew++;
            $rows[] = ['n' => $n, 'err' => $e, 'product_name' => $productName, 'pid' => $pid,
                'size' => $size, 'component' => $comp, 'qty' => $qty];
        }
        fclose($fh);
        if (!$rows) throw new Exception('No data rows found.');
        $stage = 'preview';
    } catch (Throwable $e) { $err = $e->getMessage(); $importKind = 'qty'; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act === 'qty_commit') {
    verify_csrf();
    $n = count($_POST['q_pid'] ?? []);
    $entries = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = (int)($_POST['q_pid'][$i] ?? 0);
        if ($pid <= 0) continue;
        $entries[] = [
            'product_id' => $pid,
            'size_label' => trim((string)($_POST['q_size'][$i] ?? '')),
            'component_name' => trim((string)($_POST['q_comp'][$i] ?? '')),
            'qty_per_set' => pob_num($_POST['q_qty'][$i] ?? 0),
        ];
    }
    $result = production_bulk_save_component_qty($entries, (int)current_user()['id']);
    $msg = 'Set quantities: ' . $result['saved'] . ' row(s) saved.';
    if ($result['errors']) $msg .= ' ' . count($result['errors']) . ' failed: ' . implode(' ', array_slice($result['errors'], 0, 5));
    $_SESSION['pob_import_done'] = $msg;
    redirect('production_ops_bulk.php?done=1');
}

$allProductsForPicker = db()->query("SELECT id, name FROM products WHERE is_active=1 ORDER BY name")->fetchAll();

page_header('Set Quantities — CSV');
flash();
?>
<style>
.zcard{padding:15px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;margin-bottom:11px}
.zcard h2{font-size:15px;margin:0}
/* .zin lives in assets/css/app.css now. */
.zbtn{padding:11px 18px;border:none;border-radius:11px;cursor:pointer;font-weight:700;font-size:13px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);text-decoration:none;display:inline-flex;align-items:center;gap:7px}
.zbtn.sec{background:#f6f8fc;color:#152033;border:1px solid #cbd5e3}
.zbtn[disabled]{opacity:.5;cursor:not-allowed}
.pob-grid-hd{display:grid;grid-template-columns:1.6fr 1fr 1.3fr 130px 110px 34px;gap:8px;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:700;padding:0 4px 6px}
.pob-row{display:grid;grid-template-columns:1.6fr 1fr 1.3fr 130px 110px 34px;gap:8px;align-items:start;padding:6px 4px;border-radius:10px}
.pob-row:hover{background:#f8fafc}
.pob-searchwrap{position:relative}
.pob-suggest{position:absolute;left:0;right:0;top:calc(100% + 4px);background:#fff;border:1px solid #cbd5e3;border-radius:10px;box-shadow:0 12px 28px rgba(20,30,50,.14);z-index:20;overflow:hidden;display:none;max-height:220px;overflow-y:auto}
.pob-suggest.show{display:block}
.pob-suggest-item{padding:9px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f6f8fc}
.pob-suggest-item:hover{background:#f6f8fc}
.pob-rm{width:38px;height:38px;border-radius:9px;border:1px solid #cbd5e3;background:#fff;color:#b8283f;cursor:pointer;font-size:15px}
.pob-addrow{margin-top:10px;padding:10px 14px;border-radius:10px;border:1px dashed #cbd5e3;background:transparent;color:#5a6b82;font-size:12.5px;font-weight:700;cursor:pointer}
.pob-addrow:hover{border-color:#0ea8c9;color:#0ea8c9}
.dl-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:11px;border:1px solid rgba(14,168,201,.3);background:rgba(14,168,201,.1);color:#0ea8c9;font-weight:600;font-size:13px;text-decoration:none}
.drop{display:block;border:2px dashed #cbd5e3;border-radius:14px;padding:26px;text-align:center;cursor:pointer;background:#ffffff}
.file-hidden{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.ptable{width:100%;border-collapse:collapse;font-size:12.5px;min-width:820px}
.ptable thead tr{text-align:left;color:#8a97ab;font-size:11px;text-transform:uppercase}
.ptable th{padding:5px 8px}.ptable td{padding:3px 8px;border-top:1px solid #f6f8fc;color:#152033;vertical-align:top}
.tag{padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.tag.exact{background:rgba(22,163,74,.16);color:#16a34a}
.tag.confirm{background:rgba(217,119,6,.16);color:#d97706}
.tag.none,.tag.error{background:rgba(224,67,93,.16);color:#b8283f}
.chip{padding:10px 16px;border-radius:12px}
</style>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped
         under .zskin, so this one attribute is the whole of the restyle and
         removing it puts the page back exactly as it was. The page keeps its
         own .zp-card / .zp-t / .zp-b names; the skin maps onto them. */ ?>
<div class="zskin">
<link rel="stylesheet" href="assets/css/lov.css">
<script src="assets/js/lov.js"></script>
<script src="assets/js/grid.js"></script>

<div class="topbar"><div><h1>Set Quantities &mdash; CSV</h1><p class="lead">How many of each part go in one set, per size. Export, edit in Excel, send it back &mdash; for every product at once. Operations and rates are not here: they come from the Part Library or the Product Master grid.</p></div>
  <a class="zbtn sec" href="product_master.php">← Product Master</a></div>

<?php if ($stage === 'upload'): ?>
<?php /* The set-quantity grid lives on Product Master and only appears once
         a product HAS parts — and a product only has parts once an operation
         names one. Nothing said so before, which is why that grid looks
         missing when you go looking for it first. */ ?>
<div class="zcard" style="background:rgba(109,91,208,.06);border-color:rgba(109,91,208,.24)">
  <div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
    <div style="flex:1;min-width:260px">
      <b style="font-size:13px">How many of each part are in a set?</b>
      <p style="font-size:12.5px;color:#5a6b82;margin:5px 0 0;line-height:1.6">
        A Double bed set has 2 pillow cases and 1 bed sheet; a Single has 1 of each. That
        lives on <b>Product Master → edit the product</b>, in a sizes &times; parts grid —
        or in the set-quantities CSV under the Import tab.
        <br><b>Do operations first.</b> That grid only appears once the product has parts,
        and a product only has parts once an operation here names one.</p>
    </div>
    <a class="zbtn sec" href="product_master.php">Open Product Master</a>
  </div>
</div>
<?php endif; ?>

<?php if ($stage === 'done'): ?>
<div class="zcard" style="border-color:rgba(22,163,74,.28);background:rgba(22,163,74,.08);color:#127a3f;font-weight:700"><?= e($done) ?></div>
<div class="zcard"><a class="zbtn sec" href="production_ops_bulk.php">Import More</a> <a class="zbtn sec" style="margin-left:8px" href="product_master.php">Back to Product Master</a></div>

<?php elseif ($stage === 'preview' && $importKind === 'qty'): ?>
<?php /* Set quantities get their own preview. Same shape as the operations
         one so it is familiar, but its own columns and its own errors —
         a screen that changes meaning depending on which file you picked
         is how the wrong file gets imported without anyone noticing. */ ?>
<div class="zcard">
  <h2 style="margin-bottom:12px">Set Quantities — preview</h2>
  <?php if ($err): ?><div style="color:#b8283f;margin-bottom:14px"><?= e($err) ?></div><?php endif; ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
    <div class="chip" style="background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.24)"><b style="font-size:20px;color:#16a34a"><?= $cntNew ?></b> <span style="font-size:12.5px;color:#5a6b82">ready to save</span></div>
    <div class="chip" style="background:rgba(224,67,93,.1);border:1px solid rgba(224,67,93,.24)"><b style="font-size:20px;color:#e0435d"><?= $cntErr ?></b> <span style="font-size:12.5px;color:#5a6b82">have an error</span></div>
  </div>
  <p style="font-size:12px;color:#8a97ab;margin:0 0 14px">A quantity row is matched by the product's <b>exact</b> name and one of its existing sizes — no guessing, because writing 2 pillow cases onto the wrong size is not something you would spot later. Fix any red row in the file and upload again. Nothing is saved until you click Save below.</p>
  <form method="post" onsubmit="if(this.dataset.sent)return false;this.dataset.sent='1';">
    <?= csrf_field() ?><input type="hidden" name="action" value="qty_commit">
    <div style="overflow-x:auto;margin-bottom:18px"><table class="ptable">
      <thead><tr><th>Row</th><th>Product</th><th>Size</th><th>Part</th><th class="num">Qty / set</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $blocked = ($r['err'] ?? '') !== ''; ?>
      <tr>
        <td style="color:#8a97ab"><?= (int)$r['n'] ?></td>
        <td><?= e($r['product_name'] ?? '') ?></td>
        <td><?= e($r['size'] ?? '') ?></td>
        <td><?= e($r['component'] ?? '') ?></td>
        <td class="num"><?= $blocked ? '—' : e(rtrim(rtrim(number_format((float)$r['qty'], 2, '.', ''), '0'), '.')) ?></td>
        <td><?php if ($blocked): ?><span class="tag error">Error</span><div style="font-size:11px;color:#b8283f;margin-top:3px"><?= e($r['err']) ?></div>
            <?php else: ?><span class="tag exact">Ready</span><?php endif; ?></td>
        <?php if (!$blocked): ?>
        <input type="hidden" name="q_pid[]" value="<?= (int)$r['pid'] ?>">
        <input type="hidden" name="q_size[]" value="<?= e($r['size']) ?>">
        <input type="hidden" name="q_comp[]" value="<?= e($r['component']) ?>">
        <input type="hidden" name="q_qty[]" value="<?= e($r['qty']) ?>">
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><button class="zbtn" <?= $cntNew ? '' : 'disabled' ?>>Save <?= (int)$cntNew ?> Quantit<?= $cntNew===1?'y':'ies' ?></button><a class="zbtn sec" href="production_ops_bulk.php">Cancel</a></div>
  </form>
</div>

<?php /* The operations-import preview branch stood here. It went with the
         operations CSV itself — see the note above pob_send_csv(). The set
         quantities preview above is now the only one. */ ?>

<?php else: ?>
<!-- no longer a tab: this page IS the CSV round-trip -->
<div id="pobPaneCsv">
  <div class="zcard">
    <h2 style="margin-bottom:6px">Set quantities, in and out of Excel</h2>
    <p style="color:#5a6b82;font-size:12.5px;margin:0 0 6px;line-height:1.6">
      This page does one job: the round trip for <b>how many of each part go in one set, per size</b>.
      Thirty products across six sizes is a hundred and eighty numbers, and Excel is faster at those
      than clicking.</p>
    <?php
    /* Counted on THIS page load, so you can see what the file should contain
       before you open it. A downloaded file that disagrees with these numbers
       is a stale download, not a stale query — see pob_send_csv(). */
    $pobLiveProd = $pobLiveSizes = $pobLiveQty = 0;
    try {
        $pobLiveProd  = (int)db()->query("SELECT COUNT(*) FROM products WHERE is_active=1")->fetchColumn();
        $pobLiveSizes = (int)db()->query(
            "SELECT COUNT(*) FROM product_sizes s JOIN products p ON p.id=s.product_id
             WHERE p.is_active=1")->fetchColumn();
        $pobLiveQty   = (int)db()->query("SELECT COUNT(*) FROM product_component_qty")->fetchColumn();
    } catch (Throwable $e) {}
    ?>
    <div style="background:#f6f8fc;border:1px solid #e3e9f2;border-radius:10px;padding:9px 12px;
                margin:12px 0;font-size:12.5px;color:#33415c">
      <b>Right now:</b>
      <b style="font-family:monospace"><?= $pobLiveProd ?></b> active products &middot;
      <b style="font-family:monospace"><?= $pobLiveSizes ?></b> size variants &middot;
      <b style="font-family:monospace"><?= $pobLiveQty ?></b> quantities set.
      <div style="color:#8a97ab;font-size:11.5px;margin-top:4px">
        Counted as this page loaded. If a downloaded file disagrees with these numbers, it is an old
        download &mdash; reload and press the button again.</div>
    </div>
  </div>

  <?php /* WHERE OPERATIONS AND RATES COME FROM NOW.
           They used to have an export and an import on this page. Both are
           gone: the file had no Size column, so a product priced per size
           exported as several identical-looking rows, and every row imported
           as new landed on "All sizes" whatever you meant. Two routes remain,
           and both understand sizes properly. */ ?>
  <div class="zcard" style="background:rgba(14,168,201,.06);border-color:rgba(14,168,201,.3)">
    <h2 style="margin-bottom:6px">Looking for operations and rates?</h2>
    <p style="color:#33415c;font-size:12.5px;margin:0 0 12px;line-height:1.6">
      They are not on this page any more. The CSV here had <b>no Size column</b>, so a product priced
      per size came out as several rows that looked identical apart from the rate &mdash; and any row
      added from a file always landed on &ldquo;All sizes&rdquo;, whichever size you meant.
      <br>Two places do it correctly instead:</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a class="zbtn sec" href="part_library.php">Part Library &mdash; define a part once, CSV in and out</a>
      <a class="zbtn sec" href="product_master.php">Product Master &mdash; the grid, paste a block from Excel</a>
    </div>
  </div>


  <div class="zcard">
    <h2 style="margin-bottom:6px">3 &middot; Set quantities — how many of each part are in a set</h2>
    <p style="color:#5a6b82;font-size:12.5px;margin:0 0 14px;line-height:1.6">
      A Double bed set has <b>2 pillow cases and 1 bed sheet</b>; a Single has 1 of each. That
      is this file. It is <b>separate from operations on purpose</b>: an operation is one row
      per product + part and does not care about size, while a quantity is one row per product
      + size + part and does not care about operations. Forcing both into one sheet makes every
      operation repeat once per size, and two copies that disagree about a rate cannot be
      resolved.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
      <a class="zbtn sec" href="production_ops_bulk.php?download=quantities">Export set quantities</a>
      <a class="zbtn sec" href="product_master.php">Edit them one product at a time</a>
    </div>
    <p style="color:#8a97ab;font-size:11.5px;margin:0 0 16px;line-height:1.6">
      The export lists every size &times; part combination, not only the ones already saved —
      an unsaved combination behaves as <b>1</b>, so this is the only way to see what the
      system currently believes. <b>A product only appears here once it has operations with a
      Part filled in</b>, so do step 2 first if this file comes back empty.</p>
    <form method="post" enctype="multipart/form-data" onsubmit="if(this.dataset.sent)return false; var f=this.querySelector('input[type=file]'); if(!f.files.length){alert('Please choose a CSV file first.');return false;} this.dataset.sent='1';">
      <?= csrf_field() ?><input type="hidden" name="action" value="qty_preview">
      <label class="drop">
        <input type="file" name="csv" accept=".csv" class="file-hidden" onchange="var l=this.parentNode.querySelector('.fl');if(l&&this.files[0])l.textContent=this.files[0].name">
        <div class="fl" style="font-size:14px;font-weight:600">Choose set-quantities CSV</div><div style="font-size:12px;color:#8a97ab;margin-top:3px">Product Name, Size, Part, Qty Per Set</div>
      </label>
      <div style="margin-top:18px"><button class="zbtn">Preview Quantities</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
