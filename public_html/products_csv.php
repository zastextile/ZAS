<?php
/*
  PRODUCTS CSV — your master product list, out to Excel and back.
  ==============================================================

  Built to read the exact file you exported:

      "Product ID","Product Name",Description,Category,"Default Unit","40ft HC Capacity",Active

  THE ONE RULE THAT MATTERS: THIS NEVER DELETES A PRODUCT.

  Import adds and updates. A product that is in the database but NOT in the file
  is left exactly where it is. A file is a snapshot of what somebody exported at
  some moment — it is not an instruction to make the database match it. Your
  product names are used all over costing and invoicing; a "sync" that removed
  the ones missing from a file would quietly break every document pointing at
  them, and there would be no way back.

  MATCHING ORDER: Product ID first, then Product Name, then it is new.
  Matching on the id first is what makes this a round trip — rename a product in
  the file, re-import, and it is RENAMED rather than duplicated.

  COLUMNS ARE MATCHED BY NAME, NOT POSITION, so a file whose columns were moved
  around in Excel still imports correctly.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Product Master access required.'); }
require_once __DIR__ . '/includes/zprod.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

const PC_COLS = ['Product ID', 'Product Name', 'Description', 'Category', 'Default Unit', '40ft HC Capacity', 'Active'];

/* ---------------- export: headers first, so before any output ---------------- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="master_products_' . date('Y-m-d_Hi') . '.csv"');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, PC_COLS);
    $rows = [];
    try {
        $rows = db()->query("SELECT id, name, description, category, default_unit, fcl_40hc_qty, is_active
                             FROM products ORDER BY name")->fetchAll();
    } catch (Throwable $e) {}
    if (!$rows) $rows[] = ['id' => '', 'name' => '', 'description' => '', 'category' => '',
                           'default_unit' => 'Pcs', 'fcl_40hc_qty' => '', 'is_active' => 1];
    foreach ($rows as $r) {
        fputcsv($out, zp_csv_row([
            $r['id'], $r['name'], $r['description'], $r['category'], $r['default_unit'],
            $r['fcl_40hc_qty'] !== null ? rtrim(rtrim(number_format((float)$r['fcl_40hc_qty'], 2, '.', ''), '0'), '.') : '',
            $r['is_active'] ? 'Yes' : 'No',
        ]));
    }
    fclose($out);
    exit;
}

$me     = current_user();
$userId = (int)($me['id'] ?? 0);
$msg = ''; $err = ''; $report = [];

/* Excel on Windows writes cp1252; a stray £ or a curly quote becomes invalid
   UTF-8 and the whole row is rejected downstream. Clean per cell. */
function pc_utf8(string $s): string {
    if ($s === '' || mb_check_encoding($s, 'UTF-8')) return $s;
    return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}
