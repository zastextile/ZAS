<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/costing.php';
require_login();
if (is_staff()) { http_response_code(403); exit('Not permitted.'); }
require_costing('proforma');
costing_ensure_schema();

$id=(int)($_GET['id'] ?? 0);
$st=db()->prepare("SELECT * FROM proforma_invoices WHERE id=?"); $st->execute([$id]); $pf=$st->fetch();
if(!$pf){ http_response_code(404); exit('Proforma not found.'); }
$it=db()->prepare("SELECT * FROM proforma_items WHERE proforma_id=? ORDER BY sort_order,id"); $it->execute([$id]); $items=$it->fetchAll();
$cur=$pf['currency'] ?: 'USD'; $total=0; foreach($items as $r) $total+=(float)$r['amount'];

/* currency symbol for whatever's chosen on this proforma — falls back to
   printing the plain currency code for anything not in the map. */
function pf_sym(string $c): string {
    $m = ['PKR'=>'₨','USD'=>'$','EUR'=>'€','GBP'=>'£'];
    $c = strtoupper($c);
    return $m[$c] ?? ($c.' ');
}
$sym = pf_sym($cur);

/* Beneficiary bank details — structured fields (Bank Name/Branch/Account
   Title/SWIFT/IBAN) replace the old single free-text box, so a pasted
   SWIFT code or IBAN with odd spacing can't print garbled anymore: each
   piece has its own field and always prints on its own clean line.
   Proformas saved before this change (nothing in the new fields yet, but
   text already sitting in the old bank1_details/bank2_details column)
   keep showing that old text verbatim until someone re-enters it below. */
$bankNum = ($pf['bank_choice'] ?? '1') === '2' ? '2' : '1';
$bFields = [
    'name'   => trim((string)($pf['bank'.$bankNum.'_name'] ?? '')),
    'branch' => trim((string)($pf['bank'.$bankNum.'_branch'] ?? '')),
    'title'  => trim((string)($pf['bank'.$bankNum.'_title'] ?? '')),
    'swift'  => trim((string)($pf['bank'.$bankNum.'_swift'] ?? '')),
    'iban'   => trim((string)($pf['bank'.$bankNum.'_iban'] ?? '')),
];
$bankHasStructured = (bool)array_filter($bFields);
$bankLegacyText = trim((string)($pf['bank'.$bankNum.'_details'] ?? ''));
// Falls back to the shared company-wide bank defaults (includes/costing.php)
// for anything this specific proforma hasn't got its own value for — new
// proformas are already pre-filled from the same source at creation time,
// so this only really matters for proformas created before this feature.
$bd = company_bank_defaults();
$bankDefaults = [
    'name'   => $bd['bank'.$bankNum.'_name'] ?? '',
    'branch' => $bd['bank'.$bankNum.'_branch'] ?? '',
    'title'  => $bd['bank'.$bankNum.'_title'] ?? '',
    'swift'  => $bd['bank'.$bankNum.'_swift'] ?? '',
    'iban'   => $bd['bank'.$bankNum.'_iban'] ?? '',
];
foreach ($bankDefaults as $k=>$v) { if ($bFields[$k] === '') $bFields[$k] = $v; }

/* ---------- pagination ----------
   A browser print can't tell us where its own page breaks land, so instead
   of one continuous table we split the items into fixed-size chunks
   ourselves and force a break after each one — that's what makes a
   reliable "Carried Forward" / "Brought Forward" running total possible.

   The four row-budgets below are estimates for the print CSS's current
   spacing (includes/costing.php's costing_print_head()), deliberately on
   the conservative side so a chunk never silently overflows past its
   forced page break:
     - single : everything (header + bank + Bill To + items + Total +
                Terms + signature) has to fit on ONE page — used only when
                the whole order needs no 2nd sheet at all.
     - first  : page 1 when there WILL be more pages — has the header +
                bank + Bill To, but no Total/Terms/signature yet, so it
                holds more rows than "single".
     - middle : a continuation page that is not the last one — lightest
                header, just the table, so it holds the most rows.
     - last   : the final continuation page — needs room for the real
                Total, Terms and signature at the bottom.
   If a real print still leaves a page mostly blank, or a row spills onto
   an unwanted extra page, these are the numbers to retune. */
