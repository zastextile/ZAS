<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/costing.php';
require_login();
if (is_staff()) { http_response_code(403); exit('Not permitted.'); }
require_costing('print');
costing_ensure_schema();

global $config;
$curList = $config['costing_currencies'] ?? ['PKR','USD','EUR'];
$fx = fx_get_rates(); // live-synced weekly from Frankfurter, falls back to config on failure
/* convert an amount given in $from currency into $to currency via PKR base */
function cst_convert(float $amt, string $from, string $to, array $fx): float {
    $f = $fx[$from] ?? 1; $t = $fx[$to] ?? 1;
    if ($f <= 0) $f = 1;
    $pkr = $amt / $f;      // back to PKR base
    return $pkr * $t;      // into target
}
function cst_money($v){ return number_format((float)$v, 2); }
function cst_sym($c){ $m=['PKR'=>'₨','USD'=>'$','EUR'=>'€','GBP'=>'£']; $c=strtoupper($c); return $m[$c] ?? ($c.' '); }
/* one costing line row; for Shared lines shows allocated per-piece values with a small note */
function cst_trim($n){ return rtrim(rtrim(number_format((float)$n,3),'0'),'.'); }
function cst_line_row(array $l): string {
    $shared = !empty($l['shared']);
    $q    = (float)$l['quantity'];
    $wt   = (float)$l['weight_kg'];
    $rate = (float)$l['rate'];
    $amt  = (float)$l['amount'];
    $unit = trim((string)$l['unit']) !== '' ? trim((string)$l['unit']) : 'Carton';
    $note = function($t){ return '<div style="font-size:8px;color:#8a97ab;font-weight:400;line-height:1.25;margin-top:1px">'.$t.'</div>'; };
    if ($shared && $q > 0) {
        $qtyCell  = number_format(1/$q,3).$note('1 '.e($unit).' &divide; '.cst_trim($q).' pcs');
        $wtCell   = number_format($wt/$q,3).$note(number_format($wt,3).' kg &divide; '.cst_trim($q).' pcs');
        $rateCell = number_format($rate,2).$note('Per '.e($unit));
    } else {
        $qtyCell  = number_format($q,3);
        $wtCell   = number_format($wt*$q,3);
        $rateCell = number_format($rate,2);
    }
    return '<tr><td>'.e($l['line_group']).'</td><td>'.e($l['item_name']).'</td><td>'.e($l['description']).'</td>'
        .'<td class="num">'.$qtyCell.'</td><td>'.e($l['unit']).'</td><td class="num">'.$wtCell.'</td>'
        .'<td class="num">'.$rateCell.'</td><td class="num">'.number_format($amt,2).'</td></tr>';
}

