<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (is_staff() || is_production_staff()) { http_response_code(403); exit('Staff cannot create commercial invoices.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!can_see_rates()) {
        $_SESSION['error'] = 'Your user cannot create commercial invoice because rate visibility is disabled. Ask Admin to enable rate access or use Admin login.';
        redirect('shipment_form.php');
    }

    $optEnabled = isset($_POST['optional_column_enabled']) ? 1 : 0;
    $optTitle = $optEnabled ? trim($_POST['optional_column_title'] ?? '') : null;

    $stmt = db()->prepare("INSERT INTO shipments
        (invoice_no, invoice_date, buyer_name, buyer_address, buyer_country, destination_port, po_no, bl_container_no, currency, payment_terms, optional_column_enabled, optional_column_title, created_by, updated_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");

    $stmt->execute([
        trim($_POST['invoice_no'] ?? ''),
        $_POST['invoice_date'] ?: null,
        trim($_POST['buyer_name'] ?? ''),
        trim($_POST['buyer_address'] ?? ''),
        trim($_POST['buyer_country'] ?? ''),
        trim($_POST['destination_port'] ?? ''),
        trim($_POST['po_no'] ?? ''),
        trim($_POST['bl_container_no'] ?? ''),
        trim($_POST['currency'] ?? 'USD'),
        trim($_POST['payment_terms'] ?? ''),
        $optEnabled,
        $optTitle,
        current_user()['id'],
        current_user()['id']
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
?>
<style>
.zcard{padding:15px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px);margin-bottom:11px}
.zcard h2{font-size:15px;margin:0 0 16px}
.zgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
.zlabel{font-size:12px;color:#5a6b82;display:block}
.zin{display:block;width:100%;margin-top:6px;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e3;background:#ffffff;color:#152033;font-size:13.5px;outline:none;font-family:inherit}
.zin:focus{border-color:#0ea8c9}
.zspan2{grid-column:span 2}
.zbtn{padding:12px 20px;border:none;border-radius:11px;cursor:pointer;font-weight:700;font-size:13.5px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);text-decoration:none;display:inline-block}
.zbtn.sec{background:#f6f8fc;color:#152033;border:1px solid #cbd5e3}
.zbtn[disabled]{opacity:.5;cursor:not-allowed}
.znote{padding:14px 16px;border-radius:14px;background:#f6f8fc;border:1px solid #e3e9f2;font-size:13px;color:#33415c;line-height:1.7}
.zalert{padding:14px 18px;border-radius:14px;margin-bottom:18px;font-size:13px;background:rgba(224,67,93,.1);border:1px solid rgba(224,67,93,.28);color:#b8283f}
</style>

<div class="topbar"><div><h1>New Shipment</h1><p class="lead">Create the shipment draft first — add invoice item lines and packing lines on the next screen.</p></div></div>

<?php if (!can_see_rates()): ?>
<div class="zalert">Your user cannot create commercial invoices because rate visibility is disabled. Ask Admin to enable <b>Can see rates</b> for this colleague.</div>
<?php endif; ?>

<div class="zcard">
  <div class="znote"><b>Manual entry flow:</b> &nbsp;1. Fill header &amp; click <b>Create Draft</b>. &nbsp;2. Add invoice + packing lines. &nbsp;3. Save &amp; attach original file. &nbsp;4. Admin approves &amp; locks.</div>
</div>

<form method="post">
<?= csrf_field() ?>
<div class="zcard">
  <h2>Shipment Header</h2>
  <div class="zgrid">
    <label class="zlabel">Invoice No.<input class="zin" name="invoice_no" required placeholder="Example: ZAS/5191"></label>
    <label class="zlabel">Invoice Date<input class="zin" type="date" name="invoice_date"></label>
    <label class="zlabel">Currency<select class="zin" name="currency"><option>USD</option><option>EUR</option><option>GBP</option><option>PKR</option></select></label>
    <label class="zlabel">Payment Terms<input class="zin" name="payment_terms" placeholder="DP / TT / LC"></label>
    <label class="zlabel zspan2">Buyer Name<input class="zin" name="buyer_name" required placeholder="Buyer company name"></label>
    <label class="zlabel zspan2">Buyer Address<textarea class="zin" name="buyer_address" style="min-height:70px;resize:vertical"></textarea></label>
    <label class="zlabel">Buyer Country<input class="zin" name="buyer_country" placeholder="Example: UAE"></label>
    <label class="zlabel">Destination Port<input class="zin" name="destination_port" placeholder="Example: JABEL ALI UAE"></label>
    <label class="zlabel">PO No.<input class="zin" name="po_no"></label>
    <label class="zlabel">BL / Container No.<input class="zin" name="bl_container_no"></label>
    <label class="zlabel zspan2" style="display:flex;align-items:center;gap:10px;margin-top:6px;color:#33415c;font-size:13px;cursor:pointer"><input type="checkbox" name="optional_column_enabled" style="width:16px;height:16px;accent-color:#0ea8c9"> Enable Optional Column</label>
    <label class="zlabel zspan2" id="opt-title-field" style="display:none">Optional Column Title<input class="zin" name="optional_column_title" placeholder="e.g. PO No., Style #, Design No."></label>
  </div>
</div>

<div class="zcard" style="display:flex;gap:10px">
  <button class="zbtn" <?= can_see_rates() ? '' : 'disabled' ?>>Create Draft</button>
  <a class="zbtn sec" href="shipments.php">Cancel</a>
</div>
</form>

<script>
document.querySelector('input[name="optional_column_enabled"]').addEventListener('change', function() {
  document.getElementById('opt-title-field').style.display = this.checked ? 'block' : 'none';
});
</script>
<?php page_footer(); ?>
