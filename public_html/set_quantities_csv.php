<?php
/*
  SET QUANTITIES CSV — how many of each part go into one set, per size.
  =====================================================================

  For every product at once, out to Excel and back. This is the grid at the
  bottom of Master Products, for all products in one file.

  THE COLUMN THAT MAKES THIS SAFE IS "Size".

  The old operations CSV had no size column. A product priced per size exported
  as rows that looked identical, and came back applied to "all sizes" whatever
  anybody meant. That file is why this module was rebuilt, so this one names the
  size on every single row, and refuses a row whose size it cannot find.

  IT NEVER CREATES ANYTHING. Not a product, not a size, not a part. It only sets
  quantities on combinations that already exist. A typo therefore produces a
  clearly-reported skipped row, never a phantom "Duoble" size with your
  quantities silently attached to it.

  ALL OR NOTHING. If any row fails, nothing is written.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Product Master access required.'); }
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();

const SQ_COLS = ['Product ID', 'Product Name', 'Size', 'Part ID', 'Part Name', 'Qty Per Set'];

/* ---------------- export ---------------- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="set_quantities_' . date('Y-m-d_Hi') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, SQ_COLS);
    $any = false;
    foreach (zp_products() as $p) {
        $pid   = (int)$p['id'];
        $sizes = zp_sizes($pid);
        $parts = zp_product_parts($pid);
        if (!$sizes || !$parts) continue;
        $map = zp_qty_map($pid);
        foreach ($sizes as $s) {
            foreach ($parts as $pt) {
                $cid = (int)$pt['id'];
                fputcsv($out, zp_csv_row([$pid, $p['name'], $s['size_label'], zp_part_code($cid), $pt['part_name'],
                               rtrim(rtrim(number_format(zp_qty_for($map, $cid, (int)$s['id']), 2, '.', ''), '0'), '.')]));
                $any = true;
            }
        }
    }
    if (!$any) fputcsv($out, ['', 'Add sizes and parts to a product first', '', '', '', '']);
    fclose($out);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$msg = ''; $err = ''; $report = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $text = '';
    if (isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $text = (string)file_get_contents($_FILES['csv']['tmp_name']);
    }
    if (trim($text) === '') {
        $err = 'No file was chosen, or it was empty.';
    } else {
        if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
        $lines = array_values(array_filter(explode("\n", str_replace(["\r\n", "\r"], "\n", $text)),
                                           fn($l) => trim($l) !== ''));
        if (count($lines) > 5000) {
            $err = 'That file has more than 5000 rows. Split it and import in pieces.';
        } else {
            $head = str_getcsv(array_shift($lines));
            $map = [];
            foreach ($head as $i => $h) {
                $h = mb_strtolower(trim((string)$h));
                if ($h === 'product id')                       $map[$i] = 'pid';
                elseif ($h === 'product name')                 $map[$i] = 'pname';
                elseif ($h === 'size')                         $map[$i] = 'size';
                elseif ($h === 'part id')                      $map[$i] = 'partid';
                elseif ($h === 'part name')                    $map[$i] = 'partname';
                elseif ($h === 'qty per set' || $h === 'qty')  $map[$i] = 'qty';
            }
            if (!in_array('size', $map, true) || !in_array('qty', $map, true)) {
                $err = 'That file needs a "Size" column and a "Qty Per Set" column. Download one first to see the shape.';
            } else {
                /* ---- resolve every row BEFORE writing anything ---- */
                $plan = []; $bad = 0;
                foreach ($lines as $n => $line) {
                    $cells = str_getcsv($line);
                    $r = [];
                    /* and strip the export's formula-guard quote back off */
                    foreach ($map as $i => $k) $r[$k] = zp_csv_unquote(trim((string)($cells[$i] ?? '')));
                    $row = ['line' => $n + 2, 'what' => '', 'err' => ''];

                    /* the product: by id, then by name */
                    $pid = (int)($r['pid'] ?? 0);
                    if ($pid > 0) {
                        $c = db()->prepare("SELECT id, name FROM products WHERE id=?"); $c->execute([$pid]);
                        $p = $c->fetch();
                        if (!$p) $pid = 0;
                    }
                    if (!$pid && trim((string)($r['pname'] ?? '')) !== '') {
                        $c = db()->prepare("SELECT id, name FROM products WHERE name=?"); $c->execute([$r['pname']]);
                        $p = $c->fetch();
                        $pid = $p ? (int)$p['id'] : 0;
                    }
                    if (!$pid) { $row['err'] = 'No product matches "' . ($r['pname'] ?: $r['pid'] ?: 'blank') . '".';
                                 $plan[] = $row; $bad++; continue; }
                    $row['product'] = $p['name'] ?? ('#' . $pid);

                    /* the size — matched on this product only, never created */
                    $sizeId = 0;
                    foreach (zp_sizes($pid) as $s) {
                        if (mb_strtolower($s['size_label']) === mb_strtolower((string)$r['size'])) { $sizeId = (int)$s['id']; break; }
                    }
                    if (!$sizeId) {
                        $row['err'] = 'Size "' . $r['size'] . '" is not a size of ' . $row['product']
                                    . '. Add it on Master Products first — this file never creates a size.';
                        $plan[] = $row; $bad++; continue;
                    }
                    $row['size'] = $r['size'];

                    /* the part — must already be ON this product */
                    $partId = zp_part_id_from((string)($r['partid'] ?? ''));
                    $onProd = null;
                    foreach (zp_product_parts($pid) as $pp) {
                        if ($partId && (int)$pp['id'] === $partId) { $onProd = $pp; break; }
                        if (!$partId && mb_strtolower($pp['part_name']) === mb_strtolower((string)($r['partname'] ?? ''))) {
                            $onProd = $pp; $partId = (int)$pp['id']; break;
                        }
                    }
                    if (!$onProd) {
                        $row['err'] = 'Part "' . ($r['partname'] ?: $r['partid'] ?: 'blank') . '" is not on '
                                    . $row['product'] . '. Add it on Master Products first.';
                        $plan[] = $row; $bad++; continue;
                    }
                    $row['part'] = $onProd['part_name'];

                    $qty = str_replace(',', '', (string)($r['qty'] ?? ''));
                    if ($qty === '' || !is_numeric($qty) || (float)$qty < 0) {
                        $row['err'] = 'Qty Per Set must be a number of 0 or more.';
                        $plan[] = $row; $bad++; continue;
                    }
                    $row['qty'] = round((float)$qty, 2);
                    $row['pid'] = $pid; $row['part_id'] = $partId; $row['size_id'] = $sizeId;
                    $row['what'] = 'set';
                    $plan[] = $row;
                }

                if ($bad > 0) {
                    $err = $bad . ' row' . ($bad === 1 ? '' : 's') . ' could not be matched, so NOTHING was imported — '
                         . 'your quantities are exactly as they were. Fix the rows listed below and try again.';
                    $report = $plan;
                } else {
                    $byProduct = [];
                    foreach ($plan as $p2) $byProduct[$p2['pid']][$p2['part_id']][$p2['size_id']] = $p2['qty'];
                    $ok = true;
                    db()->beginTransaction();
                    try {
                        foreach ($byProduct as $pid2 => $q) {
                            $r2 = zp_save_qty((int)$pid2, $q);
                            if (!$r2['ok']) { $ok = false; break; }
                        }
                        if ($ok) db()->commit(); else db()->rollBack();
                    } catch (Throwable $e) { db()->rollBack(); $ok = false; }
                    /* Quantity-per-set is half of the workmanship sum (rate x
                       qty), so Costing has to be re-fed after an import or a
                       Double pillow would still cost as one. Outside the
                       transaction on purpose: the quantities are already
                       committed, and a bridge failure must not roll them back. */
                    if ($ok) foreach (array_keys($byProduct) as $pid2) zp_bridge_sync_product((int)$pid2);
                    if ($ok) {
                        $msg = count($plan) . ' quantit' . (count($plan) === 1 ? 'y' : 'ies') . ' set across '
                             . count($byProduct) . ' product' . (count($byProduct) === 1 ? '' : 's') . '.';
                        $report = $plan;
                        try { audit_log(0, 'Set Quantities CSV', 'import', '', count($plan) . ' rows', 'CSV import'); } catch (Throwable $e) {}
                    } else {
                        $err = 'Nothing was imported — the database refused the file, so your quantities are exactly as they were.';
                    }
                }
            }
        }
    }
}