/* ===== FULL PRODUCT PRINT (all versions + summary) ===== */
$pid = (int)($_GET['product_id'] ?? 0);
if ($pid) {
    // Team-Share mode: a restricted view safe to hand to staff — no Fabric
    // line, no total/grand cost anywhere (top summary or per-version
    // total), no foreign-currency conversion. Everything else (Accessories,
    // Packing, Workmanship — including the exact "shared line" ÷ math) is
    // unchanged, since it reuses cst_line_row() untouched.
    $share = !empty($_GET['share']);

    $ps = db()->prepare("SELECT * FROM products WHERE id=?"); $ps->execute([$pid]); $prod = $ps->fetch();
    if (!$prod) { http_response_code(404); exit('Product not found.'); }
    $vs = db()->prepare("SELECT * FROM costing_versions WHERE product_id=? ORDER BY id"); $vs->execute([$pid]); $versions = $vs->fetchAll();

    /* per-size aggregation: map each version to its sizes */
    $sizeRows = [];
    foreach ($versions as $v) {
        $sz = db()->prepare("SELECT ps.size_label FROM costing_version_sizes cvs JOIN product_sizes ps ON ps.id=cvs.product_size_id WHERE cvs.costing_version_id=?");
        $sz->execute([$v['id']]); $labels = array_column($sz->fetchAll(),'size_label');
        if (!$labels) $labels = ['(unassigned)'];
        foreach ($labels as $lab) {
            $sizeRows[] = ['size'=>$lab,'version'=>$v['version_name'],'cur'=>$v['currency'] ?: 'PKR','cost'=>(float)$v['total_cost'],'sell'=>(float)$v['suggested_price'],'gross'=>(float)$v['gross_weight']];
        }
    }

    costing_print_head($share ? 'Team Costing Summary' : 'Product Costing Summary');
    ?>
    <div class="row">
      <div><div class="lbl">Product</div><div class="val"><?= e($prod['name']) ?></div></div>
      <div><div class="lbl">Product Code</div><div class="val"><?= e($prod['product_code'] ?: '—') ?></div></div>
      <div><div class="lbl">Category</div><div class="val"><?= e($prod['category'] ?: '—') ?></div></div>
      <div><div class="lbl">Date</div><div class="val"><?= e(date('d M Y')) ?></div></div>
    </div>

    <?php if ($share): ?>
    <div class="remarks"><b>Restricted view for internal team review.</b> Fabric cost and the overall product/total cost are not shown — only Accessories, Packing and Workmanship lines are listed below, in PKR only, so the team can verify those parts without seeing the full costing.</div>
    <?php else: ?>

    <div class="sect">Summary</div>
    <div class="row">
      <div><div class="lbl">Costing Versions</div><div class="val"><?= count($versions) ?></div></div>
      <div><div class="lbl">Sizes Covered</div><div class="val"><?= count($sizeRows) ?></div></div>
      <div><div class="lbl">Display Currencies</div><div class="val"><?= e(implode(' · ', $curList)) ?></div></div>
    </div>

    <table>
      <thead><tr><th>Size</th><th>Version</th><th class="num">Gross (kg)</th>
        <?php foreach($curList as $c): ?><th class="num">Cost <?= e($c) ?></th><?php endforeach; ?>
        <?php foreach($curList as $c): ?><th class="num">Sell <?= e($c) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php foreach($sizeRows as $r): ?>
        <tr>
          <td><?= e($r['size']) ?></td><td><?= e($r['version']) ?></td><td class="num"><?= e(number_format($r['gross'],3)) ?></td>
          <?php foreach($curList as $c): ?><td class="num"><?= e(cst_money(cst_convert($r['cost'],$r['cur'],$c,$fx))) ?></td><?php endforeach; ?>
          <?php foreach($curList as $c): ?><td class="num"><?= e(cst_money(cst_convert($r['sell'],$r['cur'],$c,$fx))) ?></td><?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        <?php if(!$sizeRows): ?><tr><td colspan="<?= 3+2*count($curList) ?>" style="text-align:center;color:#8a97ab">No costing versions yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <div class="remarks">Currency conversions are indicative, based on the configured FX rates. Costs/prices are per unit as entered in each costing version.</div>
    <?php endif; ?>

    <?php foreach($versions as $v):
        $ls = db()->prepare("SELECT * FROM costing_lines WHERE costing_version_id=? ORDER BY sort_order,id"); $ls->execute([$v['id']]); $lines=$ls->fetchAll();
        $cur = $v['currency'] ?: 'PKR';
        if ($share) $lines = array_values(array_filter($lines, fn($l) => $l['line_group'] !== 'Fabric'));
        ?>
    <div class="sect"><?= e($v['version_name']) ?><?php if(!$share): ?> · <?= e($cur) ?> · Total <?= e(cst_money($v['total_cost'])) ?><?php else: ?> · PKR<?php endif; ?></div>
    <table>
      <thead><tr><th>Group</th><th>Item</th><th>Description</th><th class="num">Qty</th><th>Unit</th><th class="num">Wt</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
      <tbody>
        <?php if($share): ?><tr style="background:#f6f8fb"><td colspan="8" style="text-align:center;color:#8a97ab;font-style:italic">Fabric — withheld from this view</td></tr><?php endif; ?>
        <?php foreach($lines as $l): ?>
        <?= cst_line_row($l) ?>
        <?php endforeach; ?>
        <?php if(!$lines && !$share): ?><tr><td colspan="8" style="text-align:center;color:#8a97ab">No detail lines.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($share): $visTotal = array_sum(array_column($lines,'amount')); ?>
      <tfoot><tr class="tot"><td colspan="7">Subtotal — excl. Fabric (PKR)</td><td class="num"><?= e(cst_money($visTotal)) ?></td></tr></tfoot>
      <?php else: ?>
      <tfoot><tr class="tot"><td colspan="7">Total (<?= e($cur) ?>)</td><td class="num"><?= e(cst_money($v['total_cost'])) ?></td></tr></tfoot>
      <?php endif; ?>
    </table>
    <?php if ($share): ?><div class="remarks">Fabric cost and the full product Total Cost are intentionally not shown in this report.</div><?php endif; ?>
    <?php endforeach; ?>

    <div class="sign"><div>Prepared by — <?= e(current_user()['name'] ?? '') ?></div><div><?= $share ? 'Reviewed by' : 'Approved by' ?></div></div>
    <?php costing_print_foot();
    exit;
}

/* ===== SINGLE VERSION PRINT ===== */
$vid = (int)($_GET['version_id'] ?? 0);
$st = db()->prepare("SELECT cv.*, p.name pname, p.product_code pcode, p.category, p.default_unit, p.hs_code FROM costing_versions cv JOIN products p ON p.id=cv.product_id WHERE cv.id=?");
$st->execute([$vid]); $v = $st->fetch();
if (!$v) { http_response_code(404); exit('Costing not found.'); }