function pc_num($v): ?float {
    $v = trim(str_replace(',', '', (string)$v));
    if ($v === '') return null;
    return is_numeric($v) ? (float)$v : null;
}
function pc_yes($v): int {
    $v = mb_strtolower(trim((string)$v));
    if ($v === '') return 1;                       // a blank Active column means active
    return in_array($v, ['no', 'n', '0', 'false', 'inactive'], true) ? 0 : 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'import') {
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
            if (count($lines) > 1000) {
                $err = 'That file has more than 1000 products. Split it and import in pieces.';
            } else {
                $head  = str_getcsv(array_shift($lines));
                $map   = [];
                foreach ($head as $i => $h) {
                    $h = mb_strtolower(trim(pc_utf8((string)$h)));
                    if ($h === 'product id')                            $map[$i] = 'id';
                    elseif ($h === 'product name' || $h === 'name')     $map[$i] = 'name';
                    elseif ($h === 'description')                       $map[$i] = 'description';
                    elseif ($h === 'category')                          $map[$i] = 'category';
                    elseif ($h === 'default unit' || $h === 'unit')     $map[$i] = 'default_unit';
                    elseif ($h === '40ft hc capacity' || $h === 'capacity') $map[$i] = 'fcl';
                    elseif ($h === 'active')                            $map[$i] = 'active';
                }
                if (!in_array('name', $map, true)) {
                    $err = 'No "Product Name" column in that file. Export one first to see the shape it expects.';
                } else {
                    $plan = [];
                    foreach ($lines as $n => $line) {
                        $cells = str_getcsv($line);
                        $r = [];
                        /* strip the single quote the export adds to a cell that would
                           otherwise be read by Excel as a formula — see zp_csv_cell() */
                        foreach ($map as $i => $k) $r[$k] = zp_csv_unquote(pc_utf8((string)($cells[$i] ?? '')));
                        $name = trim((string)($r['name'] ?? ''));
                        if ($name === '') { $plan[] = ['row' => $n + 2, 'how' => 'skipped', 'name' => '(blank)',
                                                        'err' => 'Product name is required.']; continue; }
                        $id = (int)($r['id'] ?? 0);
                        $how = 'new'; $matchId = 0;
                        if ($id > 0) {
                            $c = db()->prepare("SELECT id FROM products WHERE id=?"); $c->execute([$id]);
                            if ((int)$c->fetchColumn() === $id) { $how = 'update by id'; $matchId = $id; }
                        }
                        if (!$matchId) {
                            $c = db()->prepare("SELECT id FROM products WHERE name=?"); $c->execute([$name]);
                            $f = (int)$c->fetchColumn();
                            if ($f) { $how = 'update by name'; $matchId = $f; }
                        }
                        $plan[] = ['row' => $n + 2, 'how' => $how, 'id' => $matchId, 'name' => $name,
                                   'description' => trim((string)($r['description'] ?? '')),
                                   'category' => trim((string)($r['category'] ?? '')),
                                   'default_unit' => trim((string)($r['default_unit'] ?? '')) ?: 'Pcs',
                                   'fcl' => pc_num($r['fcl'] ?? ''), 'active' => pc_yes($r['active'] ?? ''),
                                   'err' => ''];
                    }

                    /* ALL OR NOTHING. A half-imported product list is worse than
                       no import: you cannot tell which half is which. */
                    $added = 0; $upd = 0; $skipped = 0;
                    db()->beginTransaction();
                    try {
                        $ins = db()->prepare("INSERT INTO products (name, description, category, default_unit, fcl_40hc_qty, is_active)
                                              VALUES (?,?,?,?,?,?)");
                        $up  = db()->prepare("UPDATE products SET name=?, description=?, category=?, default_unit=?, fcl_40hc_qty=?, is_active=? WHERE id=?");
                        foreach ($plan as &$p) {
                            if ($p['err'] !== '') { $skipped++; continue; }
                            if ($p['how'] === 'new') {
                                $ins->execute([$p['name'], $p['description'], $p['category'], $p['default_unit'], $p['fcl'], $p['active']]);
                                $p['id'] = (int)db()->lastInsertId();
                                $added++;
                            } else {
                                $up->execute([$p['name'], $p['description'], $p['category'], $p['default_unit'], $p['fcl'], $p['active'], $p['id']]);
                                $upd++;
                            }
                        }
                        unset($p);
                        db()->commit();
                        $report = $plan;
                        $msg = "$added product" . ($added === 1 ? '' : 's') . " added, $upd updated"
                             . ($skipped ? ", $skipped skipped" : '') . '. Nothing was deleted.';
                        try { audit_log(0, 'Products CSV', 'import', '', "added $added, updated $upd", 'CSV import'); } catch (Throwable $e) {}
                    } catch (Throwable $e) {
                        db()->rollBack();
                        $err = 'Nothing was imported — the database refused the file, so your product list is exactly as it was. ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

$count = 0;
try { $count = (int)db()->query("SELECT COUNT(*) FROM products")->fetchColumn(); } catch (Throwable $e) {}

page_header('Products CSV');
?>
<style>
.pc-wrap{max-width:1000px}
.pc-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:17px 18px;margin-bottom:16px;
         box-shadow:0 1px 2px rgba(20,35,60,.04)}
.pc-card h2{margin:0 0 4px;font-size:15.5px;color:#152033}
.pc-card p.sub{margin:0 0 13px;font-size:12.5px;color:#8a97ab;line-height:1.5}
.pc-b{display:inline-flex;align-items:center;gap:6px;padding:8px 15px;border-radius:9px;border:1px solid #d9e0ea;
      background:#fff;color:#33465f;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none}
.pc-b:hover{border-color:#0ea8c9;color:#0b7f99}
.pc-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}
.pc-b.pri:hover{background:#1667c9;color:#fff}
.pc-flash{padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:15px}
.pc-flash.ok{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.pc-flash.bad{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}
.pc-note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55;background:#eef6ff;
         border:1px solid #cfe3fb;color:#28527d}
.pc-note.warn{background:#fff6e8;border-color:#f3ddb8;color:#8a5a10}
.pc-note ul{margin:6px 0 0;padding-left:18px}
table.pc-t{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:12px}
table.pc-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;
              font-weight:800;padding:7px 8px;border-bottom:1px solid #e6ebf2}
table.pc-t td{padding:6px 8px;border-bottom:1px solid #f1f4f9}
.tag{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10.5px;font-weight:800}
.tag.new{background:rgba(22,163,74,.13);color:#15803d}
.tag.upd{background:rgba(29,118,226,.12);color:#1667c9}
.tag.skip{background:#fdeef1;color:#9c2740}
.drop{border:2px dashed #cfd8e4;border-radius:11px;padding:20px;text-align:center;color:#8a97ab;font-size:13px;cursor:pointer}
.drop:hover{border-color:#0ea8c9;color:#0b7f99;background:#f7fdff}
code{background:#f2f5f9;padding:1px 5px;border-radius:4px;font-size:11.5px}
</style>

<div class="pc-wrap">
  <div class="topbar"><div>
    <h1>Products CSV</h1>
    <p class="lead">Your master product list, out to Excel and back. <b><?= number_format($count) ?></b> products right now.</p>
  </div></div>

  <?php if ($msg): ?><div class="pc-flash ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="pc-flash bad"><?= e($err) ?></div><?php endif; ?>

  <div class="pc-card">
    <h2>Take it out</h2>
    <p class="sub">One row per product, with its Product ID. Keep the ID column and you can edit the file
       and put it straight back — the same products are updated instead of duplicated.</p>
    <a class="pc-b pri" href="products_csv.php?export=csv">Download products CSV</a>
  </div>

  <div class="pc-card">
    <h2>Put it back</h2>
    <p class="sub">Columns are matched by <b>name</b>, so a file with its columns moved around still works.
       Expected: <code><?= e(implode('</code>, <code>', PC_COLS)) ?></code>.</p>

    <form method="post" enctype="multipart/form-data" id="impForm">
      <?= csrf_field() ?><input type="hidden" name="action" value="import">
      <div class="drop" onclick="document.getElementById('pcFile').click()" id="dropZone">
        Choose a CSV file, or drop one here
        <div id="pcName" style="margin-top:6px;font-weight:700;color:#152033"></div>
      </div>
      <input type="file" name="csv" id="pcFile" accept=".csv,.txt" style="display:none">
      <div style="margin-top:13px"><button class="pc-b pri" id="impBtn" disabled>Import this file</button></div>
    </form>

    <div class="pc-note warn" style="margin-top:14px">
      <b>Import never deletes anything.</b>
      <ul>
        <li>A row whose <b>Product ID</b> matches updates that product — so a rename in the file is a rename, not a duplicate.</li>
        <li>No ID, but the <b>name</b> matches? That product is updated.</li>
        <li>Neither matches? It is added as a new product.</li>
        <li><b>A product missing from the file is left exactly where it is.</b> Your names are used across costing and
            invoicing, and a file is a snapshot — never an instruction to delete what is not in it.</li>
        <li>It is all or nothing: if any row fails, <b>nothing</b> is written and your list is untouched.</li>
      </ul>
    </div>
  </div>

  <?php if ($report): ?>
  <div class="pc-card">
    <h2>What the import did</h2>
    <p class="sub">Line by line, so you can see exactly what happened to each row.</p>
    <table class="pc-t">
      <tr><th style="width:60px">Line</th><th style="width:110px">Result</th><th>Product</th><th style="width:80px">ID</th></tr>
      <?php foreach ($report as $r): ?>
        <tr>
          <td><?= (int)$r['row'] ?></td>
          <td><?php
            if ($r['how'] === 'new')              echo '<span class="tag new">added</span>';
            elseif (str_starts_with($r['how'], 'update')) echo '<span class="tag upd">updated</span>';
            else                                  echo '<span class="tag skip">skipped</span>';
          ?></td>
          <td><?= e($r['name']) ?><?= $r['err'] !== '' ? ' — <span style="color:#9c2740">' . e($r['err']) . '</span>' : '' ?></td>
          <td><?= !empty($r['id']) ? (int)$r['id'] : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <div class="pc-note">
    <b>Sizes and parts are deliberately not in this file.</b>
    A product has many sizes and many parts, so they cannot sit on one product row without repeating the product
    on every line — which is exactly the shape that made the old operations CSV impossible to read and easy to
    corrupt. Sizes and parts are edited on <a href="product_master.php">Master Products</a>, and parts have their own
    file in the <a href="part_library.php">Part Library</a>.
  </div>
</div>

<script>
(function(){
  var inp = document.getElementById('pcFile'), btn = document.getElementById('impBtn'),
      nm = document.getElementById('pcName'), zone = document.getElementById('dropZone');
  if (!inp) return;
  function show(){
    if (inp.files && inp.files.length) { nm.textContent = inp.files[0].name; btn.disabled = false; }
    else { nm.textContent = ''; btn.disabled = true; }
  }
  inp.addEventListener('change', show);
  ['dragenter','dragover'].forEach(function(ev){
    zone.addEventListener(ev, function(e){ e.preventDefault(); zone.style.borderColor = '#0ea8c9'; });
  });
  ['dragleave','drop'].forEach(function(ev){
    zone.addEventListener(ev, function(e){ e.preventDefault(); zone.style.borderColor = ''; });
  });
  zone.addEventListener('drop', function(e){
    if (e.dataTransfer && e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; show(); }
  });
})();
</script>
<?php page_footer(); ?>