function pf_paginate_items(array $items, int $single, int $first, int $middle, int $last): array {
    $n = count($items);
    if ($n <= $single) return [$items];
    $pages = [];
    $remaining = $items;
    $take = min(count($remaining), $first);
    if ($take >= count($remaining)) $take = max(1, count($remaining) - 1);
    $pages[] = array_splice($remaining, 0, $take);
    while (count($remaining) > $last) {
        $take = min(count($remaining), $middle);
        if ($take >= count($remaining)) $take = max(1, count($remaining) - 1);
        $pages[] = array_splice($remaining, 0, $take);
    }
    if ($remaining) $pages[] = $remaining;
    return $pages;
}

$pages = pf_paginate_items($items, 18, 26, 32, 26);
$totalPages = count($pages);
$pageSums = array_map(fn($p) => array_sum(array_map(fn($r) => (float)$r['amount'], $p)), $pages);

costing_print_head('Proforma Invoice');

$sr = 0;
foreach ($pages as $pi => $pageItems):
    $isFirst = $pi === 0;
    $isLast = $pi === $totalPages - 1;
    ?>
    <div class="ppage">
    <?php if ($isFirst): ?>
    <div class="row" style="align-items:flex-start">
      <div style="flex:1.3">
        <div><div class="lbl">PI No.</div><div class="val"><?= e($pf['pi_no']) ?></div></div>
        <div style="margin-top:8px"><div class="lbl">Date</div><div class="val"><?= e($pf['pi_date'] ? date('d M Y',strtotime($pf['pi_date'])) : '—') ?></div></div>
        <div style="margin-top:8px"><div class="lbl">Currency</div><div class="val"><?= e($cur) ?></div></div>
        <div style="margin-top:8px"><div class="lbl">Validity</div><div class="val"><?= e($pf['validity'] ?: '—') ?></div></div>
      </div>
      <div style="flex:1">
        <div class="lbl">Beneficiary Bank Details</div>
        <?php if (!$bankHasStructured && $bankLegacyText !== ''): ?>
        <div class="val" style="white-space:pre-line"><?= e($bankLegacyText) ?></div>
        <?php else: ?>
        <div class="bgrid">
          <div><div class="lbl">Bank</div><div class="val"><?= e($bFields['name']) ?></div></div>
          <div><div class="lbl">Branch</div><div class="val"><?= e($bFields['branch']) ?></div></div>
          <div><div class="lbl">Account Title</div><div class="val"><?= e($bFields['title']) ?></div></div>
          <div><div class="lbl">SWIFT</div><div class="val"><?= e($bFields['swift']) ?></div></div>
          <div style="grid-column:span 2"><div class="lbl">Account / IBAN</div><div class="val"><?= e($bFields['iban']) ?></div></div>
        </div>
        <?php endif; ?>
      <?php if(trim((string)$pf['payment_terms'])!==''): ?><div style="margin-top:12px"><div class="lbl">Payment Terms</div><div class="val"><?= e($pf['payment_terms']) ?></div></div><?php endif; ?></div>
    </div>
    <div class="row" style="margin-top:2px;align-items:flex-start">
      <div style="flex:1.3">
        <div class="sect" style="margin-top:0">Bill To</div>
        <div class="val" style="white-space:pre-line"><b><?= e($pf['customer_name'] ?: '—') ?></b><?= $pf['customer_address']?"\n".e($pf['customer_address']):'' ?></div>
      </div>
      <div style="flex:1"><div class="lbl">Delivery Date</div><div class="val"><?= e($pf['delivery_date'] ?: '—') ?></div></div>
    </div>
    <?php else: ?>
    <div class="head cont">
      <div class="brand sm">ZAS TEXTILE</div>
      <div class="docttl"><h1><?= e($pf['pi_no']) ?></h1><div class="cont-note">continued — page <?= (int)$pi+1 ?> of <?= (int)$totalPages ?></div></div>
    </div>
    <?php endif; ?>

    <div class="sect">Products<?= $totalPages>1 ? ' — page '.((int)$pi+1).' of '.(int)$totalPages : '' ?></div>
    <table>
      <thead><tr><th>#</th><th>Product</th><th>Description</th><th>Size</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit Price</th><th class="num">Amount</th></tr></thead>
      <tbody>
        <?php if (!$isFirst): $before = array_sum(array_slice($pageSums, 0, $pi)); ?>
        <tr class="fwd"><td colspan="7">Brought Forward →</td><td class="num"><?= e($sym.number_format($before,2)) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($pageItems as $r): $sr++; ?>
        <tr><td><?= $sr ?></td><td><?= e($r['product_name']) ?></td><td><?= e($r['description']) ?></td><td><?= e($r['size']) ?></td><td class="num"><?= e(number_format((float)$r['qty'],2)) ?></td><td><?= e($r['unit']) ?></td><td class="num"><?= e($sym.number_format((float)$r['unit_price'],2)) ?></td><td class="num"><?= e($sym.number_format((float)$r['amount'],2)) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$isLast): $after = array_sum(array_slice($pageSums, 0, $pi+1)); ?>
        <tr class="fwd"><td colspan="7">Carried Forward →</td><td class="num"><?= e($sym.number_format($after,2)) ?></td></tr>
        <?php else: ?>
        <tr class="tot"><td colspan="7">Total (<?= e($cur) ?>)</td><td class="num"><?= e($sym.number_format($total,2)) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php if ($isLast): ?>
    <div class="row" style="margin-top:14px">
      <div><div class="lbl">Delivery Terms</div><div class="val"><?= e($pf['delivery_terms'] ?: '—') ?></div></div>
      <div><div class="lbl">Shipment Terms</div><div class="val"><?= e($pf['shipment_terms'] ?: '—') ?></div></div>
    </div>
    <div class="row"><div><div class="lbl">Packing</div><div class="val"><?= e($pf['packing_details'] ?: '—') ?></div></div></div>
    <?php if(trim((string)$pf['remarks'])!==''): ?><div class="remarks"><b>Terms &amp; Conditions:</b> <?= e($pf['remarks']) ?></div><?php endif; ?>
    <?php
    // Drop a signature image at assets/signatures/afnan.png (any normal PNG/JPG,
    // background doesn't matter — the page it prints on is white anyway) and it
    // shows here automatically on every proforma, no code change needed to swap
    // or update it later. Falls back to a blank line above the caption if the
    // file isn't there yet.
    $sigFile = __DIR__ . '/assets/signatures/afnan.png';
    $sigUrl = 'assets/signatures/afnan.png?v=' . (is_file($sigFile) ? filemtime($sigFile) : 0);
    ?>
    <div class="sign" style="<?= is_file($sigFile) ? 'margin-top:52px' : '' ?>">
      <div>Prepared by</div>
      <div style="position:relative">
        <?php if (is_file($sigFile)): ?><img src="<?= e($sigUrl) ?>" class="sigimg" alt="Signature"><?php endif; ?>
        Authorised Signature
      </div>
    </div>
    <?php if (!empty($pf['show_pfooter'])): ?>
    <div class="pfooter">
      <p><b>Order Acceptance:</b> This PI does not need Buyer's signature. Any advance payment, L/C opening, or order confirmation/instruction by Email or WhatsApp means the Buyer has accepted this PI, including price, product details and terms.</p>
      <ol>
        <li><b>Production &amp; Delivery:</b> Production time starts only after final approval of sample, labels, packing, artwork and all other required details. If Buyer makes any change or gives new instructions, delivery time will be counted again from the final approval date. Any earlier waiting or approval time will not be counted. If Buyer is aware of the loading/shipment date through Email or WhatsApp and does not object before loading, the shipment schedule will be treated as accepted.</li>
        <li><b>Quality Claim:</b> Any quality claim must be supported by 100% inspection from an independent third-party company acceptable to the Seller. A claim will only be considered if major defects are above 5% or minor defects are above 10% of the inspected goods.</li>
        <li><b>Claim Settlement:</b> Any claim will apply only to the quantity confirmed defective in the inspection report. Any discount, credit or settlement will be decided with the Seller and must have Seller's written approval. Buyer cannot make any deduction, chargeback or reject goods without Seller's written agreement.</li>
      </ol>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    </div><!-- /.ppage -->
<?php endforeach; ?>
<?php costing_print_foot(); ?>
