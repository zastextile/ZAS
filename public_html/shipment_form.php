<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/parties.php';
require_login();
if (is_staff() || is_production_staff()) { http_response_code(403); exit('Staff cannot create commercial invoices.'); }
exp_ensure_schema();
pty_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!can_see_rates()) {
        $_SESSION['error'] = 'Your user cannot create commercial invoice because rate visibility is disabled. Ask Admin to enable rate access or use Admin login.';
        redirect('shipment_form.php');
    }

    $optEnabled = isset($_POST['optional_column_enabled']) ? 1 : 0;
    $optTitle = $optEnabled ? trim($_POST['optional_column_title'] ?? '') : null;

    /* THE INVOICE NUMBER IS STILL WHATEVER YOU TYPE.
     *
     * When your own numbering is switched on, the box arrives pre-filled with
     * the next number in your series. That suggestion did NOT use the counter
     * up — opening a form and changing your mind must not leave a hole in the
     * sequence that looks like a lost invoice.
     *
     * So the counter is only spent here, on save, and only if you left the
     * suggestion exactly as it was. Type your own number over it and the
     * counter is not touched at all. The second call is what actually claims
     * the number, so if someone else saved first in the meantime you get the
     * one after theirs rather than a duplicate. */
    $invoiceNo  = trim($_POST['invoice_no'] ?? '');
    $suggested  = trim($_POST['invoice_no_suggested'] ?? '');
    if ($suggested !== '' && $invoiceNo === $suggested) {
        $claimed = exp_numbering_next('INV');
        if ($claimed !== null && $claimed !== '') $invoiceNo = $claimed;
    }

    /* THE CURRENCY HAS TO BE CHOSEN. The list used to open on USD, so an
       invoice priced in rupees was saved as dollars unless somebody noticed,
       and the dashboard then multiplied every rupee by the dollar rate — a
       16 million sale shown as 4.4 billion. Nothing is assumed now. */
    $currency = strtoupper(trim((string)($_POST['currency'] ?? '')));
    if (!in_array($currency, ['USD', 'EUR', 'GBP', 'PKR'], true)) {
        $_SESSION['error'] = 'Choose the currency of this invoice (USD, EUR, GBP or PKR). The prices you type on it are in that currency.';
        redirect('shipment_form.php');
    }

    $stmt = db()->prepare("INSERT INTO shipments
        (invoice_no, invoice_date, buyer_name, buyer_address, buyer_country, destination_port, po_no, bl_container_no, currency, payment_terms, optional_column_enabled, optional_column_title, created_by, updated_by, created_at, incoterm, party_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)");

    $stmt->execute([
        $invoiceNo,
        $_POST['invoice_date'] ?: null,
        trim($_POST['buyer_name'] ?? ''),
        trim($_POST['buyer_address'] ?? ''),
        trim($_POST['buyer_country'] ?? ''),
        trim($_POST['destination_port'] ?? ''),
        trim($_POST['po_no'] ?? ''),
        trim($_POST['bl_container_no'] ?? ''),
        $currency,
        trim($_POST['payment_terms'] ?? ''),
        $optEnabled,
        $optTitle,
        current_user()['id'],
        current_user()['id'],
        trim($_POST['incoterm'] ?? '') ?: null,
        pty_valid_id($_POST['party_id'] ?? 0),   /* the customer it was picked from, if any */
    ]);

    $id = (int)db()->lastInsertId();

    db()->prepare("INSERT IGNORE INTO shipment_assignments (shipment_id,user_id,assigned_by) VALUES (?,?,?)")
       ->execute([$id, current_user()['id'], current_user()['id']]);

    audit_log($id, 'Shipment', 'created', '', 'Shipment created', 'Initial creation');
    $_SESSION['flash'] = 'Shipment draft created. Now add commercial invoice items and packing-list items, then save.';
    redirect('shipment_view.php?id=' . $id);
}

