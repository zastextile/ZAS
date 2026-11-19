<?php
/* Parties — customers, suppliers and job workers, kept once.
 *
 * CUSTOMERS NOW CARRY WHAT AN EXPORT INVOICE NEEDS (owner, 19 Nov 2026):
 * country, currency, payment terms, incoterm, port, contact, notify party.
 * New Shipment, the shipment page and the proforma PICK a customer from here
 * and copy its details onto the document — see includes/parties.php. A
 * document keeps its own copy, so editing a customer never changes an
 * invoice already sent.
 *
 * FAST: one read of the list when the page opens; searching, the type tabs
 * and "show inactive" all run in the browser. The add / edit panel is filled
 * from the same data, so opening it asks the server nothing. Only Save does.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/parties.php';
inv_ensure_schema();
pty_ensure_schema();
if (!inv_can_open('invmast')) { http_response_code(403); exit('You do not have permission to view Parties.'); }
$canEdit = inv_perm('master');
$seeMoney = function_exists('can_see_rates') ? can_see_rates() : true;

$TYPES = ['customer' => 'Customer', 'supplier' => 'Supplier', 'jobworker' => 'Job worker', 'both' => 'Supplier & customer'];

/** The next free code for a type: CUS-0007, SUP-0012… */
function ip_next_code(string $type): string {
    $p = ['supplier' => 'SUP', 'customer' => 'CUS', 'jobworker' => 'JWP', 'both' => 'PTY'][$type] ?? 'PTY';
    $n = 0;
    try {
        $st = db()->prepare("SELECT code FROM inv_parties WHERE code LIKE ?"); $st->execute([$p . '-%']);
        foreach ($st->fetchAll() as $r) { $tail = (int)substr((string)$r['code'], strlen($p) + 1); if ($tail > $n) $n = $tail; }
    } catch (Throwable $e) {}
    return $p . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
}
function ip_txt($v, int $max): ?string { $v = trim((string)$v); return $v === '' ? null : mb_substr($v, 0, $max); }

/* Buyer names on shipments and proformas that are not in Parties yet, each
   with what its most recent document says about it — so making it a customer
   is one click and nothing is typed twice. */