$ls = db()->prepare("SELECT * FROM costing_lines WHERE costing_version_id=? ORDER BY sort_order,id"); $ls->execute([$vid]); $lines=$ls->fetchAll();
$sz = db()->prepare("SELECT ps.size_label FROM costing_version_sizes cvs JOIN product_sizes ps ON ps.id=cvs.product_size_id WHERE cvs.costing_version_id=?"); $sz->execute([$vid]);
$sizeList = implode(', ', array_column($sz->fetchAll(),'size_label'));
$cur = $v['currency'] ?: 'PKR';
$prepared = current_user()['name'] ?? '';

costing_print_head('Product Costing');
?>
<div class="row">
  <div><div class="lbl">Costing No.</div><div class="val"><?= e($v['costing_no'] ?: ('CST-'.$v['id'])) ?></div></div>
  <div><div class="lbl">Date</div><div class="val"><?= e(date('d M Y', strtotime($v['created_at']))) ?></div></div>
  <div><div class="lbl">Version / Rev</div><div class="val"><?= e($v['version_name']) ?> · Rev <?= (int)($v['revision_no'] ?? 1) ?></div></div>
  <div><div class="lbl">Status</div><div class="val"><?= e(ucfirst($v['status'] ?? 'draft')) ?></div></div>
</div>
<div class="row">
  <div><div class="lbl">Product</div><div class="val"><?= e($v['pname']) ?></div></div>
  <div><div class="lbl">Product Code</div><div class="val"><?= e($v['pcode'] ?: '—') ?></div></div>
  <div><div class="lbl">Category</div><div class="val"><?= e($v['category'] ?: '—') ?></div></div>
  <div><div class="lbl">HS Code</div><div class="val"><?= e($v['hs_code'] ?: '—') ?></div></div>
</div>
<div class="row"><div><div class="lbl">Applicable Sizes</div><div class="val"><?= e($sizeList ?: '—') ?></div></div><div><div class="lbl">Unit</div><div class="val"><?= e($v['default_unit'] ?: 'Pc') ?></div></div></div>

<div class="sect">Costing Details</div>
<table>
  <thead><tr><th>Group</th><th>Item</th><th>Description</th><th class="num">Qty</th><th>Unit</th><th class="num">Wt (kg)</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
  <tbody>
    <?php foreach($lines as $l): ?>
    <?= cst_line_row($l) ?>
    <?php endforeach; ?>
    <?php if(!$lines): ?><tr><td colspan="8" style="text-align:center;color:#8a97ab">No detail lines.</td></tr><?php endif; ?>
  </tbody>
  <tfoot><tr class="tot"><td colspan="7"><?php
    $tc=(float)$v['total_cost'];
    $fxg = $fx + ['GBP'=>0.0028];
    $foreign=[]; foreach(['USD','EUR','GBP'] as $cc){ if(strtoupper($cc)===strtoupper($cur)) continue; $foreign[]=cst_sym($cc).number_format(cst_convert($tc,$cur,$cc,$fxg),2); }
  ?><span style="display:inline-flex;width:100%;justify-content:space-between;align-items:baseline"><span>Total Cost per <?= e($v['default_unit'] ?: 'Unit') ?></span><?php if($foreign): ?><span style="font-weight:400;color:#8a97ab"><?= implode(' &nbsp;/&nbsp; ',$foreign) ?></span><?php endif; ?></span></td><td class="num"><?= cst_sym($cur).number_format($tc,2) ?></td></tr></tfoot>
</table>

<?php
/* recompute weights for print: shared lines allocate carton weight per piece (wt / qty) */
$netW=0; $packW=0;
foreach($lines as $l){ $q=(float)$l['quantity']; $w=(float)$l['weight_kg']; $sh=!empty($l['shared']); $aw=$sh ? ($q>0?$w/$q:$w) : ($w*$q); $g=$l['line_group']; if($g==='Fabric'||$g==='Accessories') $netW+=$aw; elseif($g==='Packing') $packW+=$aw; }
$grossW=$netW+$packW;
?>
<div class="row" style="margin-top:14px">
  <div><div class="lbl">Net Weight</div><div class="val"><?= e(number_format($netW,3)) ?> kg</div></div>
  <div><div class="lbl">Packing Weight</div><div class="val"><?= e(number_format($packW,3)) ?> kg</div></div>
  <div><div class="lbl">Gross Weight</div><div class="val"><?= e(number_format($grossW,3)) ?> kg</div></div>
  <div><div class="lbl">Suggested Selling Price</div><div class="val"><?= e($cur.' '.number_format((float)$v['suggested_price'],2)) ?></div></div>
</div>

<?php if(trim((string)$v['remarks'])!==''): ?><div class="remarks"><b>Remarks:</b> <?= e($v['remarks']) ?></div><?php endif; ?>

<div class="sign"><div>Prepared by — <?= e($prepared) ?></div><div>Approved by</div></div>
<?php costing_print_foot(); ?>
