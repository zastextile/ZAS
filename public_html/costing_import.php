<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/costing.php';
require_login();
if (is_staff()) { http_response_code(403); exit('Staff cannot import costings.'); }
require_costing('import');
costing_ensure_schema();
try { db()->exec("ALTER TABLE costing_lines ADD COLUMN shared TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

$GROUPS = ['Fabric','Accessories','Packing','Workmanship','Other'];
function ci_norm($v): string { return strtolower(trim(preg_replace('/[^a-z0-9]+/i','_', (string)$v),'_')); }
function ci_num($v): float { $s=preg_replace('/[^0-9.\-]/','',(string)$v); return is_numeric($s)?(float)$s:0.0; }
function ci_pick(array $r, array $k, $d=''){ foreach($k as $x){ if(isset($r[$x])&&trim((string)$r[$x])!=='') return trim((string)$r[$x]); } return $d; }
/* Excel-exported CSVs are often Windows-1252, not UTF-8 — a single invalid byte anywhere
   in the file used to make json_encode() silently fail for the whole import. Clean every
   cell to valid UTF-8 as it's read so that can never happen again. */
function ci_utf8($s): string { $s=(string)$s; if ($s==='' || mb_check_encoding($s,'UTF-8')) return $s; $c=@mb_convert_encoding($s,'UTF-8','Windows-1252'); return $c!==false ? $c : (string)@iconv('UTF-8','UTF-8//IGNORE',$s); }

$vid = (int)($_GET['version_id'] ?? $_POST['version_id'] ?? 0);
$ver=null;$prod=null;
if ($vid) {
    $st=db()->prepare("SELECT cv.*, p.name pname, p.product_code pcode FROM costing_versions cv JOIN products p ON p.id=cv.product_id WHERE cv.id=?");
    $st->execute([$vid]); $ver=$st->fetch();
}
if (!$ver) { http_response_code(404); exit('Costing version not found.'); }

/* template */
if (($_GET['download'] ?? '')==='template') {
    header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="costing_detail_template.csv"');
    echo "Group,Item,Description,Qty,Unit,Weight,Rate\n";
    echo "Fabric,Main Fabric,Printed cotton,3.2,Meter,1.45,520\n";
    echo "Packing,Shared Master Carton,1 carton per 5 sets,0.2,Carton,0.08,250\n";
    exit;
}

$stage='upload'; $rows=[]; $cntNew=$cntErr=0; $err=''; $done='';

/* Restore a just-completed import after redirect (avoids browser "resubmit form" reprompt) */
if (($_GET['done'] ?? '') === '1' && !empty($_SESSION['costing_import_done']) && (int)($_SESSION['costing_import_done']['version_id'] ?? 0) === $vid) {
    $stage = 'done';
    $done = $_SESSION['costing_import_done']['msg'];
    unset($_SESSION['costing_import_done']);
}

/* existing lines for dup detection */
$existing=[]; $ex=db()->prepare("SELECT item_name,description FROM costing_lines WHERE costing_version_id=?"); $ex->execute([$vid]);
foreach($ex->fetchAll() as $r) $existing[ci_norm($r['item_name']).'|'.ci_norm($r['description'])]=true;

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='preview') {
    verify_csrf();
    try {
        if (!isset($_FILES['csv']) || $_FILES['csv']['error']!==UPLOAD_ERR_OK) throw new Exception('Please choose a .csv file.');
        if (strtolower(pathinfo($_FILES['csv']['name'],PATHINFO_EXTENSION))!=='csv') throw new Exception('Only .csv files are supported.');
        $fh=fopen($_FILES['csv']['tmp_name'],'r'); $head=fgetcsv($fh); if(!$head) throw new Exception('CSV is empty.');
        $map=[]; foreach($head as $i=>$h) $map[$i]=ci_norm($h);
        $seen=[]; $n=0;
        while(($d=fgetcsv($fh))!==false){
            if(count(array_filter($d,fn($x)=>trim((string)$x)!==''))===0) continue; $n++;
            $r=[]; foreach($map as $i=>$k) $r[$k]=ci_utf8($d[$i]??'');
            $group=ci_pick($r,['group','line_group']); $item=ci_pick($r,['item','item_name']); $desc=ci_pick($r,['description','desc']);
            $qty=ci_num(ci_pick($r,['qty','quantity'],'0')); $unit=ci_pick($r,['unit'],''); $wt=ci_num(ci_pick($r,['weight','weight_kg'],'0')); $rate=ci_num(ci_pick($r,['rate'],'0'));
            if(!in_array($group,$GROUPS,true)) $group='Other';
            $e='';
            if($item==='') $e='Item name required';
            elseif($qty<=0) $e='Qty must be greater than zero';
            elseif($rate<0) $e='Rate cannot be negative';
            $dupKey=ci_norm($item).'|'.ci_norm($desc);
            if(!$e && (isset($existing[$dupKey])||isset($seen[$dupKey]))) $e='Duplicate line (item + description already present)';
            $seen[$dupKey]=true;
            if($e) $cntErr++; else $cntNew++;
            $rows[]=['n'=>$n,'err'=>$e,'cells'=>[$group,$item,$desc,number_format($qty,3),$unit,number_format($wt,3),number_format($rate,2)],'data'=>compact('group','item','desc','qty','unit','wt','rate')];
        }
        fclose($fh);
        if(!$rows) throw new Exception('No data rows found.');
        $stage='preview';
    } catch(Throwable $e){ $err=$e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='commit') {
    verify_csrf();
    try {
        // Plain per-row hidden fields (imp_*[]), not a single json_encode()'d blob:
        // a CSV cell with even one invalid-UTF8 byte (common from Excel exports) makes
        // json_encode() silently return false for the WHOLE array, so the old hidden
        // field ended up empty and every import quietly inserted zero rows with no error.
        $n = count($_POST['imp_item'] ?? []);
        db()->beginTransaction();
        $line=(int)db()->query("SELECT COALESCE(MAX(sort_order),0) FROM costing_lines WHERE costing_version_id=".(int)$vid)->fetchColumn();
        $ins=db()->prepare("INSERT INTO costing_lines (costing_version_id,line_group,item_name,description,quantity,unit,weight_kg,rate,amount,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $imported=0;
        for ($i=0; $i<$n; $i++){
            if (trim((string)($_POST['imp_err'][$i] ?? '')) !== '') continue;
            $g = in_array($_POST['imp_group'][$i] ?? '', $GROUPS, true) ? $_POST['imp_group'][$i] : 'Other';
            $item = trim((string)($_POST['imp_item'][$i] ?? ''));
            $desc = trim((string)($_POST['imp_desc'][$i] ?? ''));
            $qty = ci_num($_POST['imp_qty'][$i] ?? 0);
            $unit = trim((string)($_POST['imp_unit'][$i] ?? ''));
            $wt = ci_num($_POST['imp_wt'][$i] ?? 0);
            $rate = ci_num($_POST['imp_rate'][$i] ?? 0);
            $ins->execute([$vid,$g,$item,$desc,$qty,$unit,$wt,$rate,round($qty*$rate,2),++$line]);
            $imported++;
        }
        /* recompute version totals: normal lines allocate weight = weight × qty, shared lines keep weight ÷ qty */
        $agg=db()->prepare("SELECT line_group,quantity,weight_kg,amount,shared FROM costing_lines WHERE costing_version_id=?"); $agg->execute([$vid]);
        $tc=0;$net=0;$pack=0;
        foreach($agg->fetchAll() as $a){
            $tc+=(float)$a['amount'];
            $lq=(float)$a['quantity']; $lw=(float)$a['weight_kg']; $lsh=!empty($a['shared']);
            $law=$lsh ? ($lq>0?$lw/$lq:$lw) : ($lw*$lq);
            if($a['line_group']==='Fabric'||$a['line_group']==='Accessories')$net+=$law;
            if($a['line_group']==='Packing')$pack+=$law;
        }
        db()->prepare("UPDATE costing_versions SET total_cost=?,net_weight=?,packing_weight=?,gross_weight=? WHERE id=?")->execute([round($tc,2),round($net,3),round($pack,3),round($net+$pack,3),$vid]);
        try { audit_log(0,'Product Costing','import_detail',$ver['pname'],"$imported detail line(s) into ".$ver['costing_no'],'Costing detail import'); } catch(Throwable $e){}
        db()->commit();
        $done = "Imported $imported detail line(s) into ".$ver['costing_no'].". Totals recalculated.";
        /* Redirect (PRG) so refreshing the result page never re-triggers the import or a
           browser "confirm form resubmission" prompt. */
        $_SESSION['costing_import_done'] = ['version_id' => $vid, 'msg' => $done];
        redirect('costing_import.php?version_id=' . $vid . '&done=1');
    } catch(Throwable $e){ if(db()->inTransaction())db()->rollBack(); $err=$e->getMessage(); }
}

$cols=['Group','Item','Description','Qty','Unit','Weight','Rate'];
page_header('Import Costing Detail'); flash();
?>
<style>
.zcard{padding:15px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px);margin-bottom:11px}
.zcard h2{font-size:15px;margin:0}
.zin{width:100%;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e3;background:#ffffff;color:#152033;font-size:13.5px;font-family:inherit}
.dl-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:11px;border:1px solid rgba(14,168,201,.3);background:rgba(14,168,201,.1);color:#0ea8c9;font-weight:600;font-size:13px;text-decoration:none}
.drop{display:block;border:2px dashed #cbd5e3;border-radius:14px;padding:26px;text-align:center;cursor:pointer;background:#ffffff}
.zbtn{padding:12px 20px;border:none;border-radius:12px;cursor:pointer;font-weight:700;font-size:13.5px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0)}
.zbtn.sec{background:transparent;color:#152033;border:1px solid #cbd5e3;text-decoration:none}
.zbtn[disabled]{opacity:.5;cursor:not-allowed}
.chip{padding:10px 16px;border-radius:12px}
.ptable{width:100%;border-collapse:collapse;font-size:12.5px;min-width:640px}
.ptable thead tr{text-align:left;color:#8a97ab;font-size:11px;text-transform:uppercase}
.ptable th{padding:5px 8px}.ptable td{padding:3px 8px;border-top:1px solid #f6f8fc;color:#152033}
.tag{padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700}
.tag.new{background:rgba(22,163,74,.16);color:#16a34a}.tag.error{background:rgba(224,67,93,.16);color:#b8283f}
.file-hidden{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
</style>

<div class="topbar"><div><h1>Import Costing Detail</h1><p class="lead"><?= e($ver['pname']) ?> · <?= e($ver['costing_no']) ?> · <?= e($ver['version_name']) ?></p></div>
  <a class="zbtn sec" href="product_costing.php?product_id=<?= (int)$ver['product_id'] ?>">← Back to Costing</a></div>

<?php if($err): ?><div class="zcard" style="border-color:rgba(224,67,93,.3);background:rgba(224,67,93,.08);color:#b8283f"><?= e($err) ?></div><?php endif; ?>

<?php if($stage==='done'): ?>
<div class="zcard" style="border-color:rgba(22,163,74,.28);background:rgba(22,163,74,.08);color:#127a3f;font-weight:700"><?= e($done) ?></div>
<div class="zcard"><a class="zbtn sec" href="product_costing.php?product_id=<?= (int)$ver['product_id'] ?>">Open Costing</a> <a class="zbtn sec" style="margin-left:8px" href="costing_import.php?version_id=<?= (int)$vid ?>">Import More</a></div>

<?php elseif($stage==='preview'): ?>
<div class="zcard">
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
    <div class="chip" style="background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.24)"><b style="font-size:20px;color:#16a34a"><?= $cntNew ?></b> <span style="font-size:12.5px;color:#5a6b82">valid new lines</span></div>
    <div class="chip" style="background:rgba(224,67,93,.1);border:1px solid rgba(224,67,93,.24)"><b style="font-size:20px;color:#e0435d"><?= $cntErr ?></b> <span style="font-size:12.5px;color:#5a6b82">skipped (errors/dups)</span></div>
  </div>
  <div style="overflow-x:auto;margin-bottom:18px"><table class="ptable">
    <thead><tr><th>Row</th><?php foreach($cols as $c): ?><th><?= e($c) ?></th><?php endforeach; ?><th>Status</th></tr></thead>
    <tbody><?php foreach($rows as $r): ?><tr><td style="color:#8a97ab"><?= (int)$r['n'] ?></td><?php foreach($r['cells'] as $c): ?><td><?= e($c) ?></td><?php endforeach; ?><td><?php if($r['err']): ?><span class="tag error">Skip</span><div style="font-size:11px;color:#b8283f;margin-top:3px"><?= e($r['err']) ?></div><?php else: ?><span class="tag new">New</span><?php endif; ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <form method="post" onsubmit="if(this.dataset.sent)return false;this.dataset.sent='1';">
    <?= csrf_field() ?><input type="hidden" name="action" value="commit"><input type="hidden" name="version_id" value="<?= (int)$vid ?>">
    <?php foreach($rows as $r): $x=$r['data']; ?>
    <input type="hidden" name="imp_group[]" value="<?= e($x['group']) ?>">
    <input type="hidden" name="imp_item[]" value="<?= e($x['item']) ?>">
    <input type="hidden" name="imp_desc[]" value="<?= e($x['desc']) ?>">
    <input type="hidden" name="imp_qty[]" value="<?= e($x['qty']) ?>">
    <input type="hidden" name="imp_unit[]" value="<?= e($x['unit']) ?>">
    <input type="hidden" name="imp_wt[]" value="<?= e($x['wt']) ?>">
    <input type="hidden" name="imp_rate[]" value="<?= e($x['rate']) ?>">
    <input type="hidden" name="imp_err[]" value="<?= e($r['err']) ?>">
    <?php endforeach; ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap"><button class="zbtn" <?= $cntNew>0?'':'disabled' ?>>Import <?= $cntNew ?> line(s)</button><a class="zbtn sec" style="padding:12px 20px" href="costing_import.php?version_id=<?= (int)$vid ?>">Cancel</a></div>
  </form>
</div>

<?php else: ?>
<div class="zcard">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:6px"><h2>Detail Lines CSV</h2>
    <a class="dl-btn" href="costing_import.php?version_id=<?= (int)$vid ?>&download=template"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/></svg>Download Template</a></div>
  <p style="color:#5a6b82;font-size:12.5px;margin:0 0 18px;line-height:1.6">Columns: Group (Fabric / Accessories / Packing / Workmanship / Other), Item, Description, Qty, Unit, Weight, Rate. Lines import into <b><?= e($ver['costing_no']) ?></b> only. Duplicates, blank items, zero qty and negative rates are rejected in the preview.</p>
  <form method="post" enctype="multipart/form-data" onsubmit="if(this.dataset.sent)return false; var f=this.querySelector('input[type=file]'); if(!f.files.length){alert('Please choose a CSV file first.');return false;} this.dataset.sent='1';">
    <?= csrf_field() ?><input type="hidden" name="action" value="preview"><input type="hidden" name="version_id" value="<?= (int)$vid ?>">
    <label class="drop">
      <input type="file" name="csv" accept=".csv" class="file-hidden" onchange="var l=this.parentNode.querySelector('.fl');if(l&&this.files[0])l.textContent=this.files[0].name">
      <div class="fl" style="font-size:14px;font-weight:600">Choose CSV file</div><div style="font-size:12px;color:#8a97ab;margin-top:3px">Matches the template columns</div>
    </label>
    <div style="margin-top:18px"><button class="zbtn">Preview Import</button></div>
  </form>
</div>
<?php endif; ?>
<?php page_footer(); ?>