function ip_buyers_not_in_master(): array {
    $out = [];
    try {
        $known = [];
        foreach (db()->query("SELECT LOWER(TRIM(name)) n FROM inv_parties")->fetchAll() as $r) $known[$r['n']] = true;
        $rows = db()->query("SELECT buyer_name nm, buyer_address addr, buyer_country country, currency, payment_terms, incoterm, destination_port port, invoice_date d, 's' src
                               FROM shipments WHERE TRIM(buyer_name) <> ''
                             UNION ALL
                             SELECT customer_name, customer_address, NULL, currency, payment_terms, delivery_terms, NULL, pi_date, 'p'
                               FROM proforma_invoices WHERE TRIM(customer_name) <> ''
                             ORDER BY d DESC")->fetchAll();
        foreach ($rows as $r) {
            $nm = trim((string)$r['nm']); $k = mb_strtolower($nm);
            if ($nm === '' || isset($known[$k]) || $nm === '(set buyer name)') continue;
            if (!isset($out[$k])) $out[$k] = ['name' => $nm, 'uses' => 0, 'address' => '', 'country' => '', 'currency' => '', 'payment_terms' => '', 'incoterm' => '', 'destination_port' => ''];
            $o =& $out[$k]; $o['uses']++;
            /* the newest document that says something wins, field by field */
            foreach (['address' => 'addr', 'country' => 'country', 'currency' => 'currency', 'payment_terms' => 'payment_terms', 'incoterm' => 'incoterm', 'destination_port' => 'port'] as $f => $c) {
                if ($o[$f] === '' && trim((string)($r[$c] ?? '')) !== '') $o[$f] = trim((string)$r[$c]);
            }
            unset($o);
        }
    } catch (Throwable $e) { return []; }
    $out = array_values($out);
    usort($out, fn($a, $b) => $b['uses'] <=> $a['uses']);
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    inv_require('master');
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $type = array_key_exists($_POST['party_type'] ?? '', $TYPES) ? $_POST['party_type'] : 'supplier';
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        if ($name === '') { $_SESSION['error'] = 'Party name is required.'; redirect('inv_parties.php'); }
        // Keep the existing code when editing with the box cleared — a new
        // code would orphan every document already pointing at this party.
        if ($code === '' && $id > 0) {
            try { $st = db()->prepare("SELECT code FROM inv_parties WHERE id=?"); $st->execute([$id]); $code = (string)$st->fetchColumn(); } catch (Throwable $e) {}
        }
        if ($code === '') $code = ip_next_code($type);
        $cur = strtoupper(trim((string)($_POST['currency'] ?? '')));
        $f = [
            'code' => mb_substr($code, 0, 20), 'name' => mb_substr($name, 0, 190), 'party_type' => $type,
            'city'    => ip_txt($_POST['city'] ?? '', 120),
            'phone'   => ip_txt($_POST['phone'] ?? '', 60),
            'ntn'     => ip_txt($_POST['ntn'] ?? '', 40),
            'address' => ip_txt($_POST['address'] ?? '', 2000),
            'country' => ip_txt($_POST['country'] ?? '', 80),
            'currency' => in_array($cur, PTY_CURRENCIES, true) ? $cur : null,
            'payment_terms' => ip_txt($_POST['payment_terms'] ?? '', 200),
            'incoterm' => ip_txt($_POST['incoterm'] ?? '', 120),
            'destination_port' => ip_txt($_POST['destination_port'] ?? '', 160),
            'contact_person' => ip_txt($_POST['contact_person'] ?? '', 120),
            'email' => ip_txt($_POST['email'] ?? '', 190),
            'notify_party' => ip_txt($_POST['notify_party'] ?? '', 1000),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        try {
            if ($id > 0) {
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
                $vals = array_values($f); $vals[] = $id;
                db()->prepare("UPDATE inv_parties SET $sets WHERE id=?")->execute($vals);
                inv_audit('party_edit', $id, $f, 'Party updated');
                $_SESSION['flash'] = 'Party ' . $f['code'] . ' saved.';
            } else {
                $cols = implode(',', array_keys($f)) . ',created_by';
                $ph = implode(',', array_fill(0, count($f) + 1, '?'));
                $vals = array_values($f); $vals[] = (int)(current_user()['id'] ?? 0);
                db()->prepare("INSERT INTO inv_parties ($cols) VALUES ($ph)")->execute($vals);
                inv_audit('party_add', '', $f, 'Party created');
                $_SESSION['flash'] = 'Party ' . $f['code'] . ' created.';
            }
        } catch (Throwable $e) { $_SESSION['error'] = 'Could not save — the party code may already be in use.'; }
        redirect('inv_parties.php' . ($type === 'customer' || $type === 'both' ? '#customer' : ''));
    }

    /* MAKE THE BUYERS CUSTOMERS, all at once, each filled from its newest
       document. Existing shipments and proformas are not touched. */
    if ($act === 'import_buyers') {
        $want = array_map('mb_strtolower', array_map('trim', (array)($_POST['names'] ?? [])));
        $made = 0;
        foreach (ip_buyers_not_in_master() as $b) {
            if ($want && !in_array(mb_strtolower($b['name']), $want, true)) continue;
            $cur = strtoupper($b['currency']);
            $f = ['code' => ip_next_code('customer'), 'name' => mb_substr($b['name'], 0, 190), 'party_type' => 'customer',
                  'address' => ip_txt($b['address'], 2000), 'country' => ip_txt($b['country'], 80),
                  'currency' => in_array($cur, PTY_CURRENCIES, true) ? $cur : null, 'payment_terms' => ip_txt($b['payment_terms'], 200),
                  'incoterm' => ip_txt($b['incoterm'], 120), 'destination_port' => ip_txt($b['destination_port'], 160), 'is_active' => 1];
            try {
                $cols = implode(',', array_keys($f)) . ',created_by';
                $vals = array_values($f); $vals[] = (int)(current_user()['id'] ?? 0);
                db()->prepare("INSERT INTO inv_parties ($cols) VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")")->execute($vals);
                inv_audit('party_add', '', $f, 'Customer made from shipments and proformas');
                $made++;
            } catch (Throwable $e) {}
        }
        $_SESSION['flash'] = $made . ' customer' . ($made === 1 ? '' : 's') . ' created from your shipments and proformas — check each one and fill what is missing.';
        redirect('inv_parties.php#customer');
    }

    if ($act === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare("UPDATE inv_parties SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            inv_audit('party_toggle', $id, '', 'Active status changed');
        } catch (Throwable $e) {}
        redirect('inv_parties.php');
    }
}

/* ---- everything the page needs, read once ---- */
$rows = [];
try { $rows = db()->query("SELECT * FROM inv_parties ORDER BY name LIMIT 3000")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

/* what each customer has bought: by the party picked on the document, or by
   the same name on older documents made before customers existed */
$byName = []; foreach ($rows as $r) $byName[mb_strtolower(trim((string)$r['name']))] = (int)$r['id'];
$docs = [];
try {
    foreach (db()->query("SELECT party_id, LOWER(TRIM(buyer_name)) n, currency, COUNT(*) c, SUM(total_amount) v, MAX(invoice_date) d FROM shipments GROUP BY party_id, LOWER(TRIM(buyer_name)), currency")->fetchAll() as $r) {
        $pid = (int)$r['party_id'] ?: ($byName[$r['n']] ?? 0); if (!$pid) continue;
        $d =& $docs[$pid]; $d['s'] = ($d['s'] ?? 0) + (int)$r['c']; if ((string)$r['d'] > (string)($d['last'] ?? '')) $d['last'] = (string)$r['d'];
        if ($r['currency']) $d['v'][$r['currency']] = ($d['v'][$r['currency']] ?? 0) + (float)$r['v']; unset($d);
    }
    foreach (db()->query("SELECT party_id, LOWER(TRIM(customer_name)) n, COUNT(*) c FROM proforma_invoices GROUP BY party_id, LOWER(TRIM(customer_name))")->fetchAll() as $r) {
        $pid = (int)$r['party_id'] ?: ($byName[$r['n']] ?? 0); if (!$pid) continue;
        $docs[$pid]['p'] = ($docs[$pid]['p'] ?? 0) + (int)$r['c'];
    }
} catch (Throwable $e) {}

$P = array_map(function ($r) use ($docs, $seeMoney) {
    $d = $docs[(int)$r['id']] ?? [];
    $keep = ['id', 'code', 'name', 'party_type', 'city', 'phone', 'ntn', 'address', 'country', 'currency', 'payment_terms', 'incoterm', 'destination_port', 'contact_person', 'email', 'notify_party', 'is_active'];
    $o = [];
    foreach ($keep as $k) $o[$k] = $r[$k] ?? null;
    $o['id'] = (int)$o['id']; $o['is_active'] = (int)$o['is_active'];
    $o['ship'] = (int)($d['s'] ?? 0); $o['pi'] = (int)($d['p'] ?? 0); $o['last'] = $d['last'] ?? null;
    $o['value'] = $seeMoney ? ($d['v'] ?? []) : [];
    return $o;
}, $rows);
$suggest = $canEdit ? ip_buyers_not_in_master() : [];

$pre = null;   /* opened from a document's "+ Save as customer": the panel opens filled */
if ($canEdit && isset($_GET['new'])) {
    $pre = ['party_type' => array_key_exists($_GET['type'] ?? '', $TYPES) ? $_GET['type'] : 'customer'];
    foreach (['name', 'address', 'country', 'currency', 'payment_terms', 'incoterm', 'destination_port'] as $k) $pre[$k] = mb_substr(trim((string)($_GET[$k] ?? '')), 0, 1000);
}
$openEdit = (int)($_GET['edit'] ?? 0);

page_header('Parties');
flash();
$J = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<style>
/* Fiori-style tokens, as on People Finance: quiet greys, one blue, tight rows */
.ipf{--ink:#1d2d3e;--mut:#556b82;--line:#d9dee4;--soft:#eef1f4;--pri:#0a6ed1;--bg:#f5f6f7;font-size:13px;color:var(--ink)}
.ipf *{box-sizing:border-box}
.ipf [hidden]{display:none!important}
.ipf .hd{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin:0 0 10px}
.ipf h1{font-size:20px;font-weight:600;margin:0}
.ipf .sub{color:var(--mut);font-size:12.5px;margin-top:2px}
.ipf .btn{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 12px;border-radius:4px;border:1px solid var(--line);background:#fff;color:var(--pri);font:600 12.5px/1 inherit;cursor:pointer;text-decoration:none;white-space:nowrap}
.ipf .btn:hover{background:#eaf2fc;border-color:#b9d3f1}
.ipf .btn.pri{background:var(--pri);border-color:var(--pri);color:#fff}.ipf .btn.pri:hover{background:#0859ad}
.ipf .btn.sm{height:24px;padding:0 8px;font-size:11.5px}
.ipf .btn.danger{color:#b42318}
.ipf .card{background:#fff;border:1px solid var(--line);border-radius:6px;margin-bottom:10px}
.ipf .sug{padding:10px 14px;border-left:4px solid #e9730c;display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap}
.ipf .sug .t{flex:1 1 420px}.ipf .sug b{font-weight:600}
.ipf .chip{display:inline-flex;align-items:center;gap:5px;height:24px;padding:0 9px;border-radius:12px;border:1px solid var(--line);background:#fff;font-size:12px;color:var(--ink);margin:6px 5px 0 0;cursor:pointer}
.ipf .chip:hover{border-color:var(--pri);color:var(--pri)}.ipf .chip small{color:var(--mut)}
.ipf .bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 10px;border-bottom:1px solid var(--line)}
.ipf .seg{display:inline-flex;border:1px solid var(--line);border-radius:4px;overflow:hidden}
.ipf .seg button{height:28px;padding:0 11px;border:0;border-right:1px solid var(--line);background:#fff;color:var(--mut);font:600 12px inherit;cursor:pointer}
.ipf .seg button:last-child{border-right:0}.ipf .seg button.on{background:#eaf2fc;color:var(--pri)}
.ipf .seg button b{color:var(--ink);font-weight:600;margin-left:3px}.ipf .seg button.on b{color:var(--pri)}
.ipf .srch{height:28px;border:1px solid var(--line);border-radius:4px;padding:0 9px;font:inherit;font-size:12.5px;width:260px}
.ipf .srch:focus,.ipf .fld input:focus,.ipf .fld select:focus,.ipf .fld textarea:focus{outline:none;border-color:var(--pri);box-shadow:0 0 0 2px rgba(10,110,209,.15)}
.ipf .cnt{margin-left:auto;color:var(--mut);font-size:12px}
.ipf table{width:100%;border-collapse:collapse}
.ipf th{position:sticky;top:0;background:#f7f9fb;text-align:left;font-size:11.5px;font-weight:600;color:var(--mut);padding:7px 10px;border-bottom:1px solid var(--line);white-space:nowrap;cursor:pointer;user-select:none}
.ipf td{padding:6px 10px;border-bottom:1px solid var(--soft);vertical-align:top}
.ipf tbody tr:hover{background:#f7fafd}.ipf tr.off{opacity:.55}
.ipf td.c{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;color:var(--mut);white-space:nowrap}
.ipf .nm{font-weight:600}.ipf .s2{color:var(--mut);font-size:11.5px;margin-top:1px;max-width:340px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ipf .pill{display:inline-block;font-size:11px;font-weight:600;padding:1px 7px;border-radius:3px;border:1px solid;white-space:nowrap}
.ipf .t-customer{color:#256f3a;background:#eef8f0;border-color:#c6e6cf}.ipf .t-supplier{color:#0a6ed1;background:#eef5fd;border-color:#c3dbf5}
.ipf .t-jobworker{color:#6b4fbb;background:#f4f1fc;border-color:#d8cdf3}.ipf .t-both{color:#8a5a00;background:#fff6e5;border-color:#f0d8a8}
.ipf .miss{color:#b8860b;font-size:11.5px}
.ipf td.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.ipf .acts{white-space:nowrap;text-align:right}
.ipf .none{padding:26px;text-align:center;color:var(--mut)}
/* the side panel — add / edit without leaving the list */
.ipf .scrim{position:fixed;inset:0;background:rgba(29,45,62,.28);z-index:80}
.ipf .pnl{position:fixed;top:0;right:0;bottom:0;width:min(560px,100vw);background:#fff;z-index:81;display:flex;flex-direction:column;box-shadow:-8px 0 30px rgba(16,32,56,.18)}
.ipf .pnl .ph{padding:12px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}
.ipf .pnl .ph h2{font-size:15px;font-weight:600;margin:0}.ipf .pnl .ph small{display:block;color:var(--mut);font-size:12px;font-weight:400}
.ipf .pnl .pb{padding:12px 16px;overflow:auto;flex:1}
.ipf .pnl .pf{padding:10px 16px;border-top:1px solid var(--line);display:flex;gap:8px;justify-content:flex-end;background:#f7f9fb}
.ipf .sec{font-size:11.5px;font-weight:600;color:var(--mut);text-transform:uppercase;letter-spacing:.04em;margin:12px 0 6px;padding-bottom:4px;border-bottom:1px solid var(--soft)}
.ipf .sec:first-child{margin-top:0}
.ipf .g{display:grid;grid-template-columns:1fr 1fr;gap:8px 12px}.ipf .g .w{grid-column:1/-1}
.ipf .fld label{display:block;font-size:12px;color:var(--mut);margin-bottom:3px}
.ipf .fld input,.ipf .fld select,.ipf .fld textarea{width:100%;height:30px;border:1px solid var(--line);border-radius:4px;padding:0 8px;font:inherit;font-size:13px;color:var(--ink);background:#fff}
.ipf .fld textarea{height:auto;min-height:58px;padding:6px 8px;resize:vertical}
.ipf .fld input.mono{font-family:ui-monospace,Menlo,Consolas,monospace}
.ipf .hint{color:var(--mut);font-size:11.5px;margin-top:3px}
@media(max-width:720px){.ipf .g{grid-template-columns:1fr}.ipf .srch{width:100%}.ipf .cnt{margin-left:0}}
</style>

<div class="ipf">
  <div class="hd">
    <div><h1>Parties</h1><div class="sub" id="ipSub"></div></div>
    <?php if ($canEdit): ?><button type="button" class="btn pri" id="ipNew">+ New party</button><?php endif; ?>
  </div>

  <?php if ($suggest): ?>
  <div class="card sug" id="ipSug">
    <div class="t"><b><?= count($suggest) ?> buyer name<?= count($suggest) === 1 ? '' : 's' ?> on your shipments and proformas <?= count($suggest) === 1 ? 'is' : 'are' ?> not a customer yet.</b>
      <div class="sub">Make them customers once, filled from their newest document; after that New Shipment and the proforma pick them instead of typing. Your existing documents are not changed.</div>
      <div><?php foreach ($suggest as $i => $s): ?><span class="chip" data-sug="<?= $i ?>" title="Open it filled in, to check before saving"><?= e($s['name']) ?> <small><?= (int)$s['uses'] ?>×</small></span><?php endforeach; ?></div>
    </div>
    <form method="post" onsubmit="return confirm('Create <?= count($suggest) ?> customers from your shipments and proformas?');"><?= csrf_field() ?>
      <input type="hidden" name="action" value="import_buyers">
      <button class="btn pri">Make all <?= count($suggest) ?> customers</button></form>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="bar">
      <div class="seg" id="ipSeg"></div>
      <input type="search" class="srch" id="ipQ" placeholder="Search name, code, country, city, contact…" aria-label="Search parties">
      <label style="display:flex;gap:6px;align-items:center;color:var(--mut);font-size:12px"><input type="checkbox" id="ipOff"> Show inactive</label>
      <span class="cnt" id="ipCnt"></span>
    </div>
    <div style="overflow:auto;max-height:calc(100vh - 230px)">
      <table>
        <thead><tr id="ipHead"></tr></thead>
        <tbody id="ipBody"></tbody>
      </table>
    </div>
  </div>

  <?php if ($canEdit): ?>
  <div class="scrim" id="ipScrim" hidden></div>
  <form method="post" class="pnl" id="ipPnl" hidden><?= csrf_field() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
    <div class="ph"><h2 id="ipPt">New party<small></small></h2><button type="button" class="btn sm" id="ipX">Close</button></div>
    <div class="pb">
      <div class="sec">General</div>
      <div class="g">
        <div class="fld w"><label>Name *</label><input name="name" required maxlength="190"></div>
        <div class="fld"><label>Type *</label><select name="party_type"><?php foreach ($TYPES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="fld"><label>Code</label><input name="code" class="mono" placeholder="made for you"></div>
      </div>
      <div class="sec">Address &amp; contact</div>
      <div class="g">
        <div class="fld w"><label>Address — as it prints on the invoice</label><textarea name="address" rows="3"></textarea></div>
        <div class="fld"><label>City</label><input name="city"></div>
        <div class="fld"><label>Phone</label><input name="phone"></div>
        <div class="fld"><label>Contact person</label><input name="contact_person"></div>
        <div class="fld"><label>Email</label><input name="email" type="email"></div>
        <div class="fld"><label>NTN</label><input name="ntn" class="mono"></div>
      </div>
      <div id="ipExp">
        <div class="sec">Export — filled into the invoice and proforma</div>
        <div class="g">
          <div class="fld"><label>Country</label><input name="country" list="ipCountries"></div>
          <div class="fld"><label>Currency</label><select name="currency"><option value="">—</option><?php foreach (PTY_CURRENCIES as $c): ?><option><?= $c ?></option><?php endforeach; ?></select></div>
          <div class="fld"><label>Payment terms</label><input name="payment_terms" placeholder="e.g. 30% advance, 70% against BL"></div>
          <div class="fld"><label>Incoterm</label><input name="incoterm" list="ipInco" placeholder="e.g. FOB Karachi"></div>
          <div class="fld w"><label>Destination port</label><input name="destination_port" placeholder="e.g. Hamburg, Germany"></div>
          <div class="fld w"><label>Notify party</label><textarea name="notify_party" rows="2" placeholder="Bank, agent or warehouse to be told when the goods arrive"></textarea></div>
        </div>
      </div>
      <label style="display:flex;gap:7px;align-items:center;margin-top:12px"><input type="checkbox" name="is_active" value="1" checked> Active</label>
    </div>
    <div class="pf"><button type="button" class="btn" id="ipCancel">Cancel</button><button class="btn pri" id="ipSave">Save</button></div>
  </form>
  <datalist id="ipInco"><option>FOB Karachi</option><option>FOB Port Qasim</option><option>CFR</option><option>CIF</option><option>EXW Faisalabad</option><option>DAP</option><option>DDP</option></datalist>
  <datalist id="ipCountries"></datalist>
  <?php endif; ?>
</div>

<script>
(function(){
  var P = <?= $J($P) ?>, SUG = <?= $J($suggest) ?>, PRE = <?= $J($pre) ?>, OPEN = <?= (int)$openEdit ?>, CAN = <?= $canEdit ? 'true' : 'false' ?>, MONEY = <?= $seeMoney ? 'true' : 'false' ?>;
  var TYPES = <?= $J($TYPES) ?>;
  var CSRF = <?= $J(csrf_token()) ?>;
  var $ = function(id){ return document.getElementById(id); };
  var st = { t: (location.hash || '').replace('#', ''), q: '', off: false, sort: 'name', dir: 1 };
  if (['customer', 'supplier', 'jobworker', ''].indexOf(st.t) < 0) st.t = '';
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function isT(p, t){ return !t || p.party_type === t || (p.party_type === 'both' && (t === 'customer' || t === 'supplier')); }
  function isCust(p){ return p.party_type === 'customer' || p.party_type === 'both'; }
  function nice(d){ if (!d) return ''; var x = new Date(d + 'T00:00:00'); return isNaN(x) ? d : x.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); }
  function money(v){ return Object.keys(v || {}).map(function(c){ return c + ' ' + Math.round(v[c]).toLocaleString('en-US'); }).join(' · '); }

  var COLS = [['code', 'Code'], ['name', 'Name'], ['party_type', 'Type'], ['country', 'Country / city'], ['currency', 'Currency'], ['payment_terms', 'Payment terms · incoterm'], ['contact_person', 'Contact'], ['ship', 'Documents'], ['', '']];
  function head(){
    $('ipHead').innerHTML = COLS.map(function(c){ return '<th data-k="' + c[0] + '"' + (c[0] === 'ship' ? ' style="text-align:right"' : '') + '>' + c[1] + (st.sort === c[0] && c[0] ? (st.dir > 0 ? ' ▲' : ' ▼') : '') + '</th>'; }).join('');
  }
  function seg(){
    var on = P.filter(function(p){ return st.off || p.is_active; });
    var n = function(t){ return on.filter(function(p){ return isT(p, t); }).length; };
    $('ipSeg').innerHTML = [['', 'All'], ['customer', 'Customers'], ['supplier', 'Suppliers'], ['jobworker', 'Job workers']].map(function(x){
      return '<button type="button" data-t="' + x[0] + '" class="' + (st.t === x[0] ? 'on' : '') + '">' + x[1] + '<b>' + n(x[0]) + '</b></button>'; }).join('');
    var act = P.filter(function(p){ return p.is_active; });
    $('ipSub').textContent = act.filter(isCust).length + ' customers · ' + act.filter(function(p){ return isT(p, 'supplier'); }).length + ' suppliers · ' + act.filter(function(p){ return p.party_type === 'jobworker'; }).length + ' job workers';
  }
  function rows(){
    var w = st.q.toLowerCase().split(/\s+/).filter(Boolean);
    var list = P.filter(function(p){
      if (!st.off && !p.is_active) return false;
      if (!isT(p, st.t)) return false;
      var h = [p.code, p.name, p.country, p.city, p.contact_person, p.email, p.phone, p.address, p.currency].join(' ').toLowerCase();
      return w.every(function(x){ return h.indexOf(x) >= 0; });
    });
    var k = st.sort;
    list.sort(function(a, b){ var x = a[k], y = b[k]; if (typeof x === 'number' || typeof y === 'number') return ((+x || 0) - (+y || 0)) * st.dir; return String(x || '').localeCompare(String(y || '')) * st.dir; });
    $('ipBody').innerHTML = list.length ? list.map(function(p){
      var cust = isCust(p);
      var miss = cust ? ['country', 'currency', 'address'].filter(function(f){ return !p[f]; }) : [];
      return '<tr class="' + (p.is_active ? '' : 'off') + '" data-id="' + p.id + '">'
        + '<td class="c">' + esc(p.code) + '</td>'
        + '<td><div class="nm">' + esc(p.name) + '</div>' + (p.address ? '<div class="s2" title="' + esc(p.address) + '">' + esc(p.address.split('\n').join(', ')) + '</div>' : '') + '</td>'
        + '<td><span class="pill t-' + esc(p.party_type) + '">' + esc(TYPES[p.party_type] || p.party_type) + '</span>' + (p.is_active ? '' : ' <span class="pill" style="color:#b42318;border-color:#f3c6c2;background:#fdf0ef">Inactive</span>') + '</td>'
        + '<td>' + esc(p.country || '') + (p.country && p.city ? '<div class="s2">' + esc(p.city) + '</div>' : esc(p.city || '')) + (miss.length ? '<div class="miss">missing: ' + miss.join(', ') + '</div>' : '') + '</td>'
        + '<td>' + esc(p.currency || '') + '</td>'
        + '<td><div>' + esc(p.payment_terms || '') + '</div>' + (p.incoterm ? '<div class="s2">' + esc(p.incoterm) + (p.destination_port ? ' · ' + esc(p.destination_port) : '') + '</div>' : '') + '</td>'
        + '<td>' + esc(p.contact_person || '') + '<div class="s2">' + esc([p.email, p.phone].filter(Boolean).join(' · ')) + '</div></td>'
        + '<td class="num">' + (p.ship || p.pi ? (p.ship ? p.ship + ' shipment' + (p.ship === 1 ? '' : 's') : '') + (p.ship && p.pi ? ' · ' : '') + (p.pi ? p.pi + ' PI' : '') : '<span style="color:#9aa8b6">—</span>')
        +   (MONEY && p.ship ? '<div class="s2" style="margin-left:auto">' + esc(money(p.value)) + '</div>' : '') + (p.last ? '<div class="s2">last ' + esc(nice(p.last)) + '</div>' : '') + '</td>'
        + '<td class="acts">' + (CAN ? '<button type="button" class="btn sm" data-edit="' + p.id + '">Edit</button> <button type="button" class="btn sm' + (p.is_active ? ' danger' : '') + '" data-tog="' + p.id + '">' + (p.is_active ? 'Deactivate' : 'Activate') + '</button>' : '') + '</td></tr>';
    }).join('') : '<tr><td colspan="9" class="none">' + (P.length ? 'Nothing matches.' : 'No parties yet.') + '</td></tr>';
    $('ipCnt').textContent = list.length + ' shown';
  }
  function draw(){ head(); seg(); rows(); }

  $('ipSeg').addEventListener('click', function(e){ var b = e.target.closest('button'); if (!b) return; st.t = b.getAttribute('data-t'); history.replaceState(null, '', st.t ? '#' + st.t : location.pathname); draw(); });
  $('ipQ').addEventListener('input', function(){ st.q = this.value; rows(); });
  $('ipOff').addEventListener('change', function(){ st.off = this.checked; draw(); });
  $('ipHead').addEventListener('click', function(e){ var th = e.target.closest('th'); var k = th && th.getAttribute('data-k'); if (!k) return; st.dir = st.sort === k ? -st.dir : 1; st.sort = k; draw(); });
  $('ipBody').addEventListener('click', function(e){
    var ed = e.target.closest('[data-edit]'), tg = e.target.closest('[data-tog]');
    if (ed) return openPanel(P.filter(function(p){ return p.id === +ed.getAttribute('data-edit'); })[0]);
    if (tg) {
      var p = P.filter(function(x){ return x.id === +tg.getAttribute('data-tog'); })[0];
      if (p.is_active && !confirm('Deactivate ' + p.name + '? It stays on old documents, and is no longer offered on new ones.')) return;
      var f = document.createElement('form'); f.method = 'post';
      f.innerHTML = '<input type="hidden" name="_csrf" value="' + esc(CSRF) + '"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' + p.id + '">';
      document.body.appendChild(f); f.submit();
    }
  });

  /* ---- the side panel ---- */
  var pnl = $('ipPnl');
  function showExp(){ var t = pnl.party_type.value; $('ipExp').hidden = !(t === 'customer' || t === 'both'); }
  function openPanel(p, title){
    if (!pnl) return;
    p = p || {};
    pnl.reset();
    ['id', 'name', 'code', 'address', 'city', 'phone', 'contact_person', 'email', 'ntn', 'country', 'currency', 'payment_terms', 'incoterm', 'destination_port', 'notify_party'].forEach(function(k){ if (pnl[k]) pnl[k].value = p[k] == null ? (k === 'id' ? '0' : '') : p[k]; });
    pnl.party_type.value = p.party_type || (st.t && st.t !== 'jobworker' && st.t !== 'supplier' ? 'customer' : (st.t || 'customer'));
    pnl.is_active.checked = p.is_active === undefined ? true : !!p.is_active;
    $('ipPt').innerHTML = esc(title || (p.id ? 'Edit ' + p.name : 'New party')) + '<small>' + esc(p.id ? p.code + ' · ' + (TYPES[p.party_type] || '') : 'Only the name and type are needed; the rest fills the invoice for you.') + '</small>';
    showExp(); $('ipScrim').hidden = false; pnl.hidden = false; setTimeout(function(){ pnl.name.focus(); }, 30);
  }
  function closePanel(){ if (!pnl) return; pnl.hidden = true; $('ipScrim').hidden = true; }
  if (pnl) {
    pnl.party_type.addEventListener('change', showExp);
    $('ipX').addEventListener('click', closePanel); $('ipCancel').addEventListener('click', closePanel); $('ipScrim').addEventListener('click', closePanel);
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !pnl.hidden) closePanel(); });
    var nb = $('ipNew'); if (nb) nb.addEventListener('click', function(){ openPanel(null); });
    var dl = $('ipCountries'); if (dl) { var seen = {}; P.forEach(function(p){ if (p.country && !seen[p.country.toLowerCase()]) { seen[p.country.toLowerCase()] = 1; dl.insertAdjacentHTML('beforeend', '<option>' + esc(p.country) + '</option>'); } }); }
    var sg = $('ipSug'); if (sg) sg.addEventListener('click', function(e){ var c = e.target.closest('[data-sug]'); if (!c) return; var s = SUG[+c.getAttribute('data-sug')];
      openPanel({ name: s.name, party_type: 'customer', address: s.address, country: s.country, currency: s.currency, payment_terms: s.payment_terms, incoterm: s.incoterm, destination_port: s.destination_port }, 'New customer — from your documents'); });
  }
  draw();
  if (PRE) openPanel(PRE, 'New customer');
  else if (OPEN) { var o = P.filter(function(p){ return p.id === OPEN; })[0]; if (o) openPanel(o); }
})();
</script>
<?php page_footer(); ?>