page_header('New Shipment');
flash();
$suggest = exp_numbering_peek('INV');
$customers = pty_customers();
$canAddParty = false;
if (is_file(__DIR__ . '/includes/inventory.php')) { require_once __DIR__ . '/includes/inventory.php'; $canAddParty = function_exists('inv_perm') && inv_perm('master'); }
?>
<style>
/* compact object-page form, same tokens as People Finance and Parties */
.nsf{--ink:#1d2d3e;--mut:#556b82;--line:#d9dee4;--pri:#0a6ed1;font-size:13px;color:var(--ink);max-width:1180px}
.nsf *{box-sizing:border-box}
.nsf .hd{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:10px}
.nsf h1{font-size:20px;font-weight:600;margin:0}.nsf .sub{color:var(--mut);font-size:12.5px;margin-top:2px}
.nsf .steps{display:flex;gap:6px;flex-wrap:wrap;font-size:12px;color:var(--mut)}
.nsf .steps span{padding:3px 9px;border:1px solid var(--line);border-radius:12px;background:#fff}.nsf .steps span.on{border-color:var(--pri);color:var(--pri);font-weight:600}
.nsf .card{background:#fff;border:1px solid var(--line);border-radius:6px;margin-bottom:10px}
.nsf .ch{padding:9px 14px;border-bottom:1px solid #eef1f4;font-weight:600;font-size:13.5px;display:flex;justify-content:space-between;align-items:center}
.nsf .ch small{color:var(--mut);font-weight:400;font-size:12px}
.nsf .cb{padding:10px 14px 12px}
.nsf .g{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px 12px}
.nsf .s2{grid-column:span 2}.nsf .s4{grid-column:1/-1}
.nsf .fld label{display:block;font-size:12px;color:var(--mut);margin-bottom:3px}
.nsf .fld label i{color:#b42318;font-style:normal}
.nsf .fld input,.nsf .fld select,.nsf .fld textarea{width:100%;height:30px;border:1px solid var(--line);border-radius:4px;padding:0 8px;font:inherit;font-size:13px;color:var(--ink);background:#fff}
.nsf .fld textarea{height:auto;min-height:56px;padding:6px 8px;resize:vertical}
.nsf .fld input:focus,.nsf .fld select:focus,.nsf .fld textarea:focus{outline:none;border-color:var(--pri);box-shadow:0 0 0 2px rgba(10,110,209,.15)}
.nsf .hint{color:var(--mut);font-size:11.5px;margin-top:3px}
.nsf .ft{display:flex;gap:8px;align-items:center;padding:10px 14px;background:#f7f9fb;border-top:1px solid #eef1f4;border-radius:0 0 6px 6px}
.nsf .btn{display:inline-flex;align-items:center;height:30px;padding:0 14px;border-radius:4px;border:1px solid var(--line);background:#fff;color:var(--pri);font:600 12.5px/1 inherit;cursor:pointer;text-decoration:none}
.nsf .btn.pri{background:var(--pri);border-color:var(--pri);color:#fff}.nsf .btn[disabled]{opacity:.5;cursor:not-allowed}
.nsf .alert{padding:9px 14px;border-radius:4px;border:1px solid #f3c6c2;background:#fdf0ef;color:#b42318;margin-bottom:10px}
@media(max-width:860px){.nsf .g{grid-template-columns:1fr 1fr}.nsf .s2{grid-column:1/-1}}
</style>
<div class="nsf">
  <div class="hd">
    <div><h1>New Shipment</h1><div class="sub">Create the draft here; invoice lines and packing come on the next screen.</div></div>
    <div class="steps"><span class="on">1 Header</span><span>2 Invoice &amp; packing lines</span><span>3 Save &amp; attach</span><span>4 Admin approves &amp; locks</span></div>
  </div>
  <?php if (!can_see_rates()): ?>
    <div class="alert">Your user cannot create commercial invoices because rate visibility is disabled. Ask Admin to enable <b>Can see rates</b> for this colleague.</div>
  <?php endif; ?>
  <form method="post" class="card" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="party_id" value="">
    <div class="ch">Buyer <small><?= count($customers) ?> customer<?= count($customers) === 1 ? '' : 's' ?> in Parties — type 2 letters and pick; the rest fills in</small></div>
    <div class="cb"><div class="g">
      <div class="fld s2"><label>Buyer name <i>*</i></label><input name="buyer_name" required placeholder="Type to pick a customer"></div>
      <div class="fld"><label>Buyer country</label><input name="buyer_country" placeholder="e.g. Germany"></div>
      <div class="fld"><label>Destination port</label><input name="destination_port" placeholder="e.g. Hamburg"></div>
      <div class="fld s2"><label>Buyer address</label><textarea name="buyer_address" rows="3"></textarea></div>
      <div class="fld"><label>Payment terms</label><input name="payment_terms" placeholder="DP / TT / LC"></div>
      <div class="fld"><label>Incoterm</label><input name="incoterm" placeholder="e.g. FOB Karachi"></div>
    </div></div>
    <div class="ch" style="border-top:1px solid #eef1f4">Invoice</div>
    <div class="cb"><div class="g">
      <div class="fld"><label>Invoice no. <i>*</i></label><input name="invoice_no" required placeholder="e.g. ZAS/5191" value="<?= e((string)$suggest) ?>">
        <?php if ($suggest !== null): ?><input type="hidden" name="invoice_no_suggested" value="<?= e($suggest) ?>"><div class="hint">Next in your series — type over it for another.</div><?php endif; ?></div>
      <div class="fld"><label>Invoice date</label><input type="date" name="invoice_date" value="<?= e(date('Y-m-d')) ?>"></div>
      <div class="fld"><label>Currency <i>*</i> — prices are in</label><select name="currency" required><option value="">— choose —</option><option>USD</option><option>EUR</option><option>GBP</option><option>PKR</option></select></div>
      <div class="fld"><label>PO no.</label><input name="po_no"></div>
      <div class="fld"><label>BL / container no.</label><input name="bl_container_no"></div>
      <div class="fld" style="display:flex;align-items:flex-end;gap:7px;padding-bottom:6px"><label style="display:flex;gap:7px;align-items:center;margin:0;color:var(--ink)"><input type="checkbox" name="optional_column_enabled" style="width:15px;height:15px"> Extra column on lines</label></div>
      <div class="fld s2" id="opt-title-field" style="display:none"><label>Extra column title</label><input name="optional_column_title" placeholder="e.g. Style #, Design no."></div>
    </div></div>
    <div class="ft">
      <button class="btn pri" <?= can_see_rates() ? '' : 'disabled' ?>>Create draft</button>
      <a class="btn" href="shipments.php">Cancel</a>
    </div>
  </form>
</div>
<?= pty_picker(['name' => 'input[name="buyer_name"]', 'party_id' => 'input[name="party_id"]', 'address' => 'textarea[name="buyer_address"]', 'country' => 'input[name="buyer_country"]',
                'currency' => 'select[name="currency"]', 'payment_terms' => 'input[name="payment_terms"]', 'incoterm' => 'input[name="incoterm"]', 'destination_port' => 'input[name="destination_port"]'],
               $customers, $canAddParty) ?>
<script>
document.querySelector('input[name="optional_column_enabled"]').addEventListener('change', function() {
  document.getElementById('opt-title-field').style.display = this.checked ? 'block' : 'none';
});
</script>
<?php page_footer(); ?>