$nProd = 0;
foreach (zp_products() as $p) if (zp_sizes((int)$p['id']) && zp_product_parts((int)$p['id'])) $nProd++;

page_header('Set Quantities CSV');
?>
<style>
.zs-wrap{max-width:1020px}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:8px 15px;border-radius:9px;border:1px solid #d9e0ea;
      background:#fff;color:#33465f;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}.zp-b.pri:hover{background:#1667c9;color:#fff}
.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:17px 18px;margin-bottom:16px;
         box-shadow:0 1px 2px rgba(20,35,60,.04)}
.zp-card h2{margin:0 0 4px;font-size:15.5px;color:#152033}
.zp-card p.sub{margin:0 0 13px;font-size:12.5px;color:#8a97ab;line-height:1.5}
.flash{padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:15px}
.flash.ok{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.flash.bad{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}
.note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55}
.note.info{background:#eef6ff;border:1px solid #cfe3fb;color:#28527d}
.note.warn{background:#fff6e8;border:1px solid #f3ddb8;color:#8a5a10}
.note ul{margin:6px 0 0;padding-left:18px}
.drop{border:2px dashed #cfd8e4;border-radius:11px;padding:20px;text-align:center;color:#8a97ab;font-size:13px;cursor:pointer}
.drop:hover{border-color:#0ea8c9;color:#0b7f99;background:#f7fdff}
table.zp-t{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:12px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;
              font-weight:800;padding:7px 8px;border-bottom:1px solid #e6ebf2}
table.zp-t td{padding:6px 8px;border-bottom:1px solid #f1f4f9}
.tag{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:800}
.tag.ok{background:rgba(22,163,74,.13);color:#15803d}
.tag.bad{background:#fdeef1;color:#9c2740}
code{background:#f2f5f9;padding:1px 5px;border-radius:4px;font-size:11.5px}
.num{text-align:right;font-variant-numeric:tabular-nums;font-family:ui-monospace,monospace}
</style>

<div class="zs-wrap">
  <div class="topbar"><div>
    <h1>Set Quantities CSV</h1>
    <p class="lead">How many of each part go into one set, per size &mdash; for every product at once.
      <b><?= number_format($nProd) ?></b> product<?= $nProd === 1 ? '' : 's' ?> ready to export.</p>
  </div></div>

  <?php if ($msg): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash bad"><?= e($err) ?></div><?php endif; ?>

  <div class="zp-card">
    <h2>Take it out</h2>
    <p class="sub">One row per <b>product &times; size &times; part</b>. Edit the
      <code>Qty Per Set</code> column and put it straight back.</p>
    <a class="zp-b pri" href="set_quantities_csv.php?export=csv">Download set quantities</a>
  </div>

  <div class="zp-card">
    <h2>Put it back</h2>
    <p class="sub">Expected columns: <code><?= e(implode('</code>, <code>', SQ_COLS)) ?></code>.
      Matched by name, so moving them around in Excel is fine.</p>

    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="drop" onclick="document.getElementById('sqFile').click()" id="dropZone">
        Choose a CSV file, or drop one here
        <div id="sqName" style="margin-top:6px;font-weight:700;color:#152033"></div>
      </div>
      <input type="file" name="csv" id="sqFile" accept=".csv,.txt" style="display:none">
      <div style="margin-top:13px"><button class="zp-b pri" id="sqBtn" disabled>Import this file</button></div>
    </form>

    <div class="note warn" style="margin-top:14px">
      <b>This file never creates anything.</b>
      <ul>
        <li>Not a product, not a size, not a part &mdash; it only sets quantities on combinations that already exist.</li>
        <li>A size or part it cannot find is <b>reported as a skipped row</b>, never invented. A typo can therefore
            not produce a phantom "Duoble" size with your quantities quietly attached to it.</li>
        <li><b>All or nothing.</b> If any row fails to match, <b>nothing</b> is written and your quantities stay
            exactly as they were.</li>
      </ul>
    </div>
  </div>

  <?php if ($report): ?>
  <div class="zp-card">
    <h2>What the file said</h2>
    <table class="zp-t">
      <tr><th style="width:56px">Line</th><th style="width:90px">Result</th><th>Product</th>
        <th style="width:110px">Size</th><th>Part</th><th class="num" style="width:80px">Qty</th></tr>
      <?php foreach ($report as $r): ?>
        <tr>
          <td><?= (int)$r['line'] ?></td>
          <td><?= $r['err'] === '' ? '<span class="tag ok">set</span>' : '<span class="tag bad">skipped</span>' ?></td>
          <td><?= e($r['product'] ?? '—') ?><?= $r['err'] !== '' ? '<div style="color:#9c2740;font-size:11px">' . e($r['err']) . '</div>' : '' ?></td>
          <td><?= e($r['size'] ?? '—') ?></td>
          <td><?= e($r['part'] ?? '—') ?></td>
          <td class="num"><?= isset($r['qty']) ? rtrim(rtrim(number_format((float)$r['qty'], 2), '0'), '.') : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <div class="note info">
    <b>Why the Size column matters so much.</b>
    The old operations CSV had no size column. A product priced per size exported as rows that looked
    identical and came back applied to every size, whatever anybody meant &mdash; which is a large part of
    why this module was rebuilt. Every row here names its size, and a row whose size cannot be found is
    refused rather than guessed at.
    <br><br>
    Operations and rates are not in this file either. They belong to the <a href="part_library.php">Part
    Library</a>, which has its own, so that one rate correction fixes every product at once.
  </div>
</div>

<script>
(function(){
  var inp = document.getElementById('sqFile'), btn = document.getElementById('sqBtn'),
      nm = document.getElementById('sqName'), zone = document.getElementById('dropZone');
  if (!inp) return;
  function show(){
    if (inp.files && inp.files.length) { nm.textContent = inp.files[0].name; btn.disabled = false; }
    else { nm.textContent = ''; btn.disabled = true; }
  }
  inp.addEventListener('change', show);
  ['dragenter','dragover'].forEach(function(ev){
    zone.addEventListener(ev, function(e){ e.preventDefault(); zone.style.borderColor = '#0ea8c9'; }); });
  ['dragleave','drop'].forEach(function(ev){
    zone.addEventListener(ev, function(e){ e.preventDefault(); zone.style.borderColor = ''; }); });
  zone.addEventListener('drop', function(e){
    if (e.dataTransfer && e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; show(); } });
})();
</script>
<?php page_footer(); ?>
