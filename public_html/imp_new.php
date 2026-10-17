<?php
/*
  NEW IMPORT — four fields to start; everything else is filled on the
  import's own tabs as it becomes known, the way an export shipment grows.

  "Opening import" (afnan's old data): an import already under way before
  go-live. Only the open part is needed. "Already in stock" means the goods
  are in Store & Inventory already, so Phase 2's Gate In will never add them
  again.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';
require_login();
imp_ensure_schema();
imp_require('imp', 'c');

$suppliers = imp_suppliers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $pid  = (int)($_POST['supplier_party_id'] ?? 0);
        $name = trim((string)($_POST['supplier_new'] ?? ''));
        if ($pid > 0 && isset($suppliers[$pid])) {
            $name = $suppliers[$pid];
        } elseif ($name !== '') {
            /* a new supplier goes into the one Parties list, not a second one */
            if (!function_exists('inv_party_create')) throw new Exception('The Parties list is not available.');
            $r = inv_party_create($name, 'supplier');
            if (empty($r['ok'])) throw new Exception($r['error'] ?: 'The supplier could not be added.');
            $pid = (int)$r['id']; $name = (string)$r['name'];
        } else {
            throw new Exception('Pick the supplier, or type a new one.');
        }
        $cur = (string)($_POST['currency'] ?? 'USD');
        if (!in_array($cur, IMP_CURRENCIES, true)) throw new Exception('Pick the currency.');
        $type = ($_POST['import_type'] ?? '') === 'efs' ? 'efs' : 'ordinary';
        $opening = !empty($_POST['is_opening']) ? 1 : 0;
        $inStock = $opening && !empty($_POST['already_in_stock']) ? 1 : 0;

        $ref = imp_next_ref();
        db()->prepare("INSERT INTO imp_imports (ref, employee, supplier_party_id, supplier_name, contract_no, currency, import_type, is_opening, already_in_stock, created_by, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$ref, (string)(current_user()['name'] ?? ''), $pid ?: null, $name, trim((string)($_POST['contract_no'] ?? '')) ?: null,
                       $cur, $type, $opening, $inStock, (int)(current_user()['id'] ?? 0)]);
        $id = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO imp_invoices (import_id, sort_order) VALUES (?, 1)")->execute([$id]);
        imp_log($id, 'Import created', $ref . ' · ' . $name . ' · ' . strtoupper($type) . ' · ' . $cur
                . ($opening ? ' · opening import' . ($inStock ? ', already in stock' : '') : ''));
        $_SESSION['flash'] = $ref . ' created. Fill in the contract and the invoice lines as they are known.';
        redirect('imp_view.php?id=' . $id);
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
        redirect('imp_new.php' . (!empty($_POST['is_opening']) ? '?opening=1' : ''));
    }
}

$opening = !empty($_GET['opening']);
page_header('New Import');
flash();
echo imp_page_css();
?>
<h1 style="font-size:20px;margin:0 0 12px">New Import</h1>
<form class="xcard" method="post" autocomplete="off">
  <?= csrf_field() ?>
  <div class="xgrid">
    <label class="xlabel">Supplier
      <select class="xin" name="supplier_party_id" id="supSel">
        <option value="">— pick —</option>
        <?php foreach ($suppliers as $sid => $sname): ?><option value="<?= (int)$sid ?>"><?= e($sname) ?></option><?php endforeach; ?>
        <option value="-1">+ A new supplier…</option>
      </select>
    </label>
    <label class="xlabel" id="supNewWrap" style="display:none">New supplier name<input class="xin" name="supplier_new" id="supNew" placeholder="As on their invoice"></label>
    <?= imp_input('Contract / PO no.', 'contract_no', '', 'text', ' placeholder="e.g. HY-ZAS-2610"') ?>
    <?= imp_select('Currency', 'currency', IMP_CURRENCIES, 'USD') ?>
    <?= imp_select('Import type', 'import_type', ['efs' => 'EFS', 'ordinary' => 'Ordinary (duty paid)'], 'efs', '', true) ?>
  </div>
  <label class="tog" style="margin-top:8px"><input type="checkbox" name="is_opening" id="opTog" value="1"<?= $opening ? ' checked' : '' ?>> Opening import — already under way before go-live</label>
  <div class="reveal" id="opWrap" style="<?= $opening ? '' : 'display:none' ?>">
    <label class="tog"><input type="checkbox" name="already_in_stock" value="1"> The goods are already in Store &amp; Inventory (Gate In will not add them again)</label>
    <div class="imp-small imp-muted">Only the open part is needed: the supplier, the invoice, what is still payable, and the dates you have. Payments made before go-live go in as one "Paid before go-live" line on PAYMENTS.</div>
  </div>
  <div class="xnote" style="margin-top:12px">Only these to start. The contract, lines, packing, shipping and payments are filled on the import's own tabs as they are known.</div>
  <div style="margin-top:12px;display:flex;gap:9px"><button class="xbtn">Create import</button><a class="xbtn sec" href="imp_register.php">Cancel</a></div>
</form>
<script>
(function () {
  var sel = document.getElementById('supSel'), wrap = document.getElementById('supNewWrap');
  sel.addEventListener('change', function () { wrap.style.display = sel.value === '-1' ? '' : 'none'; if (sel.value === '-1') document.getElementById('supNew').focus(); });
  var op = document.getElementById('opTog'), ow = document.getElementById('opWrap');
  op.addEventListener('change', function () { ow.style.display = op.checked ? '' : 'none'; });
})();
</script>
<?php page_footer(); ?>
