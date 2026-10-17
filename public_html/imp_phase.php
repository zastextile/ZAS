<?php
/*
  IMPORT — CUSTOMS and GATE IN tabs, Phase 2. Shown now so staff see where
  they will be; they change nothing.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('imp');
$tab = ($_GET['tab'] ?? '') === 'gatein' ? 'gatein' : 'customs';

page_header(($tab === 'customs' ? 'Customs' : 'Gate In') . ' — ' . $imp['ref']);
flash();
echo imp_page_css();
imp_tab_strip($imp, $tab);
?>
<div class="xcard"><div style="padding:22px;text-align:center;color:#5a6b82;font-size:13px;line-height:1.75">
<?php if ($tab === 'customs'): ?>
  <b>CUSTOMS — Phase 2</b><br>
  GD no./date, station and agent, and GD lines copied from the invoice lines: declared and assessed quantity, kg and value, valuation per kg, exchange rate, and each tax head
  (CD, ACD, RD, ST, AST, FED, WHT, other) <b>as assessed on the GD / challan</b>. An EFS exemption is per line with its reference — never every tax set to zero by itself.
  FTA and NOC records. Supplier value, declared value and assessed value stay separate.<br><br>
  For now the GD number and date go on <a href="imp_view.php?id=<?= (int)$imp['id'] ?>">OVERVIEW</a>, and the customs dates on <a href="imp_logistics.php?id=<?= (int)$imp['id'] ?>">LOGISTICS</a>.
<?php else: ?>
  <b>GATE IN — Phase 2</b><br>
  One receipt process: <b>Receive into Stock</b> opens your existing <b>Gate Inward</b>, already filled from this import. Staff type only what arrived: vehicle, container, packages,
  weighbridge gross/tare/net, damaged / rejected; quarantine goods go to a Quarantine location. Posting is the normal Gate Inward post — once only, no approval step —
  with several receipts per import and the balance still to come.<br><br>
  <?= (int)$imp['already_in_stock'] === 1 ? '<b>This is an opening import marked "already in stock": Gate In will not add it again.</b>' : 'Nothing on this import is in stock yet: imports are shown as ordered or in transit until Gate In.' ?>
<?php endif; ?>
</div></div>
<?php page_footer(); ?>
