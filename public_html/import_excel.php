<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ai_check_core.php'; // pmk(), pm_fuzzy() — Product Master cross-match
require_login();
if (is_staff() || is_production_staff()) { http_response_code(403); exit('Staff cannot import CSV data.'); }

/* ---------- helpers ---------- */
function csv_norm($v): string { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string)$v), '_')); }
function csv_num($v): float { $s = preg_replace('/[^0-9.\-]/', '', (string)$v); return is_numeric($s) ? (float)$s : 0.0; }
function csv_pick(array $row, array $keys, $default=''){ foreach($keys as $k){ if(isset($row[$k]) && trim((string)$row[$k])!=='') return trim((string)$row[$k]); } return $default; }

/* Active Product Master names, keyed by normalized name — the source of
   truth for flagging rows whose Product/Article text won't match anything
   when costing runs. */
function csv_pm_index(): array {
    $idx = [];
    try { foreach (db()->query("SELECT name FROM products WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN) as $n) $idx[pmk($n)] = $n; }
    catch (Throwable $e) {}
    return $idx;
}

/* Shipments this user may edit (not approved_locked) — carries the Optional
   Column fields too, so the upload page can show/switch the right template
   hint per shipment without a separate query per row. */
function csv_editable_shipments(): array {
    if (is_admin()) return db()->query("SELECT id,invoice_no,buyer_name,status,optional_column_enabled,optional_column_title FROM shipments WHERE status<>'approved_locked' ORDER BY id DESC LIMIT 200")->fetchAll();
    $ids = assigned_shipment_ids();
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT id,invoice_no,buyer_name,status,optional_column_enabled,optional_column_title FROM shipments WHERE status<>'approved_locked' AND id IN ($in) ORDER BY id DESC");
    $st->execute($ids);
    return $st->fetchAll();
}

/* Header name(s) a CSV can use for the Optional Column, given a shipment's
   own custom title — accepts the exact title (what the downloaded template
   uses) OR the generic "Optional Column" name, so a CSV still imports
   correctly even if the title was renamed after that CSV was downloaded. */
function csv_optional_header_keys(string $title): array {
    $keys = ['optional_column', 'optional_value', 'optional'];
    $norm = csv_norm($title);
    if ($norm !== '') array_unshift($keys, $norm);
    return array_unique($keys);
}

$tab = ($_GET['tab'] ?? $_POST['tab'] ?? 'invoice') === 'packing' ? 'packing' : 'invoice';

/* Header text for the reference-only column — kept as one constant so the
   template writer and the importer's blank-row check always agree on it. */
const CSV_REF_HEADER = 'Reference only — copy exact product name from here (do not edit this column)';

/* ---------- CSV template download ----------
   Right-hand "Reference" column lists every active Product Master name, one
   per row, purely so staff can see/copy the exact spelling while filling in
   Product/Article on the left. The importer ignores this column entirely —
   a row that only has a reference-column value (nothing in the real working
   columns) is treated as blank and skipped, same as any empty row. */
if (($_GET['download'] ?? '') !== '') {
    $which = $_GET['download'];
    $products = [];
    try { $products = db()->query("SELECT name FROM products WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}

    // Shipment-aware Optional Column — only added when a viewable, unlocked
    // shipment is given AND it actually has the column switched on; with no
    // id, or the column off, the template is byte-identical to before.
    $dlOptEnabled = false; $dlOptTitle = '';
    $dlShipId = (int)($_GET['id'] ?? 0);
    if ($dlShipId) {
        $st = db()->prepare("SELECT status, optional_column_enabled, optional_column_title FROM shipments WHERE id=?");
        $st->execute([$dlShipId]);
        $dlShip = $st->fetch();
        if ($dlShip && can_view_shipment($dlShipId) && $dlShip['status'] !== 'approved_locked' && !empty($dlShip['optional_column_enabled'])) {
            $dlOptEnabled = true;
            $dlOptTitle = trim((string)$dlShip['optional_column_title']) ?: 'Optional Column';
        }
    }

    header('Content-Type: text/csv');
    $out = fopen('php://output', 'w');
    if ($which === 'packing') {
        header('Content-Disposition: attachment; filename="zas_packing_list_template.csv"');
        $headers = ['Product Name','Description/Colour','Package Type','Package From','Package To','Qty per Package','Net Weight','Gross Weight'];
        $example = ['Patient Gown','SEHA Green Diamond','CTN',1,50,20,12.5,13.2];
    } else {
        header('Content-Disposition: attachment; filename="zas_invoice_items_template.csv"');
        $headers = ['Product/Article','Description/Colour','Category','Qty','Unit','Rate','Amount'];
        $example = ['Patient Gown','SEHA Green Diamond','Apparel',1000,'Pcs',2.85,2850];
    }
    if ($dlOptEnabled) { $headers[] = $dlOptTitle; $example[] = ''; }
    $blankRow = array_fill(0, count($headers), '');
    fputcsv($out, array_merge($headers, ['', CSV_REF_HEADER]));
    fputcsv($out, array_merge($example, ['', $products[0] ?? '']));
    for ($i = 1; $i < count($products); $i++) fputcsv($out, array_merge($blankRow, ['', $products[$i]]));
    fclose($out);
    exit;
}

$targetId = (int)($_POST['shipment_id'] ?? $_GET['id'] ?? 0);
$stage = 'upload';      // upload | preview | done
$rows = [];             // parsed+classified
$cntNew = $cntDup = $cntErr = $cntPmMiss = 0;
$errorMsg = '';
$doneMsg = '';

/* Restore a just-completed import after redirect (avoids browser "resubmit form" reprompt) */
if (($_GET['done'] ?? '') === '1' && !empty($_SESSION['import_done']) && ($_SESSION['import_done']['tab'] ?? '') === $tab) {
    $stage = 'done';
    $doneMsg = $_SESSION['import_done']['msg'];
    $targetId = (int)($_SESSION['import_done']['target_id'] ?? $targetId);
    unset($_SESSION['import_done']);
}

/* Restore a computed preview after redirect (avoids browser "resubmit form" reprompt,
   and the CSV re-upload that prompt would otherwise repeat) */
if (($_GET['preview'] ?? '') === '1' && !empty($_SESSION['import_preview'])
    && ($_SESSION['import_preview']['tab'] ?? '') === $tab
    && (int)($_SESSION['import_preview']['target_id'] ?? 0) === $targetId) {
    $stage = 'preview';
    $rows = $_SESSION['import_preview']['rows'];
    $cntNew = $_SESSION['import_preview']['cntNew'];
    $cntDup = $_SESSION['import_preview']['cntDup'];
    $cntErr = $_SESSION['import_preview']['cntErr'];
    $cntPmMiss = $_SESSION['import_preview']['cntPmMiss'] ?? 0;
}

/* Load target shipment + its existing items for dup detection */
$target = null; $existingItems = []; $existingPacks = [];
if ($targetId) {
    $st = db()->prepare("SELECT * FROM shipments WHERE id=?"); $st->execute([$targetId]); $target = $st->fetch();
    if ($target && (!can_view_shipment($targetId) || $target['status']==='approved_locked')) $target = null;
    if ($target) {
        $st = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no,id"); $st->execute([$targetId]); $existingItems = $st->fetchAll();
        $st = db()->prepare("SELECT * FROM packing_items WHERE shipment_id=? ORDER BY line_no,id"); $st->execute([$targetId]); $existingPacks = $st->fetchAll();
    }
}

/* Optional Column for the currently targeted shipment — drives the extra
   CSV column (both parsing it and showing it in the Preview table), the
   template download hint, and the commit-step INSERT/UPDATE below. */
$optEnabled = $target && !empty($target['optional_column_enabled']);
$optTitle = $optEnabled ? (trim((string)$target['optional_column_title']) ?: 'Optional Column') : '';
$optKeys = $optEnabled ? csv_optional_header_keys($optTitle) : [];

/* item lookup by normalized product name for packing */
$itemByName = [];
foreach ($existingItems as $it) $itemByName[csv_norm($it['product_name'])] = $it;

/* Product Master lookup for the costing cross-match column (invoice tab) */
$pmIndex = csv_pm_index();

/* ---------- Parse uploaded CSV → preview ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='preview') {
    verify_csrf();
    try {
        if (!$target) throw new Exception('Select a valid unlocked shipment to import into.');
        if (!isset($_FILES['csv']) || $_FILES['csv']['error']!==UPLOAD_ERR_OK) throw new Exception('Please choose a .csv file.');
        if (strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION)) !== 'csv') throw new Exception('Only .csv files are supported.');

        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        $head = fgetcsv($fh);
        if (!$head) throw new Exception('CSV is empty.');
        $map = []; foreach ($head as $i=>$h) $map[$i] = csv_norm($h);
        /* Column index(es) to leave out of the "is this row blank?" check —
           the reference-only product list (and its blank spacer) never
           counts as real data, even though it fills every row down the sheet. */
        $refKey = csv_norm(CSV_REF_HEADER);
        $ignoreCols = array_keys(array_filter($map, fn($k) => $k === $refKey || $k === ''));

        $n = 0;
        while (($data = fgetcsv($fh)) !== false) {
            $working = $data; foreach ($ignoreCols as $ci) unset($working[$ci]);
            if (count(array_filter($working, fn($x)=>trim((string)$x)!=='')) === 0) continue;
            $r = []; foreach ($map as $i=>$key) $r[$key] = $data[$i] ?? '';
            $n++;
            if ($tab==='invoice') {
                $product = csv_pick($r, ['product_article','product_name','product','article']);
                $des = csv_pick($r, ['description_colour','description_color','des_col','description','colour','color']);
                $cat = csv_pick($r, ['category','dept','department']);
                $qty = csv_num(csv_pick($r, ['qty','quantity'],'0'));
                $unit = csv_pick($r, ['unit'], 'Pcs');
                $rate = csv_num(csv_pick($r, ['rate','price'],'0'));
                $amount = csv_num(csv_pick($r, ['amount','value'],'0')); if ($amount<=0) $amount = $qty*$rate;
                $err = '';
                if ($product==='') $err = 'Product/Article is required';
                elseif ($qty<=0) $err = 'Qty must be greater than zero';
                $dup = isset($itemByName[csv_norm($product)]);
                $tag = $err ? 'error' : ($dup ? 'dup' : 'new');
                if ($tag==='error') $cntErr++; elseif ($tag==='dup') $cntDup++; else $cntNew++;
                /* Costing cross-match: does Product/Article match a live Product Master
                   name? Only this field decides costing match — Description/Colour never does. */
                $pmKey = pmk($product);
                $pmMatch = $product!=='' && isset($pmIndex[$pmKey]);
                $pmSuggest = '';
                if (!$pmMatch && $product!=='') { $cntPmMiss++; [$sk,$sscore] = pm_fuzzy($product, $pmIndex); if ($sscore>=0.6 && $sk!=='') $pmSuggest = $pmIndex[$sk]; }
                $optVal = $optEnabled ? csv_pick($r, $optKeys) : '';
                $cells = [$product,$des,$cat,number_format($qty),$unit,number_format($rate,2),number_format($amount,2)];
                if ($optEnabled) $cells[] = $optVal;
                $rows[] = ['n'=>$n,'tag'=>$tag,'err'=>$err,'cells'=>$cells,
                           'pmMatch'=>$pmMatch,'pmSuggest'=>$pmSuggest,
                           'data'=>compact('product','des','cat','qty','unit','rate','amount','optVal')];
            } else {
                $product = csv_pick($r, ['product_name','product','article']);
                $des = csv_pick($r, ['description_colour','description_color','des_col','description']);
                $type = strtoupper(csv_pick($r, ['package_type','type'],'CTN')) ?: 'CTN';
                $from = (int)csv_num(csv_pick($r, ['package_from','carton_from','from'],'0'));
                $to = (int)csv_num(csv_pick($r, ['package_to','carton_to','to'],'0'));
                $qpp = csv_num(csv_pick($r, ['qty_per_package','qty_per_carton','qty_per_unit'],'0'));
                $net = csv_num(csv_pick($r, ['net_weight','net'],'0'));
                $gross = csv_num(csv_pick($r, ['gross_weight','gross'],'0'));
                $pkgs = max(0, $to-$from+1);
                $tq = $pkgs*$qpp;
                $err = '';
                $matchItem = $itemByName[csv_norm($product)] ?? null;
                if ($product==='') $err = 'Product Name is required';
                elseif (!$matchItem) $err = 'No invoice item matches this product name';
                elseif ($from<=0 || $to<$from) $err = 'Package From/To range is invalid';
                $dupRow = false;
                foreach ($existingPacks as $ep) { if (csv_norm($ep['product_name'])===csv_norm($product) && (int)$ep['carton_from']===$from && (int)$ep['carton_to']===$to){ $dupRow=true; break; } }
                $tag = $err ? 'error' : ($dupRow ? 'dup' : 'new');
                if ($tag==='error') $cntErr++; elseif ($tag==='dup') $cntDup++; else $cntNew++;
                $optVal = $optEnabled ? csv_pick($r, $optKeys) : '';
                $cells = [$product,$type,$from,$to,number_format($pkgs),number_format($qpp),number_format($net,2),number_format($gross,2)];
                if ($optEnabled) $cells[] = $optVal;
                $rows[] = ['n'=>$n,'tag'=>$tag,'err'=>$err,'cells'=>$cells,
                           'data'=>compact('product','des','type','from','to','qpp','net','gross','pkgs','tq','optVal') + ['item_id'=>$matchItem['id'] ?? 0]];
            }
        }
        fclose($fh);
        if (!$rows) throw new Exception('No data rows found in CSV.');
        /* Redirect (PRG) so refreshing the preview page, or a slow/retried request,
           never re-prompts the browser to resubmit the file upload. */
        $_SESSION['import_preview'] = ['tab' => $tab, 'target_id' => $targetId, 'rows' => $rows, 'cntNew' => $cntNew, 'cntDup' => $cntDup, 'cntErr' => $cntErr, 'cntPmMiss' => $cntPmMiss];
        redirect('import_excel.php?tab=' . $tab . '&id=' . $targetId . '&preview=1');
    } catch (Throwable $e) { $errorMsg = $e->getMessage(); }
}

/* ---------- Commit import ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='commit') {
    verify_csrf();
    try {
        if (!$target) throw new Exception('Invalid shipment.');
        $dupMode = ($_POST['dupmode'] ?? 'skip')==='update' ? 'update' : 'skip';
        $payload = json_decode($_POST['rows_json'] ?? '[]', true) ?: [];
        $imported = 0; $updated = 0;
        db()->beginTransaction();

        if ($tab==='invoice') {
            $lineStmt = db()->prepare("SELECT COALESCE(MAX(line_no),0) FROM shipment_items WHERE shipment_id=?");
            $lineStmt->execute([$targetId]); $line = (int)$lineStmt->fetchColumn();
            $ins = db()->prepare("INSERT INTO shipment_items (shipment_id,line_no,product_name,des_col,department,optional_value,qty,unit,rate,amount) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $upd = db()->prepare("UPDATE shipment_items SET des_col=?,department=?,optional_value=?,qty=?,unit=?,rate=?,amount=? WHERE shipment_id=? AND product_name=?");
            foreach ($payload as $d) {
                if (!empty($d['err'])) continue;
                $x = $d['data'];
                $existingRow = $itemByName[csv_norm($x['product'])] ?? null;
                // Only overwrite optional_value when this shipment actually uses the
                // column — otherwise leave whatever the row already had untouched,
                // instead of blanking it out just because this import didn't supply it.
                $opt = $optEnabled ? ($x['optVal'] ?? '') : (string)($existingRow['optional_value'] ?? '');
                if ($existingRow) {
                    if ($dupMode==='update') { $upd->execute([$x['des'],$x['cat'],$opt,$x['qty'],$x['unit'],$x['rate'],$x['amount'],$targetId,$x['product']]); $updated++; }
                } else { $ins->execute([$targetId, ++$line, $x['product'],$x['des'],$x['cat'],$opt,$x['qty'],$x['unit'],$x['rate'],$x['amount']]); $imported++; }
            }
            /* recalc invoice totals */
            $t = db()->prepare("SELECT COALESCE(SUM(qty),0) q, COALESCE(SUM(amount),0) a FROM shipment_items WHERE shipment_id=?"); $t->execute([$targetId]); $tt=$t->fetch();
            db()->prepare("UPDATE shipments SET total_qty=?, total_amount=?, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$tt['q'],$tt['a'],current_user()['id'],$targetId]);
        } else {
            $lineStmt = db()->prepare("SELECT COALESCE(MAX(line_no),0) FROM packing_items WHERE shipment_id=?");
            $lineStmt->execute([$targetId]); $line = (int)$lineStmt->fetchColumn();
            $ins = db()->prepare("INSERT INTO packing_items (shipment_id,invoice_item_id,line_no,product_name,des_col,optional_value,pack_unit_title,carton_from,carton_to,packages,qty_per_carton,total_qty,net_weight,gross_weight) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($payload as $d) {
                if (!empty($d['err'])) continue;
                $x = $d['data'];
                $isDup = false;
                foreach ($existingPacks as $ep) { if (csv_norm($ep['product_name'])===csv_norm($x['product']) && (int)$ep['carton_from']===(int)$x['from'] && (int)$ep['carton_to']===(int)$x['to']){ $isDup=true; break; } }
                if ($isDup && $dupMode==='skip') continue;
                $opt = $optEnabled ? ($x['optVal'] ?? '') : '';
                $ins->execute([$targetId,$x['item_id'],++$line,$x['product'],$x['des'],$opt,$x['type'],$x['from'],$x['to'],$x['pkgs'],$x['qpp'],$x['tq'],$x['net'],$x['gross']]); $imported++;
            }
            $t = db()->prepare("SELECT COALESCE(SUM(packages),0) p, COALESCE(SUM(net_weight),0) n, COALESCE(SUM(gross_weight),0) g FROM packing_items WHERE shipment_id=?"); $t->execute([$targetId]); $tt=$t->fetch();
            db()->prepare("UPDATE shipments SET total_packages=?, total_net_weight=?, total_gross_weight=?, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$tt['p'],$tt['n'],$tt['g'],current_user()['id'],$targetId]);
        }

        audit_log($targetId, 'CSV Import', 'import', '', ucfirst($tab).' CSV imported ('.$dupMode.')', "Imported: $imported, Updated: $updated");
        db()->commit();
        $doneMsg = "Imported $imported new row(s)" . ($updated ? ", updated $updated" : '') . " into {$target['invoice_no']}.";
        /* Redirect (PRG) so refreshing the result page never re-triggers the import or a
           browser "confirm form resubmission" prompt. */
        unset($_SESSION['import_preview']);
        $_SESSION['import_done'] = ['tab' => $tab, 'msg' => $doneMsg, 'target_id' => $targetId];
        redirect('import_excel.php?tab=' . $tab . '&done=1');
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $errorMsg = $e->getMessage(); }
}

$editable = csv_editable_shipments();
$invCols = ['Product / Article','Description / Colour','Category','Qty','Unit','Rate','Amount'];
$packCols = ['Product Name','Type','Pkg From','Pkg To','Packages','Qty/Pkg','Net Wt','Gross Wt'];
$cols = $tab==='invoice' ? $invCols : $packCols;
if ($optEnabled) $cols[] = $optTitle;

page_header('CSV Import');
flash();
?>
<style>
.zcard{padding:15px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px);margin-bottom:11px}
.zcard h2{font-size:15px;margin:0}
.imp-tabs{display:flex;gap:8px;margin-bottom:18px}
.imp-tab{padding:10px 18px;border-radius:11px;border:1px solid #cbd5e3;background:#f6f8fc;color:#33415c;cursor:pointer;font-size:13px;text-decoration:none;font-weight:600}
.imp-tab.on{background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;border-color:transparent}
.zin{display:block;width:100%;margin-top:6px;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e3;background:#ffffff;color:#152033;font-size:13.5px;outline:none;font-family:inherit}
.dl-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:11px;border:1px solid rgba(14,168,201,.3);background:rgba(14,168,201,.1);color:#0ea8c9;cursor:pointer;font-weight:600;font-size:13px;text-decoration:none}
.drop{display:block;border:2px dashed #cbd5e3;border-radius:14px;padding:26px;text-align:center;cursor:pointer;background:#ffffff}
.zbtn{padding:12px 20px;border:none;border-radius:12px;cursor:pointer;font-weight:700;font-size:13.5px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0)}
.zbtn.sec{background:transparent;color:#152033;border:1px solid #cbd5e3}
.zbtn[disabled]{opacity:.5;cursor:not-allowed}
.chip{padding:10px 16px;border-radius:12px}
.ptable{width:100%;border-collapse:collapse;font-size:12.5px;min-width:640px}
.ptable thead tr{text-align:left;color:#8a97ab;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.ptable th{padding:5px 8px}.ptable td{padding:3px 8px;border-top:1px solid #f6f8fc;color:#152033}
.tag{padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700}
.tag.new{background:rgba(22,163,74,.16);color:#16a34a}.tag.dup{background:rgba(217,119,6,.16);color:#d97706}.tag.error{background:rgba(224,67,93,.16);color:#b8283f}
.file-hidden{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
</style>

<div class="topbar"><div><h1>CSV Import</h1><p class="lead">Append rows into an unlocked shipment. No OpenAI tokens used.</p></div></div>

<div class="imp-tabs">
  <a class="imp-tab <?= $tab==='invoice'?'on':'' ?>" href="import_excel.php?tab=invoice<?= $targetId?'&id='.$targetId:'' ?>">Invoice Items</a>
  <a class="imp-tab <?= $tab==='packing'?'on':'' ?>" href="import_excel.php?tab=packing<?= $targetId?'&id='.$targetId:'' ?>">Packing List</a>
</div>

<?php if($errorMsg): ?><div class="zcard" style="border-color:rgba(224,67,93,.3);background:rgba(224,67,93,.08);color:#b8283f"><?= e($errorMsg) ?></div><?php endif; ?>

<?php if($stage==='done'): ?>
<div class="zcard" style="border-color:rgba(22,163,74,.28);background:rgba(22,163,74,.08);display:flex;align-items:center;gap:16px">
  <div style="width:48px;height:48px;border-radius:14px;background:rgba(22,163,74,.16);display:flex;align-items:center;justify-content:center;font-size:24px;color:#16a34a">&#10003;</div>
  <div><div style="font-weight:700;font-size:15px;color:#127a3f"><?= e($doneMsg) ?></div><div style="font-size:12.5px;color:#5a6b82;margin-top:2px">Manual editing remains fully available. Calculations, permissions and validations preserved.</div></div>
</div>
<div class="zcard"><a class="zbtn sec" style="text-decoration:none" href="shipment_view.php?id=<?= (int)$targetId ?>">Open Shipment</a> <a class="zbtn sec" style="text-decoration:none;margin-left:8px" href="import_excel.php?tab=<?= $tab ?>">Import More</a></div>

<?php elseif($stage==='preview'): ?>
<div class="zcard">
  <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px">
    <div class="chip" style="background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.24)"><b style="font-size:20px;color:#16a34a"><?= $cntNew ?></b> <span style="font-size:12.5px;color:#5a6b82">new rows</span></div>
    <div class="chip" style="background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.24)"><b style="font-size:20px;color:#d97706"><?= $cntDup ?></b> <span style="font-size:12.5px;color:#5a6b82">match existing</span></div>
    <div class="chip" style="background:rgba(224,67,93,.1);border:1px solid rgba(224,67,93,.24)"><b style="font-size:20px;color:#e0435d"><?= $cntErr ?></b> <span style="font-size:12.5px;color:#5a6b82">validation errors</span></div>
    <?php if($tab==='invoice'): ?><div class="chip" style="background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.24)"><b style="font-size:20px;color:#d97706"><?= $cntPmMiss ?></b> <span style="font-size:12.5px;color:#5a6b82">not in Product Master</span></div><?php endif; ?>
  </div>
  <?php if($tab==='invoice' && $cntPmMiss>0): ?>
  <div style="padding:12px 16px;border-radius:12px;background:rgba(217,119,6,.08);border:1px solid rgba(217,119,6,.24);font-size:12.5px;color:#8a5a06;margin-bottom:16px;line-height:1.6">
    <b>Costing cross-check:</b> rows flagged below don't match any active Product Master name, so Final Costing / AI Check won't be able to pull material detail for them automatically — they'll import fine but need a Product Master match added (or the name corrected) before costing will pick them up. Only the <b>Product/Article</b> name matters for this — Description/Colour can say anything and never affects which product a line costs against.
  </div>
  <?php endif; ?>
  <div style="font-size:12px;color:#8a97ab;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Preview into <?= e($target['invoice_no']) ?></div>
  <div style="overflow-x:auto;margin-bottom:18px">
    <table class="ptable">
      <thead><tr><th>Row</th><?php foreach($cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?><th>Status</th><?php if($tab==='invoice'): ?><th>Costing Match</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td style="color:#8a97ab"><?= (int)$r['n'] ?></td>
          <?php foreach($r['cells'] as $cell): ?><td><?= e($cell) ?></td><?php endforeach; ?>
          <td><span class="tag <?= $r['tag'] ?>"><?= $r['tag']==='dup'?'Match':ucfirst($r['tag']) ?></span><?php if($r['err']): ?><div style="font-size:11px;color:#b8283f;margin-top:3px"><?= e($r['err']) ?></div><?php endif; ?></td>
          <?php if($tab==='invoice'): ?>
          <td>
            <?php if($r['pmMatch']): ?><span class="tag" style="background:rgba(22,163,74,.16);color:#16a34a">&#10003; Matched</span>
            <?php else: ?><span class="tag" style="background:rgba(224,67,93,.16);color:#b8283f">&#9888; No match</span>
              <?php if(!empty($r['pmSuggest'])): ?><div style="font-size:11px;color:#8a97ab;margin-top:3px">Did you mean "<?= e($r['pmSuggest']) ?>"?</div><?php endif; ?>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <form method="post" onsubmit="if(this.dataset.sent)return false;this.dataset.sent='1';">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="commit">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <input type="hidden" name="shipment_id" value="<?= (int)$targetId ?>">
    <input type="hidden" name="rows_json" value='<?= e(json_encode($rows, JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'>
    <div style="padding:16px 18px;border-radius:14px;background:#ffffff;border:1px solid #e3e9f2;margin-bottom:16px">
      <div style="font-size:13px;font-weight:600;margin-bottom:12px">When a row matches an existing record</div>
      <label style="display:flex;gap:10px;font-size:13px;color:#33415c;margin-bottom:10px;cursor:pointer"><input type="radio" name="dupmode" value="skip" checked style="accent-color:#0ea8c9"><span><b>Skip and import only new records</b> <span style="color:#8a97ab">(default — existing manual data is never touched)</span></span></label>
      <label style="display:flex;gap:10px;font-size:13px;color:#33415c;cursor:pointer"><input type="radio" name="dupmode" value="update" style="accent-color:#0ea8c9"><span><b>Update matching records</b> <span style="color:#8a97ab">overwrite existing values with the CSV values<?= $tab==='packing'?' (packing rows are always appended)':'' ?></span></span></label>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
      <button class="zbtn" type="submit" <?= $cntNew+$cntDup>0?'':'disabled' ?>>Import <?= $cntNew ?> new row(s)</button>
      <a class="zbtn sec" style="text-decoration:none" href="import_excel.php?tab=<?= $tab ?>&id=<?= (int)$targetId ?>">Cancel</a>
      <span style="font-size:12px;color:#8a97ab">Existing rows stay editable by hand after import.</span>
    </div>
  </form>
</div>

<?php else: /* upload */ ?>
<div class="zcard">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:6px">
    <h2><?= $tab==='invoice'?'Invoice Items CSV':'Packing List CSV' ?></h2>
    <a class="dl-btn" id="dlTemplateBtn" href="import_excel.php?download=<?= $tab ?><?= $targetId ? '&id='.$targetId : '' ?>"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/></svg>Download CSV Template</a>
  </div>
  <p style="color:#5a6b82;font-size:12.5px;margin:0 0 18px;line-height:1.6"><?= $tab==='invoice'
      ? 'Imports invoice item lines only (Product/Article, Description/Colour, Category, Qty, Unit, Rate, Amount). Header fields are not changed. Every row appends as a new line unless it matches an existing product.'
      : 'Imports packing rows (Product Name, Description/Colour, Package Type, Package From/To, Qty per Package, Net &amp; Gross Weight). Product must match an existing invoice item. Accepts old headers carton_from / carton_to / qty_per_carton. Default package type is CTN.' ?></p>

  <form method="post" enctype="multipart/form-data" id="impForm" onsubmit="if(this.dataset.sent)return false; var f=this.querySelector('input[type=file]'); if(!f.files.length){alert('Please choose a CSV file first.');return false;} this.dataset.sent='1';">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="preview">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">

    <label style="font-size:12px;color:#5a6b82">Import into shipment
      <select class="zin" name="shipment_id" id="shipSelect" required style="max-width:420px" onchange="impUpdateOptColHint(this.value)">
        <option value="">— Select an unlocked shipment —</option>
        <?php foreach($editable as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $targetId===(int)$s['id']?'selected':'' ?>><?= e($s['invoice_no']) ?> · <?= e($s['buyer_name']) ?> (<?= e(ucwords(str_replace('_',' ',$s['status']))) ?>)</option><?php endforeach; ?>
      </select>
    </label>
    <?php if(!$editable): ?><div style="margin-top:10px;font-size:12.5px;color:#d97706">No unlocked shipments available. Create a shipment first.</div><?php endif; ?>
    <div id="optColHint" style="display:<?= $optEnabled?'flex':'none' ?>;margin-top:10px;padding:9px 13px;border-radius:9px;background:rgba(109,91,208,.07);border:1px solid rgba(109,91,208,.22);font-size:12px;color:#6d5bd0;font-weight:600;align-items:center;gap:8px">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><path d="M12 16v-4M12 8h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0z"/></svg>
      <span>This shipment's Optional Column — "<span id="optColTitle"><?= e($optTitle) ?></span>" — is included in the template below.</span>
    </div>
    <script>
    var IMP_TAB = <?= json_encode($tab) ?>;
    var IMP_OPT_COLS = <?= json_encode(array_reduce($editable, function($acc, $s) {
        $acc[(int)$s['id']] = !empty($s['optional_column_enabled']) ? (trim((string)$s['optional_column_title']) ?: 'Optional Column') : null;
        return $acc;
    }, [])) ?>;
    function impUpdateOptColHint(shipId) {
      var dl = document.getElementById('dlTemplateBtn');
      var base = 'import_excel.php?download=' + IMP_TAB;
      dl.href = shipId ? (base + '&id=' + shipId) : base;
      var title = IMP_OPT_COLS[shipId] || null;
      var hint = document.getElementById('optColHint');
      if (title) { document.getElementById('optColTitle').textContent = title; hint.style.display = 'flex'; }
      else { hint.style.display = 'none'; }
    }
    </script>

    <label class="drop" style="margin-top:16px">
      <input type="file" name="csv" accept=".csv" class="file-hidden" onchange="var l=this.parentNode.querySelector('.fl'); if(l&&this.files[0]) l.textContent=this.files[0].name">
      <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#0ea8c9" stroke-width="1.5" style="margin-bottom:8px"><path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
      <div class="fl" style="font-size:14px;font-weight:600">Choose CSV file</div>
      <div style="font-size:12px;color:#8a97ab;margin-top:3px">CSV only · matches the downloadable template columns</div>
    </label>

    <div style="margin-top:18px"><button class="zbtn" type="submit">Preview Import</button></div>
  </form>
</div>
<?php endif; ?>

<?php page_footer(); ?>
